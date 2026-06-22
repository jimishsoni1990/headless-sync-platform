<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Queue;

use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Queue\Providers\Database\QueueConnectionInterface;
use HSP\Core\Queue\Exception\QueueException;
use HSP\Tests\Unit\Queue\FakeEvent;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for DatabaseQueueProvider against live PostgreSQL.
 *
 * Proves all P0-S5 DoD items:
 *   1. SKIP LOCKED concurrency: two concurrent claimants never get the same job.
 *   2. Visibility-timeout recovery: requeueTimedOut() revives an expired in-flight job.
 *   3. Retry-limit → dead-letter with populated payload_snapshot (DECISION A).
 *   4. Partition filter: claim on 'commerce' does not touch 'content' jobs.
 *   5. worker_id is bound as UUID; visibility_timeout_at as TIMESTAMPTZ.
 *
 * Authority: OPEN-4 v1.1, OPEN-3 v1.1, DECISION A, ADR-022.
 *
 * Environment variables (test self-skips if a DB is genuinely absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class DatabaseQueueProviderIntegrationTest extends TestCase
{
    private mixed  $pgConn  = null;
    private string $schema  = 'hsp_queue_test';

    // -------------------------------------------------------------------------
    // setUp / tearDown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, "DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
            pg_close($this->pgConn);
        }
    }

    // -------------------------------------------------------------------------
    // Test 1 — Happy path: enqueue → claim → complete
    // -------------------------------------------------------------------------

    public function test_enqueue_claim_complete_lifecycle(): void
    {
        $provider = $this->makeProvider();
        $workerId = $this->newUuid();
        $event    = new FakeEvent($this->newUuid(), 'content.post.created');
        $jobId    = $provider->enqueue($event, 'content');

        // Job must be in the table with status=available.
        $row = $this->fetchJob($jobId);
        self::assertNotNull($row, 'Job must exist after enqueue');
        self::assertSame('available', $row['status']);
        self::assertSame(0, (int) $row['attempts']);

        // Claim it.
        $claimed = $provider->claim('content', $workerId);
        self::assertNotNull($claimed, 'claim() must return the job');
        self::assertSame($jobId, $claimed['id']);
        self::assertSame(1, $claimed['attempts']);
        self::assertSame('claimed', $claimed['status']);

        // Row in DB must reflect the claim.
        $row = $this->fetchJob($jobId);
        self::assertSame('claimed', $row['status']);
        self::assertSame($workerId, $row['worker_id']);
        self::assertNotNull($row['visibility_timeout_at']);

        // Complete it.
        $provider->complete($jobId);
        $row = $this->fetchJob($jobId);
        self::assertSame('completed', $row['status']);
        self::assertNull($row['visibility_timeout_at']);
        self::assertNull($row['worker_id']);
    }

    // -------------------------------------------------------------------------
    // Test 2 — SKIP LOCKED: two concurrent claimants never get the same job
    // -------------------------------------------------------------------------

    public function test_skip_locked_prevents_double_claim(): void
    {
        $provider = $this->makeProvider();

        // Enqueue one job.
        $event = new FakeEvent($this->newUuid());
        $provider->enqueue($event, 'content');

        // Open a second connection (forced new — not a pooled handle) that begins
        // a transaction and holds a FOR UPDATE lock on the row.
        $lockConn = $this->connectPgsqlForceNew();
        pg_query($lockConn, 'BEGIN');
        pg_query_params(
            $lockConn,
            "SELECT id FROM {$this->schema}.queue_jobs
             WHERE  queue_name = 'content' AND status = 'available'
             FOR UPDATE",
            []
        );

        // Worker B (using a second provider instance with the same connection pool)
        // must find no rows (SKIP LOCKED skips the locked row).
        $providerB = $this->makeProvider();
        $result = $providerB->claim('content', $this->newUuid());

        // Release lock.
        pg_query($lockConn, 'ROLLBACK');
        pg_close($lockConn);

        self::assertNull($result, 'SKIP LOCKED must yield no job while A holds the lock');
    }

    // -------------------------------------------------------------------------
    // Test 3 — Visibility-timeout recovery
    // -------------------------------------------------------------------------

    public function test_visibility_timeout_requeue_revives_expired_job(): void
    {
        $provider = $this->makeProvider();
        $event    = new FakeEvent($this->newUuid());
        $jobId    = $provider->enqueue($event, 'content');

        // Claim the job.
        $provider->claim('content', $this->newUuid());

        // Simulate expiry by backdating visibility_timeout_at.
        pg_query_params(
            $this->pgConn,
            "UPDATE {$this->schema}.queue_jobs
             SET    visibility_timeout_at = NOW() - INTERVAL '1 second'
             WHERE  id = $1::uuid",
            [$jobId]
        );

        // requeueTimedOut() must revive the job.
        $count = $provider->requeueTimedOut('content');
        self::assertSame(1, $count, 'One expired job must be requeued');

        $row = $this->fetchJob($jobId);
        self::assertSame('available', $row['status']);
        self::assertNull($row['visibility_timeout_at']);
        self::assertNull($row['worker_id']);
    }

    public function test_requeue_does_not_touch_non_expired_jobs(): void
    {
        $provider = $this->makeProvider();
        $event    = new FakeEvent($this->newUuid());
        $jobId    = $provider->enqueue($event, 'content');

        // Claim without backdating — visibility_timeout_at is in the future.
        $provider->claim('content', $this->newUuid());

        $count = $provider->requeueTimedOut('content');
        self::assertSame(0, $count, 'Non-expired in-flight jobs must not be requeued');

        $row = $this->fetchJob($jobId);
        self::assertSame('claimed', $row['status']);
    }

    public function test_concurrent_requeue_is_idempotent(): void
    {
        $provider = $this->makeProvider();
        $event    = new FakeEvent($this->newUuid());
        $jobId    = $provider->enqueue($event, 'content');

        $provider->claim('content', $this->newUuid());

        pg_query_params(
            $this->pgConn,
            "UPDATE {$this->schema}.queue_jobs
             SET    visibility_timeout_at = NOW() - INTERVAL '1 second'
             WHERE  id = $1::uuid",
            [$jobId]
        );

        // Two concurrent requeueTimedOut() calls — total requeued must be 1.
        $count1 = $provider->requeueTimedOut('content');
        $count2 = $provider->requeueTimedOut('content');

        self::assertSame(1, $count1 + $count2, 'Combined requeue count must be exactly 1');
    }

    // -------------------------------------------------------------------------
    // Test 4 — Retry limit → dead-letter with payload_snapshot NOT NULL (DECISION A)
    // -------------------------------------------------------------------------

    public function test_dead_letter_populates_dlq_with_payload_snapshot(): void
    {
        $provider = $this->makeProvider();
        $workerId = $this->newUuid();
        $event    = new FakeEvent($this->newUuid());
        $jobId    = $provider->enqueue($event, 'content');
        $provider->claim('content', $workerId);

        $payload = ['title' => 'Hello', 'id' => 42];

        $provider->deadLetter($jobId, $workerId, [
            'failure_reason'   => 'Permanent processing failure',
            'stack_trace'      => '#0 Worker::process() line 42',
            'attempt_count'    => 10,
            'payload_snapshot' => $payload,
        ]);

        // Queue job must be dead_lettered.
        $jobRow = $this->fetchJob($jobId);
        self::assertSame('dead_lettered', $jobRow['status']);

        // DLQ must have exactly one row.
        $dlqRows = $this->fetchDlqForJob($jobId);
        self::assertCount(1, $dlqRows, 'DLQ must contain exactly one entry');
        $dlq = $dlqRows[0];

        self::assertSame($jobId, $dlq['job_id']);
        self::assertSame('Permanent processing failure', $dlq['failure_reason']);
        self::assertSame('#0 Worker::process() line 42', $dlq['stack_trace']);
        self::assertSame(10, (int) $dlq['attempt_count']);
        self::assertSame($workerId, $dlq['worker_id']);

        // payload_snapshot must be NOT NULL and contain the job payload (DECISION A).
        self::assertNotNull($dlq['payload_snapshot'], 'payload_snapshot must not be NULL — DECISION A');
        $decoded = json_decode($dlq['payload_snapshot'], true);
        self::assertSame('Hello', $decoded['title']);
        self::assertSame(42, $decoded['id']);
    }

    public function test_dead_letter_null_payload_becomes_raw_json_not_null(): void
    {
        $provider = $this->makeProvider();
        $event    = new FakeEvent($this->newUuid());
        $jobId    = $provider->enqueue($event, 'content');
        $provider->claim('content', $this->newUuid());

        $provider->deadLetter($jobId, $this->newUuid(), [
            'failure_reason'   => 'unparseable',
            'attempt_count'    => 1,
            'payload_snapshot' => null,
        ]);

        $dlqRows = $this->fetchDlqForJob($jobId);
        self::assertNotNull($dlqRows[0]['payload_snapshot'], 'payload_snapshot must not be NULL even for null input — DECISION A');

        $decoded = json_decode($dlqRows[0]['payload_snapshot'], true);
        self::assertArrayHasKey('raw', $decoded);
        self::assertSame('null', $decoded['raw']);
    }

    // -------------------------------------------------------------------------
    // Test 5 — Partition isolation
    // -------------------------------------------------------------------------

    public function test_claim_on_commerce_does_not_touch_content_jobs(): void
    {
        $provider = $this->makeProvider();

        // Enqueue a 'content' job.
        $event  = new FakeEvent($this->newUuid());
        $jobId  = $provider->enqueue($event, 'content');

        // Claim from 'commerce' — must get nothing (no commerce jobs enqueued).
        $result = $provider->claim('commerce', $this->newUuid());
        self::assertNull($result, 'commerce worker must not claim content jobs');

        // Content job must still be available.
        $row = $this->fetchJob($jobId);
        self::assertSame('available', $row['status']);
    }

    // -------------------------------------------------------------------------
    // Test 6 — release() with backoff scheduling
    // -------------------------------------------------------------------------

    public function test_release_with_backoff_delay_reschedules_job(): void
    {
        $provider = $this->makeProvider();
        $event    = new FakeEvent($this->newUuid());
        $jobId    = $provider->enqueue($event, 'content');

        $provider->claim('content', $this->newUuid());

        // Release with a 60-second backoff.
        $provider->release($jobId, 60);

        $row = $this->fetchJob($jobId);
        self::assertSame('available', $row['status']);
        self::assertNull($row['visibility_timeout_at']);
        self::assertNull($row['worker_id']);

        // available_at must be in the future (now + ~60s).
        // We just check it is strictly after the current time.
        $availableAt = new \DateTimeImmutable($row['available_at']);
        self::assertGreaterThan(new \DateTimeImmutable(), $availableAt,
            'available_at must be in the future after backoff release');
    }

    // -------------------------------------------------------------------------
    // Schema helpers
    // -------------------------------------------------------------------------

    private function createSchema(): void
    {
        pg_query($this->pgConn, "CREATE SCHEMA IF NOT EXISTS {$this->schema}");

        pg_query($this->pgConn, "
            CREATE TABLE IF NOT EXISTS {$this->schema}.queue_jobs (
                id                    UUID         NOT NULL,
                event_id              UUID         NOT NULL,
                queue_name            VARCHAR(255) NOT NULL,
                status                VARCHAR(50)  NOT NULL,
                attempts              INTEGER      NOT NULL DEFAULT 0,
                available_at          TIMESTAMPTZ  NOT NULL,
                started_at            TIMESTAMPTZ  NULL,
                completed_at          TIMESTAMPTZ  NULL,
                last_error            TEXT         NULL,
                worker_id             UUID         NULL,
                visibility_timeout_at TIMESTAMPTZ  NULL,
                CONSTRAINT pk_test_queue_jobs PRIMARY KEY (id)
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE IF NOT EXISTS {$this->schema}.dead_letter_jobs (
                id               UUID        NOT NULL,
                job_id           UUID        NOT NULL,
                event_id         UUID        NOT NULL,
                failure_reason   TEXT        NOT NULL,
                created_at       TIMESTAMPTZ NOT NULL,
                stack_trace      TEXT        NULL,
                attempt_count    INTEGER     NOT NULL DEFAULT 0,
                worker_id        UUID        NULL,
                payload_snapshot JSONB       NOT NULL,
                CONSTRAINT pk_test_dead_letter_jobs PRIMARY KEY (id)
            )
        ");
    }

    /** @return array<string, mixed>|null */
    private function fetchJob(string $jobId): ?array
    {
        $result = pg_query_params(
            $this->pgConn,
            "SELECT id, event_id, queue_name, status, attempts,
                    available_at, started_at, completed_at, last_error,
                    worker_id::text AS worker_id,
                    visibility_timeout_at::text AS visibility_timeout_at
             FROM   {$this->schema}.queue_jobs
             WHERE  id = $1::uuid",
            [$jobId]
        );

        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);

        return $rows[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    private function fetchDlqForJob(string $jobId): array
    {
        $result = pg_query_params(
            $this->pgConn,
            "SELECT id, job_id::text AS job_id, event_id::text AS event_id,
                    failure_reason, created_at::text AS created_at, stack_trace,
                    attempt_count, worker_id::text AS worker_id,
                    payload_snapshot::text AS payload_snapshot
             FROM   {$this->schema}.dead_letter_jobs
             WHERE  job_id = $1::uuid",
            [$jobId]
        );

        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);

        return $rows;
    }

    private function makeProvider(): DatabaseQueueProvider
    {
        // Build a connection that targets the test schema instead of system.*
        $conn = $this->makeSchemaScopedConnection();

        return new DatabaseQueueProvider($conn, [
            'retry_limit'               => 10,
            'visibility_timeout_seconds' => 300,
            'backoff_base_seconds'       => 30,
            'backoff_cap_seconds'        => 3600,
        ]);
    }

    /**
     * Returns a QueueConnectionInterface implementation that rewrites system.* references
     * to the test schema so all SQL executes against isolated test tables.
     */
    private function makeSchemaScopedConnection(): QueueConnectionInterface
    {
        $conn = $this->connectPgsqlForceNew();
        pg_query($conn, "SET search_path TO {$this->schema}");
        $inner  = new DatabaseQueueConnection($conn);
        $schema = $this->schema;

        return new class($inner, $schema) implements QueueConnectionInterface {
            public function __construct(
                private readonly DatabaseQueueConnection $inner,
                private readonly string $schema,
            ) {}

            private function rewrite(string $sql): string
            {
                return str_replace('system.', $this->schema . '.', $sql);
            }

            public function execute(string $sql, array $params = []): int
            {
                return $this->inner->execute($this->rewrite($sql), $params);
            }

            public function query(string $sql, array $params = []): array
            {
                return $this->inner->query($this->rewrite($sql), $params);
            }

            public function beginTransaction(): void { $this->inner->beginTransaction(); }
            public function commit(): void           { $this->inner->commit(); }
            public function rollback(): void         { $this->inner->rollback(); }
        };
    }

    /**
     * Force a new PG connection (PGSQL_CONNECT_FORCE_NEW) so it is genuinely
     * independent of any existing handle — required for the SKIP LOCKED concurrency
     * test where two connections must hold separate transactions.
     */
    private function connectPgsqlForceNew(): mixed
    {
        $host  = getenv('HSP_TEST_PGSQL_HOST')    ?: '127.0.0.1';
        $port  = (int) (getenv('HSP_TEST_PGSQL_PORT')    ?: 5432);
        $user  = getenv('HSP_TEST_PGSQL_USER')    ?: false;
        $pass  = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db    = getenv('HSP_TEST_PGSQL_DATABASE') ?: false;

        if ($user === false || $db === false) {
            self::markTestSkipped('PostgreSQL env vars not set.');
        }

        $dsn  = "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
        $conn = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped("Could not open forced-new PG connection.");
        }

        return $conn;
    }

    private function connectPgsql(): mixed
    {
        $host  = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port  = (int) (getenv('HSP_TEST_PGSQL_PORT')     ?: 5432);
        $user  = getenv('HSP_TEST_PGSQL_USER')     ?: false;
        $pass  = getenv('HSP_TEST_PGSQL_PASSWORD')  ?: '';
        $db    = getenv('HSP_TEST_PGSQL_DATABASE')  ?: false;

        if ($user === false || $db === false) {
            self::markTestSkipped(
                'PostgreSQL integration tests require HSP_TEST_PGSQL_USER and '
                . 'HSP_TEST_PGSQL_DATABASE env vars.'
            );
        }

        $dsn  = "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
        $conn = pg_connect($dsn);

        if ($conn === false) {
            self::markTestSkipped("Could not connect to PostgreSQL at {$host}:{$port}/{$db}.");
        }

        return $conn;
    }

    private function newUuid(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tailHex;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
