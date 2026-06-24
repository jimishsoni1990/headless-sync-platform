<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * Live-PG integration proof for the Resolve-stage stale-event guard (DECISION J Layer 1).
 *
 * DECISION J makes the Resolve stage the PRIMARY, authoritative stale-event gate.
 * The adapter GREATEST guard is defense-in-depth only; it is NOT a substitute for
 * the Resolve gate proof.
 *
 * This test drives EventWorkerStrategy::execute() end-to-end against real PostgreSQL:
 *   - Seeds system.events with event aggregate_version = 3
 *   - Seeds system.aggregate_versions with latest_processed_version = 5 (stored > incoming)
 *   - Enqueues a job pointing to that event in system.queue_jobs
 *   - Registers a spy callable in EventRegistry
 *   - Calls EventWorkerStrategy::execute()
 *
 * Asserts all three DECISION J Layer 1 consequences simultaneously:
 *   (a) Handler spy counter = 0  — handler was NEVER invoked
 *   (b) Job status = 'completed' — job was acked (not released, not dead-lettered)
 *   (c) ZERO writes occurred     — no processed_events row, no aggregate_versions change,
 *                                  no content.pages row
 *
 * The Resolve-stage read uses the shared DatabaseConnectionInterface (DECISION E).
 * No new pg_connect is introduced; queue uses its own PGSQL_CONNECT_FORCE_NEW handle.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class ResolveStageGuardIntegrationTest extends TestCase
{
    private mixed $pgConn = null;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Item 1 core test: Resolve gate fires, ALL three consequences hold
    // =========================================================================

    /**
     * Stale event (incoming version 3 ≤ stored 5):
     *   (a) handler spy never invoked
     *   (b) job acked (status = completed)
     *   (c) zero writes: no processed_events row, no aggregate_versions change,
     *       no content.pages row
     */
    public function test_resolve_stage_acks_stale_event_with_zero_writes_and_no_handler(): void
    {
        $eventId  = $this->newUuid();
        $aggType  = 'page';
        $aggId    = '777';
        $workerId = $this->newUuid();

        // ── Seed system.events: version 3 (stale — stored will be 5)
        $this->seedEvent($eventId, 'content.page.updated', $aggType, $aggId, aggregateVersion: 3);

        // ── Seed system.aggregate_versions: stored = 5 (newer than incoming 3)
        $this->seedAggregateVersion($aggType, $aggId, storedVersion: 5);

        // ── Enqueue a job pointing to the stale event
        $jobId = $this->enqueueJob($eventId, 'content');

        // ── Spy: counter must remain 0 if Resolve fires correctly
        $handlerCallCount = 0;
        $registry = new EventRegistry();
        $registry->register('content.page.updated', function () use (&$handlerCallCount): void {
            $handlerCallCount++;
        });

        // ── Wire EventWorkerStrategy with real PG connections (DECISION E)
        $strategy = $this->makeStrategy($registry);

        $ctx = new WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        // ── Execute
        $result = $strategy->execute($ctx);

        // (a) Handler NEVER invoked
        self::assertSame(0, $handlerCallCount,
            'DECISION J Layer 1: handler must NOT be invoked for a stale event at Resolve stage');

        // (b) Job acked — status must be 'completed', not 'available' or 'claimed' or 'failed'
        $jobRow = $this->fetchJobRow($jobId);
        self::assertNotNull($jobRow, 'Job row must still exist after ack');
        self::assertSame('completed', $jobRow['status'],
            'DECISION J: stale-event job must be acked (completed), not retried or dead-lettered');

        // (c) ZERO writes — processed_events must have no row for this event
        self::assertSame(0, $this->countRows('system.processed_events'),
            'DECISION J: no processed_events row must be written for a stale event');

        // (c) ZERO writes — aggregate_versions must still be exactly 5 (unchanged)
        $storedVersion = $this->fetchAggregateVersion($aggType, $aggId);
        self::assertSame(5, $storedVersion,
            'DECISION J: aggregate_versions must not change for a stale event');

        // (c) ZERO writes — no content projection row created
        self::assertSame(0, $this->countRows('content.pages'),
            'DECISION J: no content.pages row must be written for a stale event');

        // execute() must return true (job was claimed and acked — not an empty-queue no-op)
        self::assertTrue($result, 'execute() must return true when a job was claimed (even if stale)');
    }

    /**
     * Non-stale event at the boundary (incoming version 6 > stored 5):
     * Resolve does NOT fire; handler IS invoked.
     *
     * This is a sanity check to confirm the Resolve gate only fires when version ≤ stored,
     * not on every event. Uses the same pipeline; handler writes a flag via ContentSubscriber
     * substitute (a simple closure on the registry).
     */
    public function test_resolve_stage_does_not_fire_for_non_stale_event(): void
    {
        $eventId  = $this->newUuid();
        $aggType  = 'page';
        $aggId    = '888';
        $workerId = $this->newUuid();

        // incoming version 6 > stored 5 → NOT stale
        $this->seedEvent($eventId, 'content.page.updated', $aggType, $aggId, aggregateVersion: 6);
        $this->seedAggregateVersion($aggType, $aggId, storedVersion: 5);
        $jobId = $this->enqueueJob($eventId, 'content');

        $handlerCallCount = 0;
        $registry = new EventRegistry();
        $registry->register('content.page.updated', function () use (&$handlerCallCount): void {
            $handlerCallCount++;
        });

        $strategy = $this->makeStrategy($registry);
        $ctx = new WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $strategy->execute($ctx);

        self::assertSame(1, $handlerCallCount,
            'Resolve must NOT fire for non-stale event (version > stored)');
    }

    /**
     * Equal version (incoming = stored) is treated as stale per DECISION J ("≤ stored").
     * Handler must NOT be invoked; job acked; zero writes.
     */
    public function test_resolve_stage_treats_equal_version_as_stale(): void
    {
        $eventId  = $this->newUuid();
        $aggType  = 'page';
        $aggId    = '999';
        $workerId = $this->newUuid();

        // incoming version 5 == stored 5 → stale (≤)
        $this->seedEvent($eventId, 'content.page.updated', $aggType, $aggId, aggregateVersion: 5);
        $this->seedAggregateVersion($aggType, $aggId, storedVersion: 5);
        $jobId = $this->enqueueJob($eventId, 'content');

        $handlerCallCount = 0;
        $registry = new EventRegistry();
        $registry->register('content.page.updated', function () use (&$handlerCallCount): void {
            $handlerCallCount++;
        });

        $strategy = $this->makeStrategy($registry);
        $ctx = new WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $strategy->execute($ctx);

        self::assertSame(0, $handlerCallCount,
            'Equal version must be treated as stale (DECISION J: version ≤ stored)');

        $jobRow = $this->fetchJobRow($jobId);
        self::assertSame('completed', $jobRow['status'], 'Equal-version stale job must be acked');
        self::assertSame(0, $this->countRows('system.processed_events'),
            'No processed_events row for equal-version stale event');
    }

    /**
     * First event for a new aggregate (no aggregate_versions row):
     * Resolve must NOT treat missing row as stale. Handler must be invoked.
     */
    public function test_resolve_stage_does_not_fire_when_no_aggregate_version_row_exists(): void
    {
        $eventId  = $this->newUuid();
        $aggType  = 'page';
        $aggId    = '111';
        $workerId = $this->newUuid();

        // No aggregate_versions row seeded → first event for this aggregate
        $this->seedEvent($eventId, 'content.page.created', $aggType, $aggId, aggregateVersion: 1);
        $jobId = $this->enqueueJob($eventId, 'content');

        $handlerCallCount = 0;
        $registry = new EventRegistry();
        $registry->register('content.page.created', function () use (&$handlerCallCount): void {
            $handlerCallCount++;
        });

        $strategy = $this->makeStrategy($registry);
        $ctx = new WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $strategy->execute($ctx);

        self::assertSame(1, $handlerCallCount,
            'Missing aggregate_versions row must not be treated as stale — first event must be processed');
    }

    // =========================================================================
    // Helpers — strategy factory
    // =========================================================================

    private function makeStrategy(EventRegistry $registry): EventWorkerStrategy
    {
        // Shared DatabaseConnectionInterface for Resolve-stage reads (DECISION E).
        // Plain pg_connect (no FORCE_NEW) — this is the shared delivery connection, not the queue.
        $deliveryConn = new PostgresDatabaseConnection($this->pgConn);

        // Queue uses its own FORCE_NEW connection (OPEN-4 SKIP LOCKED isolation).
        $queueConnHandle = $this->connectPgsqlForceNew();
        $queueConn  = new DatabaseQueueConnection($queueConnHandle);
        $provider   = new DatabaseQueueProvider($queueConn, [
            'retry_limit'                => 10,
            'visibility_timeout_seconds' => 300,
            'backoff_base_seconds'       => 30,
            'backoff_cap_seconds'        => 3600,
        ]);

        return new EventWorkerStrategy($provider, $registry, $deliveryConn);
    }

    // =========================================================================
    // Helpers — DB seeding
    // =========================================================================

    private function seedEvent(
        string $id,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
    ): void {
        $now = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.events
                (id, event_type, event_version, aggregate_type, aggregate_id,
                 aggregate_version, payload, checksum, source_updated_at,
                 created_at, correlation_id, causation_id)
             VALUES
                ($1::uuid, $2, 1, $3, $4,
                 $5, '{}', $6, $7::timestamptz,
                 $8::timestamptz, $9::uuid, NULL)",
            [
                $id, $eventType, $aggregateType, $aggregateId,
                $aggregateVersion, str_repeat('a', 64), $now, $now, $this->newUuid(),
            ]
        );
    }

    private function seedAggregateVersion(string $aggregateType, string $aggregateId, int $storedVersion): void
    {
        $now = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->pgConn,
            'INSERT INTO system.aggregate_versions
                (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, $3, $4::timestamptz)
             ON CONFLICT (aggregate_type, aggregate_id) DO UPDATE
                SET latest_processed_version = EXCLUDED.latest_processed_version,
                    latest_processed_at = EXCLUDED.latest_processed_at',
            [$aggregateType, $aggregateId, $storedVersion, $now]
        );
    }

    private function enqueueJob(string $eventId, string $queueName): string
    {
        $jobId = $this->newUuid();
        $now   = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.queue_jobs
                (id, event_id, queue_name, status, attempts, available_at,
                 started_at, completed_at, last_error, worker_id, visibility_timeout_at)
             VALUES
                ($1::uuid, $2::uuid, $3, 'available', 0, $4::timestamptz,
                 NULL, NULL, NULL, NULL, NULL)",
            [$jobId, $eventId, $queueName, $now]
        );
        return $jobId;
    }

    // =========================================================================
    // Helpers — DB reads
    // =========================================================================

    private function countRows(string $table): int
    {
        $result = pg_query($this->pgConn, "SELECT COUNT(*) AS cnt FROM {$table}");
        if ($result === false) {
            return 0;
        }
        $row = pg_fetch_assoc($result);
        pg_free_result($result);
        return (int) ($row['cnt'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    private function fetchJobRow(string $jobId): ?array
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT id, status, attempts, worker_id::text AS worker_id
             FROM system.queue_jobs WHERE id = $1::uuid',
            [$jobId]
        );
        if ($result === false) {
            return null;
        }
        $row = pg_fetch_assoc($result) ?: null;
        pg_free_result($result);
        return $row;
    }

    private function fetchAggregateVersion(string $aggregateType, string $aggregateId): int
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT latest_processed_version FROM system.aggregate_versions
             WHERE aggregate_type = $1 AND aggregate_id = $2',
            [$aggregateType, $aggregateId]
        );
        if ($result === false) {
            return 0;
        }
        $row = pg_fetch_assoc($result);
        pg_free_result($result);
        return (int) ($row['latest_processed_version'] ?? 0);
    }

    private function newUuid(): string
    {
        $ms      = (int) (microtime(true) * 1000);
        $bytes   = random_bytes(10);
        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));
        $hex     = $tsHex . $b67hex . $b89hex . $tailHex;
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    // =========================================================================
    // Schema setup — minimal tables needed by EventWorkerStrategy
    // =========================================================================

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS system');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.events (
                id                UUID         NOT NULL,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INTEGER      NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                payload           JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                checksum          VARCHAR(64)  NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                correlation_id    UUID         NOT NULL,
                causation_id      UUID         NULL,
                CONSTRAINT pk_rsg_test_events PRIMARY KEY (id)
            )
        ');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.queue_jobs (
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
                CONSTRAINT pk_rsg_test_queue_jobs PRIMARY KEY (id)
            )
        ');

        pg_query($this->pgConn, '
            CREATE INDEX IF NOT EXISTS idx_rsg_queue_jobs_claim
                ON system.queue_jobs (queue_name, status, available_at)
        ');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.dead_letter_jobs (
                id               UUID         NOT NULL,
                job_id           UUID         NOT NULL,
                event_id         UUID         NOT NULL,
                queue_name       VARCHAR(255) NOT NULL,
                failure_reason   TEXT         NOT NULL,
                created_at       TIMESTAMPTZ  NOT NULL,
                stack_trace      TEXT         NULL,
                attempt_count    INTEGER      NOT NULL DEFAULT 0,
                worker_id        UUID         NULL,
                payload_snapshot JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                CONSTRAINT pk_rsg_test_dead_letter_jobs PRIMARY KEY (id)
            )
        ');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_rsg_test_aggregate_versions
                    PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.processed_events (
                event_id     UUID        NOT NULL,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_rsg_test_processed_events PRIMARY KEY (event_id)
            )
        ');

        // Minimal content schema so assertions on content.pages don't error
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.pages (
                id             UUID        NOT NULL,
                source_post_id BIGINT      NOT NULL,
                CONSTRAINT pk_rsg_test_pages PRIMARY KEY (id),
                CONSTRAINT uq_rsg_test_pages_source_post_id UNIQUE (source_post_id)
            )
        ');
    }

    // =========================================================================
    // Connection helpers
    // =========================================================================

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: false;
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: false;

        if ($user === false || $db === false) {
            self::markTestSkipped('PostgreSQL env vars not set — skipping Resolve-stage guard integration tests.');
        }

        $dsn  = "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
        $conn = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping.");
        }

        return $conn;
    }

    private function connectPgsqlForceNew(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: '';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';

        $dsn  = "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
        $conn = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped('Could not open forced-new PG connection for queue.');
        }

        return $conn;
    }
}
