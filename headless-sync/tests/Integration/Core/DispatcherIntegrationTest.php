<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Core;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\DispatcherWorkerStrategy;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\WorkerExecutionContext;
use PHPUnit\Framework\TestCase;

/**
 * Dispatcher stage integration tests — P1A-S6d DoD (revised).
 *
 * Proves all DoD items (DECISION L v1.12) against live PostgreSQL:
 *
 *   1. Happy path: insert event into system.events → dispatcher tick → exactly one
 *      queue_jobs row with matching event_id, queue_name='content', status='available'.
 *
 *   2. Idempotency: run dispatcher twice over the same event → still exactly one row
 *      (ON CONFLICT DO NOTHING proven); a completed event is not re-dispatched
 *      (UNIQUE constraint blocks it permanently).
 *
 *   3. Concurrency: two dispatcher ticks holding a FOR UPDATE lock on the same event
 *      batch produce no duplicate jobs (SKIP LOCKED + UNIQUE proven).
 *
 *   4. Relay→Dispatcher→Queue link: relay an outbox row into system.events (via
 *      RelayWorkerStrategy), then dispatch → queue_jobs row appears with the correct
 *      event_id. Proves the link P0-S4 RelayEndToEndTest never covered.
 *
 *   5. Queue routing: dispatcher writes queue_name='content'; a DatabaseQueueProvider
 *      claim() call on 'content' retrieves the job — proving dispatcher and worker
 *      claim the same partition.
 *
 *   6. Connection isolation: dispatcher pg_backend_pid() != delivery pg_backend_pid().
 *      Proves the dispatcher FORCE_NEW handle is physically distinct from the DECISION K
 *      delivery handle.
 *
 * Connection topology per DECISION L / DECISION K / DECISION E:
 *   - dispatchConn  : dispatcher FORCE_NEW handle ('dispatcher.connection.pgsql')
 *                     — MUST NOT be the DatabaseConnectionInterface delivery singleton
 *   - deliveryConn  : delivery FORCE_NEW handle (for PID comparison only — test 6)
 *   - queueProvider : DatabaseQueueProvider over its own FORCE_NEW handle (queue writes)
 *   - testConn      : raw pg_connect handle for schema setup, seeding, and assertions
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE  (test 4 only)
 */
final class DispatcherIntegrationTest extends TestCase
{
    private mixed $testConn = null;

    protected function setUp(): void
    {
        $this->testConn = $this->connectPgsql();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->testConn !== null) {
            // Drop only our test-owned tables; schema may be shared with other tests.
            \pg_query($this->testConn, 'DROP TABLE IF EXISTS system.queue_jobs');
            \pg_query($this->testConn, 'DROP TABLE IF EXISTS system.events');
            \pg_query($this->testConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            \pg_close($this->testConn);
            $this->testConn = null;
        }
    }

    // =========================================================================
    // Test 1 — Happy path: event → dispatcher tick → queue_jobs row
    // =========================================================================

    public function test_dispatcher_tick_enqueues_undispatched_event(): void
    {
        $eventId = $this->uuidv7();
        $this->insertSystemEvent($eventId, 'content.post.created');

        $strategy = $this->makeStrategy();
        $didWork  = $strategy->execute($this->makeContext());

        self::assertTrue($didWork, 'execute() must return true when a batch was dispatched');

        $jobs = $this->queryQueueJobs($eventId);
        self::assertCount(1, $jobs, 'Exactly one queue_jobs row must exist');
        self::assertSame($eventId,   $jobs[0]['event_id']);
        self::assertSame('content',  $jobs[0]['queue_name']);
        self::assertSame('available',$jobs[0]['status']);
    }

    public function test_dispatcher_tick_returns_false_when_no_events(): void
    {
        $strategy = $this->makeStrategy();
        self::assertFalse($strategy->execute($this->makeContext()), 'execute() must return false when system.events is empty');
    }

    // =========================================================================
    // Test 2a — Idempotency: dispatch twice → one row (ON CONFLICT proven)
    // =========================================================================

    public function test_double_dispatch_produces_exactly_one_queue_job(): void
    {
        $eventId = $this->uuidv7();
        $this->insertSystemEvent($eventId, 'content.page.updated');

        $strategy = $this->makeStrategy();

        $strategy->execute($this->makeContext());

        // Second tick: NOT EXISTS sees the queue_jobs row → event skipped → false.
        $didWork = $strategy->execute($this->makeContext());
        self::assertFalse($didWork, 'Second tick must return false — event already dispatched');

        $jobs = $this->queryQueueJobs($eventId);
        self::assertCount(1, $jobs, 'ON CONFLICT DO NOTHING must prevent duplicate row');
    }

    // =========================================================================
    // Test 2b — Completed event is not re-dispatched
    // =========================================================================

    public function test_completed_event_is_not_re_dispatched(): void
    {
        $eventId = $this->uuidv7();
        $this->insertSystemEvent($eventId, 'content.category.deleted');

        $strategy = $this->makeStrategy();
        $strategy->execute($this->makeContext());

        // Simulate worker completing the job (row retained at status='completed').
        $this->markJobCompleted($eventId);

        // NOT EXISTS sees the retained completed row → event is still blocked.
        $didWork = $strategy->execute($this->makeContext());
        self::assertFalse($didWork, 'Completed event must not trigger re-dispatch');

        $jobs = $this->queryQueueJobs($eventId);
        self::assertCount(1, $jobs, 'Still exactly one queue_jobs row');
        self::assertSame('completed', $jobs[0]['status'], 'Row must remain completed, not duplicated');
    }

    // =========================================================================
    // Test 3 — Concurrency: SKIP LOCKED + UNIQUE prove no duplicate jobs
    // =========================================================================

    public function test_concurrent_ticks_produce_no_duplicate_jobs(): void
    {
        $eventIds = [];
        for ($i = 0; $i < 3; $i++) {
            $id         = $this->uuidv7();
            $eventIds[] = $id;
            $this->insertSystemEvent($id, 'content.post.created', (string) $i);
        }

        // Lock all 3 system.events rows on a separate connection.
        $lockConn = $this->connectPgsql();
        \pg_query($lockConn, 'BEGIN');
        \pg_query($lockConn, 'SELECT id FROM system.events FOR UPDATE');

        try {
            // Worker B tick: SKIP LOCKED finds nothing → false.
            $strategyB = $this->makeStrategy();
            $didWork   = $strategyB->execute($this->makeContext());
            self::assertFalse($didWork, 'SKIP LOCKED: second dispatcher must find no unlocked rows');
            self::assertCount(0, $this->queryAllQueueJobs(), 'No queue_jobs while lock holder active');

        } finally {
            \pg_query($lockConn, 'ROLLBACK');
            \pg_close($lockConn);
        }

        // Locks released — Worker A dispatches all 3.
        $strategyA = $this->makeStrategy();
        $strategyA->execute($this->makeContext());

        $allJobs = $this->queryAllQueueJobs();
        self::assertCount(3, $allJobs, 'All 3 events must be dispatched after lock released');

        $jobEventIds = array_column($allJobs, 'event_id');
        sort($jobEventIds);
        sort($eventIds);
        self::assertSame($eventIds, $jobEventIds, 'Dispatched event_ids must match exactly');

        // Second pass proves UNIQUE prevents any duplicate.
        $strategyA->execute($this->makeContext());
        self::assertCount(3, $this->queryAllQueueJobs(), 'No duplicate jobs after second dispatch attempt');
    }

    // =========================================================================
    // Test 4 — Relay→Dispatcher→Queue link (P0-S4 gap closed)
    // =========================================================================

    public function test_relay_to_dispatcher_to_queue_link(): void
    {
        $mysqlUser = getenv('HSP_TEST_MYSQL_USER') ?: '';
        $mysqlDb   = getenv('HSP_TEST_MYSQL_DATABASE') ?: '';
        if ($mysqlUser === '' || $mysqlDb === '') {
            $this->markTestSkipped('MySQL env vars not set — skipping relay→dispatcher link test.');
        }

        $eventId = $this->relayOneOutboxRowToSystemEvents();

        // system.events must now have the row.
        $evtRows = $this->querySystemEvents($eventId);
        self::assertCount(1, $evtRows, 'RelayWorkerStrategy must have inserted into system.events');

        // Dispatch step.
        $strategy = $this->makeStrategy();
        $didWork  = $strategy->execute($this->makeContext());

        self::assertTrue($didWork, 'Dispatcher must return true after dispatching the relayed event');

        $jobs = $this->queryQueueJobs($eventId);
        self::assertCount(1, $jobs, 'Relay→Dispatcher link: queue_jobs row must appear');
        self::assertSame($eventId,    $jobs[0]['event_id']);
        self::assertSame('content',   $jobs[0]['queue_name']);
        self::assertSame('available', $jobs[0]['status']);
    }

    // =========================================================================
    // Test 5 — Queue routing: dispatcher writes 'content'; worker claim hits same row
    // =========================================================================

    public function test_dispatcher_writes_content_queue_and_worker_can_claim_it(): void
    {
        $eventId = $this->uuidv7();
        $this->insertSystemEvent($eventId, 'content.post.created');

        // Dispatch.
        $strategy = $this->makeStrategy();
        $strategy->execute($this->makeContext());

        // A DatabaseQueueProvider claiming 'content' must find the job.
        $queueProvider = $this->makeQueueProvider();
        $workerId      = $this->uuidv7();
        $job           = $queueProvider->claim('content', $workerId);

        self::assertNotNull($job, 'Worker must be able to claim the dispatched job from content queue');
        self::assertSame($eventId, $job['event_id'], 'Claimed job must reference the dispatched event_id');
        self::assertSame('content', $job['queue_name']);
        self::assertSame('claimed', $job['status']);
    }

    // =========================================================================
    // Test 6 — Connection isolation: dispatcher PID != delivery PID
    // =========================================================================

    public function test_dispatcher_connection_is_physically_distinct_from_delivery_connection(): void
    {
        // Two separate FORCE_NEW handles — each must get a distinct backend PID,
        // proving libpq cannot pool them (FORCE_NEW is working as intended).
        $dispatchConn = $this->openForceNewConn();
        $deliveryConn = $this->openForceNewConn();

        $rows1 = $dispatchConn->query('SELECT pg_backend_pid() AS pid');
        $rows2 = $deliveryConn->query('SELECT pg_backend_pid() AS pid');

        $dispatchPid = (int) $rows1[0]['pid'];
        $deliveryPid = (int) $rows2[0]['pid'];

        self::assertGreaterThan(0, $dispatchPid);
        self::assertGreaterThan(0, $deliveryPid);
        self::assertNotSame(
            $deliveryPid,
            $dispatchPid,
            'Dispatcher connection backend PID must differ from delivery connection PID — FORCE_NEW isolation (DECISION L / DECISION K pattern)',
        );
    }

    // =========================================================================
    // Strategy / provider factory helpers
    // =========================================================================

    /**
     * Builds a DispatcherWorkerStrategy using its own FORCE_NEW connection
     * ('dispatcher.connection.pgsql' pattern — DECISION L).
     * Does NOT use DatabaseConnectionInterface (delivery singleton — DECISION K).
     */
    private function makeStrategy(): DispatcherWorkerStrategy
    {
        return new DispatcherWorkerStrategy(
            new EventDispatcher(
                $this->openForceNewConn(),
                $this->makeQueueProvider(),
            ),
        );
    }

    /**
     * Opens a FORCE_NEW PostgresDatabaseConnection — the dispatcher connection pattern.
     */
    private function openForceNewConn(): PostgresDatabaseConnection
    {
        $conn = \pg_connect($this->pgsqlDsn(), PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $this->markTestSkipped('Could not open FORCE_NEW PG connection.');
        }
        return new PostgresDatabaseConnection($conn);
    }

    /**
     * Queue provider over its own FORCE_NEW connection (queue-claim path).
     */
    private function makeQueueProvider(): DatabaseQueueProvider
    {
        $conn = \pg_connect($this->pgsqlDsn(), PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $this->markTestSkipped('Could not open queue PG connection.');
        }
        return new DatabaseQueueProvider(new DatabaseQueueConnection($conn));
    }

    private function makeContext(): WorkerExecutionContext
    {
        return new WorkerExecutionContext(
            workerId:      $this->uuidv7(),
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // =========================================================================
    // Relay helper (test 4 only)
    // =========================================================================

    private function relayOneOutboxRowToSystemEvents(): string
    {
        $host   = getenv('HSP_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port   = (int) (getenv('HSP_TEST_MYSQL_PORT') ?: 3306);
        $user   = getenv('HSP_TEST_MYSQL_USER') ?: '';
        $pass   = getenv('HSP_TEST_MYSQL_PASSWORD') ?: '';
        $db     = getenv('HSP_TEST_MYSQL_DATABASE') ?: '';
        $prefix = 'disp_test_';
        $outbox = $prefix . 'hsp_outbox';

        $mysqli = new \mysqli($host, $user, $pass, $db, $port);
        if ($mysqli->connect_errno) {
            $this->markTestSkipped("MySQL connect failed: {$mysqli->connect_error}");
        }
        $mysqli->set_charset('utf8mb4');

        $mysqli->query("DROP TABLE IF EXISTS `{$outbox}`");
        $mysqli->query(
            "CREATE TABLE `{$outbox}` (
                `id`                CHAR(36)                   NOT NULL,
                `event_type`        VARCHAR(255)               NOT NULL,
                `event_version`     INT                        NOT NULL,
                `aggregate_type`    VARCHAR(100)               NOT NULL,
                `aggregate_id`      VARCHAR(255)               NOT NULL,
                `aggregate_version` BIGINT                     NOT NULL,
                `source_updated_at` DATETIME                   NOT NULL,
                `checksum`          CHAR(64)                   NOT NULL,
                `correlation_id`    CHAR(36)                   NOT NULL,
                `causation_id`      CHAR(36)                   NULL,
                `payload`           JSON                       NOT NULL,
                `status`            ENUM('pending','relayed')  NOT NULL DEFAULT 'pending',
                `created_at`        DATETIME                   NOT NULL,
                `relayed_at`        DATETIME                   NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_relay_claim` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $eventId       = $this->uuidv7();
        $correlationId = $this->uuidv7();
        $checksum      = hash('sha256', '{"title":"Dispatcher test"}');
        $now           = gmdate('Y-m-d H:i:s');

        $stmt = $mysqli->prepare(
            "INSERT INTO `{$outbox}`
                 (`id`,`event_type`,`event_version`,`aggregate_type`,`aggregate_id`,
                  `aggregate_version`,`source_updated_at`,`checksum`,`correlation_id`,
                  `causation_id`,`payload`,`status`,`created_at`,`relayed_at`)
             VALUES (?,?,1,?,?,1,'2026-01-01 00:00:00',?,?,NULL,'{}','pending',?,NULL)"
        );
        $type = 'content.post.created';
        $at   = 'post';
        $ai   = '99';
        $stmt->bind_param('sssssss', $eventId, $type, $at, $ai, $checksum, $correlationId, $now);
        $stmt->execute();
        $stmt->close();

        $mysqlConn = new MysqliOutboxConnection($mysqli);
        $pgsqlConn = new PgsqlOutboxConnection(
            new PostgresDatabaseConnection(
                \pg_connect($this->pgsqlDsn(), PGSQL_CONNECT_FORCE_NEW)
            )
        );
        $relay = new \HSP\Core\Workers\Strategies\RelayWorkerStrategy(
            $mysqlConn,
            $pgsqlConn,
            $prefix,
            10,
        );
        $relay->tick();

        $mysqli->query("DROP TABLE IF EXISTS `{$outbox}`");
        $mysqli->close();

        return $eventId;
    }

    // =========================================================================
    // Schema setup
    // =========================================================================

    private function createSchema(): void
    {
        \pg_query($this->testConn, 'CREATE SCHEMA IF NOT EXISTS system');
        \pg_query($this->testConn, 'DROP TABLE IF EXISTS system.queue_jobs');
        \pg_query($this->testConn, 'DROP TABLE IF EXISTS system.events');

        \pg_query($this->testConn,
            "CREATE TABLE system.events (
                id                UUID         NOT NULL,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INTEGER      NOT NULL DEFAULT 1,
                aggregate_type    VARCHAR(100) NOT NULL DEFAULT '',
                aggregate_id      VARCHAR(255) NOT NULL DEFAULT '',
                payload           JSONB        NOT NULL DEFAULT '{}',
                created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                aggregate_version BIGINT       NOT NULL DEFAULT 1,
                source_updated_at TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                checksum          VARCHAR(64)  NOT NULL DEFAULT '',
                correlation_id    UUID         NOT NULL DEFAULT gen_random_uuid(),
                causation_id      UUID         NULL,
                CONSTRAINT pk_system_events PRIMARY KEY (id)
            )"
        );

        \pg_query($this->testConn,
            "CREATE TABLE system.queue_jobs (
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
                CONSTRAINT pk_system_queue_jobs PRIMARY KEY (id),
                CONSTRAINT uq_queue_jobs_event_id UNIQUE (event_id)
            )"
        );
    }

    // =========================================================================
    // DB seeding / assertion helpers
    // =========================================================================

    private function insertSystemEvent(
        string $eventId,
        string $eventType,
        string $aggregateId = '1',
    ): void {
        $result = \pg_query_params(
            $this->testConn,
            "INSERT INTO system.events (id, event_type, aggregate_id)
             VALUES ($1::uuid, $2, $3)",
            [$eventId, $eventType, $aggregateId],
        );
        if ($result === false) {
            $this->fail('Failed to insert system.events row: ' . \pg_last_error($this->testConn));
        }
    }

    private function markJobCompleted(string $eventId): void
    {
        \pg_query_params(
            $this->testConn,
            "UPDATE system.queue_jobs SET status = 'completed', completed_at = NOW() WHERE event_id = $1::uuid",
            [$eventId],
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function queryQueueJobs(string $eventId): array
    {
        $result = \pg_query_params(
            $this->testConn,
            'SELECT id, event_id, queue_name, status FROM system.queue_jobs WHERE event_id = $1::uuid',
            [$eventId],
        );
        return $result !== false ? (\pg_fetch_all($result) ?: []) : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function queryAllQueueJobs(): array
    {
        $result = \pg_query($this->testConn, 'SELECT id, event_id, queue_name, status FROM system.queue_jobs');
        return $result !== false ? (\pg_fetch_all($result) ?: []) : [];
    }

    /** @return array<int, array<string, mixed>> */
    private function querySystemEvents(string $eventId): array
    {
        $result = \pg_query_params($this->testConn, 'SELECT id FROM system.events WHERE id = $1::uuid', [$eventId]);
        return $result !== false ? (\pg_fetch_all($result) ?: []) : [];
    }

    // =========================================================================
    // DB connection helpers
    // =========================================================================

    private function connectPgsql(): mixed
    {
        $user = getenv('HSP_TEST_PGSQL_USER') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';
        if ($user === '' || $db === '') {
            $this->markTestSkipped('PostgreSQL env vars not set (HSP_TEST_PGSQL_USER, HSP_TEST_PGSQL_DATABASE).');
        }
        $conn = \pg_connect($this->pgsqlDsn());
        if ($conn === false) {
            $this->markTestSkipped('Could not connect to PostgreSQL for integration test.');
        }
        return $conn;
    }

    private function pgsqlDsn(): string
    {
        $host = getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_PGSQL_PORT') ?: 5432);
        $user = getenv('HSP_TEST_PGSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';
        return "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
    }

    // =========================================================================
    // UUIDv7 — ADR-015 canon
    // =========================================================================

    private function uuidv7(): string
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
}
