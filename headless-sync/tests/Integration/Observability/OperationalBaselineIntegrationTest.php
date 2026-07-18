<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Observability;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Observability\OperationalMetricsQuery;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Observability\WorkerCounters;
use HSP\Core\Queue\DeadLetterRepository;
use HSP\Core\Queue\Exception\DeadLetterReplayException;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\DatabaseHeartbeatPublisher;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;
use PHPUnit\Framework\TestCase;

// Split-outbox fakes reused so the relay stage can idle without a live MySQL connection.
require_once __DIR__ . '/../../Unit/Events/Outbox/FakeOutboxConnection.php';

/**
 * OPS-S1 Early Operational Baseline — live-PostgreSQL integration proofs (DoD).
 *
 * Proves against a real database:
 *   - DLQ is populated on retry-limit exhaustion with full OPEN-3 context (DECISION A).
 *   - hsp dlq replay re-processes a dead-lettered event cleanly end-to-end, THROUGH the
 *     UNIQUE(event_id) constraint (a fresh claimable job appears); double-replay rejected.
 *   - A stale replay acks with zero projection writes (DECISION J / DECISION S clause (c)).
 *   - Heartbeat row is visible and last_heartbeat_at advances per tick (DECISION P).
 *   - Simulated worker crash → visibility timeout → MaintenanceWorkerStrategy requeues the
 *     job, proven THROUGH the real runtime driver (WorkerEngine.tick), not a direct
 *     requeueTimedOut() call (DECISION R).
 *   - Derived metrics are queryable (DECISION Q).
 *
 * Environment (self-skips if PG absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class OperationalBaselineIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // DLQ populated on retry-limit exhaustion (DECISION A context re-proven)
    // =========================================================================

    public function test_retry_limit_exhaustion_dead_letters_with_full_context(): void
    {
        $queue = new DatabaseQueueProvider($this->db, ['retry_limit' => 3]);

        $eventId = $this->seedEvent('content.post.updated', 'post', '100', 1);
        $this->enqueueAvailableJob($eventId, attempts: 3); // already at the retry limit

        // A handler that always throws → terminal failure on this (attempts >= limit) attempt.
        $registry = new EventRegistry();
        $registry->register('content.post.updated', function (): void {
            throw new \RuntimeException('handler boom');
        });

        $strategy = new EventWorkerStrategy($queue, $registry, $this->db, retryLimit: 3);

        $ctx = $this->ctx('01900000-0000-7000-8000-0000000000f1');
        self::assertTrue($strategy->execute($ctx), 'a job was claimed and processed');

        // DLQ row exists with OPEN-3 context.
        $rows = pg_fetch_all(pg_query($this->pgConn,
            'SELECT event_id, failure_reason, stack_trace, attempt_count, worker_id, payload_snapshot, replayed_at
             FROM system.dead_letter_jobs')) ?: [];
        self::assertCount(1, $rows, 'exactly one DLQ row');

        $dlq = $rows[0];
        self::assertSame($eventId, $dlq['event_id']);
        self::assertStringContainsString('handler boom', $dlq['failure_reason']);
        self::assertNotSame('', (string) $dlq['stack_trace'], 'stack_trace captured (OPEN-3)');
        // claim() increments attempts 3→4 before the handler runs; the DLQ records the
        // post-claim attempt count (4), which is what tripped the retry-limit guard (4 >= 3).
        self::assertSame(4, (int) $dlq['attempt_count']);
        self::assertNotNull($dlq['payload_snapshot'], 'payload_snapshot NOT NULL (DECISION A)');
        self::assertNull($dlq['replayed_at'], 'freshly dead-lettered row is not yet replayed');

        // The queue row is now dead_lettered (retained, DECISION L (d)).
        self::assertSame('dead_lettered', $this->jobStatus($eventId));
    }

    // =========================================================================
    // Replay clean end-to-end through UNIQUE(event_id) + double-replay rejected
    // =========================================================================

    public function test_replay_produces_a_fresh_claimable_job_and_rejects_double_replay(): void
    {
        $queue = new DatabaseQueueProvider($this->db, ['retry_limit' => 1]);

        // Dead-letter a job the real way: deadLetter() moves the queue row to
        // 'dead_lettered' (retained) and writes the DLQ row.
        $eventId = $this->seedEvent('content.post.updated', 'post', '200', 1);
        $jobId   = $this->enqueueAvailableJob($eventId, attempts: 1);
        $workerId = '01900000-0000-7000-8000-0000000000f2';

        // Claim then dead-letter (ownership-fenced) — reproduces terminal failure.
        $claimed = $queue->claim('content', $workerId);
        self::assertNotNull($claimed);
        self::assertTrue($queue->deadLetter($claimed['id'], $workerId, [
            'failure_reason'   => 'terminal',
            'attempt_count'    => 1,
            'payload_snapshot' => ['event_id' => $eventId],
        ]));

        self::assertSame('dead_lettered', $this->jobStatus($eventId));

        // A naive re-enqueue would ON CONFLICT DO NOTHING (silent no-op) because the
        // dead_lettered row still holds the UNIQUE(event_id). Prove that trap exists:
        $queue->enqueueIdempotent($eventId, 'content');
        self::assertNull($queue->claim('content', $workerId),
            'naive re-enqueue is a silent no-op — no claimable job (UNIQUE(event_id) trap)');

        // Now replay via the DECISION S lifecycle.
        $dlqId = $this->dlqIdFor($eventId);
        $repo  = new DeadLetterRepository($this->db);
        self::assertSame($eventId, $repo->replay($dlqId));

        // A fresh, claimable job now exists with attempts reset to 0.
        $fresh = $queue->claim('content', $workerId);
        self::assertNotNull($fresh, 'replay produced a fresh claimable job');
        self::assertSame($eventId, $fresh['event_id']);
        self::assertSame(1, (int) $fresh['attempts'], 'attempts were reset to 0, then claim increments to 1');

        // DLQ row preserved (permanent audit) and stamped replayed_at.
        self::assertNotNull($this->dlqReplayedAt($dlqId), 'replayed_at stamped');
        self::assertSame(1, $this->countRows('system.dead_letter_jobs'), 'DLQ row not deleted');

        // Double-replay is rejected by the replayed_at guard.
        $this->expectException(DeadLetterReplayException::class);
        $repo->replay($dlqId);
    }

    // =========================================================================
    // Stale replay = ack + zero writes (DECISION J / DECISION S clause (c))
    // =========================================================================

    public function test_replayed_event_that_is_stale_acks_with_zero_projection_writes(): void
    {
        $queue = new DatabaseQueueProvider($this->db, ['retry_limit' => 5]);

        // The aggregate is already at version 5; the replayed event carries version 3.
        $this->setAggregateVersion('post', '300', 5);

        $eventId = $this->seedEvent('content.post.updated', 'post', '300', 3);
        $this->enqueueAvailableJob($eventId, attempts: 0);

        // Handler would write if invoked — assert it is NOT invoked (stale gate fires first).
        $invoked  = false;
        $registry = new EventRegistry();
        $registry->register('content.post.updated', function () use (&$invoked): void {
            $invoked = true;
        });

        $strategy = new EventWorkerStrategy($queue, $registry, $this->db, retryLimit: 5);
        self::assertTrue($strategy->execute($this->ctx('01900000-0000-7000-8000-0000000000f3')));

        self::assertFalse($invoked, 'stale event: handler not invoked → zero projection writes');
        self::assertSame('completed', $this->jobStatus($eventId), 'stale event is acked (completed), not dead-lettered');
        self::assertSame(0, $this->countRows('system.processed_events'), 'no processed_events row written for stale ack');
    }

    // =========================================================================
    // Heartbeat visible + advances per tick (DECISION P)
    // =========================================================================

    public function test_heartbeat_row_is_visible_per_cycle_with_running_or_idle_status(): void
    {
        // ADR-054 / DECISION X: each processing cycle mints a FRESH UUIDv7 and upserts one
        // current-state heartbeat row (DECISION P schema, reused verbatim). Two cycles → two
        // distinct rows (processing-cycle executions, not one daemon identity). Status is only
        // ever 'running' | 'idle'. Drive the publisher directly with two fresh cycle identities.
        $publisher = new DatabaseHeartbeatPublisher($this->db);

        $cycleOne = '01900000-0000-7000-8000-0000000000a1';
        $publisher->publish(new \HSP\Core\Workers\HeartbeatRecord(
            workerId:        $cycleOne,
            status:          'running',
            lastHeartbeatAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            workerType:      'processing',
            startedAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $first = $this->heartbeatRow($cycleOne);
        self::assertNotNull($first, 'heartbeat row visible after the first cycle');
        self::assertSame('processing', $first['worker_type']);
        self::assertSame('running', $first['status']);

        $cycleTwo = '01900000-0000-7000-8000-0000000000a2';
        $publisher->publish(new \HSP\Core\Workers\HeartbeatRecord(
            workerId:        $cycleTwo,
            status:          'idle',
            lastHeartbeatAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            workerType:      'processing',
            startedAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        $second = $this->heartbeatRow($cycleTwo);
        self::assertNotNull($second, 'the second cycle wrote its own fresh-UUID row');
        self::assertSame('idle', $second['status']);

        self::assertSame(2, $this->countRows('system.worker_heartbeats'),
            'two cycles → two distinct worker_id rows (DECISION X ruling (1))');
    }

    // =========================================================================
    // Crash → visibility timeout → MaintenanceWorkerStrategy requeue via runtime driver
    // =========================================================================

    public function test_crash_then_timeout_requeue_through_the_real_maintenance_runtime_driver(): void
    {
        // Visibility timeout is effectively immediate so the "crash" expires at once.
        $queue = new DatabaseQueueProvider($this->db, ['visibility_timeout_seconds' => 0]);

        $eventId = $this->seedEvent('content.post.updated', 'post', '400', 1);
        $this->enqueueAvailableJob($eventId, attempts: 0);

        // Worker A claims the job, then "crashes" (never completes). With a 0s timeout the
        // lease is already expired.
        $claimed = $queue->claim('content', '01900000-0000-7000-8000-0000000000f4');
        self::assertNotNull($claimed);
        self::assertSame('claimed', $this->jobStatus($eventId));

        // Drive recovery THROUGH the real runtime driver: the MaintenanceWorkerStrategy sweep
        // (DECISION R) — this is exactly the maintenance STAGE the ADR-054 Processing Engine
        // cycle invokes once per cycle (no in-process cadence throttle — the cron tick is the
        // cadence). NOT a direct requeueTimedOut() call.
        $maintenance = new MaintenanceWorkerStrategy($queue, ['partitions' => ['content']]);

        $didWork = $maintenance->execute($this->ctx('01900000-0000-7000-8000-00000000ma1n'));
        self::assertTrue($didWork, 'the maintenance sweep ran');

        // The crashed job is available again and claimable by a fresh worker.
        self::assertSame('available', $this->jobStatus($eventId), 'expired job requeued to available');
        $reclaim = $queue->claim('content', '01900000-0000-7000-8000-0000000000f5');
        self::assertNotNull($reclaim, 'requeued job is claimable by a new worker');
        self::assertSame($eventId, $reclaim['event_id']);

        // The maintenance strategy observed the requeue for observability.
        self::assertSame(['content' => 1], $maintenance->getLastRequeuedByPartition());
    }

    // =========================================================================
    // Derived metrics queryable (DECISION Q)
    // =========================================================================

    public function test_derived_metrics_are_queryable_from_live_tables(): void
    {
        $queue   = new DatabaseQueueProvider($this->db);
        $metrics = new OperationalMetricsQuery($this->db);

        // Two available jobs on 'content', one worker heartbeat, one DLQ row.
        $this->enqueueAvailableJob($this->seedEvent('content.post.updated', 'post', '500', 1), attempts: 0);
        $this->enqueueAvailableJob($this->seedEvent('content.post.updated', 'post', '501', 1), attempts: 0);
        (new DatabaseHeartbeatPublisher($this->db))->publish(new \HSP\Core\Workers\HeartbeatRecord(
            workerId:        '01900000-0000-7000-8000-0000000000f6',
            status:          'idle',
            lastHeartbeatAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            workerType:      'event',
            startedAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));
        $this->insertDlqRow($this->seedEvent('content.post.updated', 'post', '502', 1));

        $snapshot = $metrics->snapshot();

        self::assertSame(2, $snapshot['queue_depth_total']);
        self::assertSame(['content' => 2], $snapshot['queue_depth_by_partition']);
        self::assertSame(1, $snapshot['dlq_depth']);
        self::assertSame(1, $snapshot['worker_count']);
        self::assertNotNull($snapshot['oldest_pending_age_seconds']);
        self::assertGreaterThanOrEqual(0.0, $snapshot['oldest_pending_age_seconds']);
    }

    // =========================================================================
    // Runtime counters emitted as structured logs (DECISION Q clause 2)
    // =========================================================================

    public function test_runtime_counters_appear_in_structured_logs_on_a_processed_cycle(): void
    {
        // ADR-054: a processed cycle emits ONE 'processing.cycle' structured log carrying the
        // per-cycle result plus the runtime counters snapshot (DECISION Q clause 2). Here a job
        // is already queued (relay/dispatch find nothing); the projection stage processes it and
        // increments the processed counter, and the engine emits the cycle metric.
        $queue = new DatabaseQueueProvider($this->db, ['retry_limit' => 5]);

        $eventId = $this->seedEvent('content.post.updated', 'post', '600', 1);
        $this->enqueueAvailableJob($eventId, attempts: 0);

        $registry = new EventRegistry();
        $registry->register('content.post.updated', function (): void { /* success */ });

        $counters = new WorkerCounters();
        $captured = [];
        $logger   = new StructuredLogger(static function (string $l) use (&$captured): void { $captured[] = $l; });

        // Real Processing Engine cycle: relay stage over split-outbox fakes (idle — no outbox
        // rows); dispatch stage over live PG (idle — system.events empty); real projection
        // stage processes the queued job; maintenance sweep over the live queue.
        $relay = new \HSP\Core\Workers\Strategies\RelayWorkerStrategy(
            new \HSP\Tests\Unit\Events\Outbox\FakeMysqlOutboxConnection(),
            new \HSP\Tests\Unit\Events\Outbox\FakePgsqlOutboxConnection(),
            'wp_',
            100,
        );
        $dispatch    = new \HSP\Core\Events\Dispatcher\DispatcherWorkerStrategy(
            new \HSP\Core\Events\Dispatcher\EventDispatcher($this->db, $queue, 100),
        );
        $projection  = new EventWorkerStrategy($queue, $registry, $this->db, retryLimit: 5, counters: $counters);
        $maintenance = new MaintenanceWorkerStrategy($queue, ['partitions' => ['content']]);

        $engine = new WorkerEngine(
            $relay,
            $dispatch,
            $projection,
            $maintenance,
            new DatabaseHeartbeatPublisher($this->db),
            projectionBatchSize:    100,
            cycleTimeBudgetSeconds:  20,
            workerType:              'processing',
            counters:                $counters,
            logger:                  $logger,
        );

        $result = $engine->runCycle();

        self::assertSame(1, $result->projected, 'the queued job was projected this cycle');
        self::assertTrue($result->didWork());

        self::assertNotEmpty($captured, 'a structured cycle line was emitted');
        $decoded = json_decode($captured[0], true);
        self::assertSame('processing.cycle', $decoded['event']);
        self::assertSame(1, $decoded['projected'], 'the cycle metric reports one projected job');
        self::assertArrayHasKey('counters', $decoded, 'the runtime counters snapshot rides the cycle metric');
        self::assertSame(1, $decoded['counters']['processed'], 'processed counter reflects the successful job');
        self::assertSame(0, $decoded['counters']['failure']);
    }

    // =========================================================================
    // Helpers — event / queue / dlq seeding
    // =========================================================================

    private function seedEvent(string $type, string $aggType, string $aggId, int $aggVersion): string
    {
        $id = $this->newUuid();
        pg_query_params($this->pgConn,
            "INSERT INTO system.events
                 (id, event_type, event_version, aggregate_type, aggregate_id, aggregate_version,
                  payload, checksum, source_updated_at, created_at, correlation_id, causation_id)
             VALUES ($1::uuid, $2, 1, $3, $4, $5, '{}'::jsonb, $6, NOW(), NOW(), $7::uuid, NULL)",
            [$id, $type, $aggType, $aggId, $aggVersion, str_repeat('a', 64), $this->newUuid()],
        );
        return $id;
    }

    private function enqueueAvailableJob(string $eventId, int $attempts): string
    {
        $jobId = $this->newUuid();
        pg_query_params($this->pgConn,
            "INSERT INTO system.queue_jobs
                 (id, event_id, queue_name, status, attempts, available_at)
             VALUES ($1::uuid, $2::uuid, 'content', 'available', $3, NOW())",
            [$jobId, $eventId, $attempts],
        );
        return $jobId;
    }

    private function insertDlqRow(string $eventId): void
    {
        pg_query_params($this->pgConn,
            "INSERT INTO system.dead_letter_jobs
                 (id, job_id, event_id, failure_reason, created_at, stack_trace, attempt_count, worker_id, payload_snapshot)
             VALUES ($1::uuid, $2::uuid, $3::uuid, 'seed', NOW(), NULL, 1, NULL, '{}'::jsonb)",
            [$this->newUuid(), $this->newUuid(), $eventId],
        );
    }

    private function setAggregateVersion(string $aggType, string $aggId, int $version): void
    {
        pg_query_params($this->pgConn,
            "INSERT INTO system.aggregate_versions
                 (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, $3, NOW())",
            [$aggType, $aggId, $version],
        );
    }

    // =========================================================================
    // Helpers — reads
    // =========================================================================

    private function jobStatus(string $eventId): ?string
    {
        $r = pg_query_params($this->pgConn,
            'SELECT status FROM system.queue_jobs WHERE event_id = $1::uuid ORDER BY available_at DESC LIMIT 1',
            [$eventId]);
        $row = pg_fetch_assoc($r) ?: null;
        return $row['status'] ?? null;
    }

    private function dlqIdFor(string $eventId): string
    {
        $r = pg_query_params($this->pgConn,
            'SELECT id FROM system.dead_letter_jobs WHERE event_id = $1::uuid LIMIT 1', [$eventId]);
        return (string) pg_fetch_result($r, 0, 0);
    }

    private function dlqReplayedAt(string $dlqId): ?string
    {
        $r = pg_query_params($this->pgConn,
            'SELECT replayed_at FROM system.dead_letter_jobs WHERE id = $1::uuid', [$dlqId]);
        $row = pg_fetch_assoc($r) ?: null;
        return $row['replayed_at'] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function heartbeatRow(string $workerId): ?array
    {
        $r = pg_query_params($this->pgConn,
            'SELECT worker_id, worker_type, status, last_heartbeat_at, started_at
             FROM system.worker_heartbeats WHERE worker_id = $1::uuid', [$workerId]);
        return pg_fetch_assoc($r) ?: null;
    }

    private function countRows(string $table): int
    {
        $r = pg_query($this->pgConn, "SELECT COUNT(*) AS c FROM {$table}");
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    private function ctx(string $workerId): \HSP\Core\Workers\WorkerExecutionContext
    {
        return new \HSP\Core\Workers\WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // =========================================================================
    // Connection + schema
    // =========================================================================

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: 'hsp';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'hsp_secret';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'hsp';

        $conn = @pg_connect("host={$host} port={$port} user={$user} password={$pass} dbname={$db}", PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping operational baseline tests.");
        }

        return $conn;
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        pg_query($this->pgConn, 'CREATE SCHEMA system');

        pg_query($this->pgConn, "
            CREATE TABLE system.events (
                id                UUID         NOT NULL PRIMARY KEY,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INT          NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                payload           JSONB        NOT NULL,
                checksum          VARCHAR(64)  NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                correlation_id    UUID         NOT NULL,
                causation_id      UUID         NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.queue_jobs (
                id                    UUID         NOT NULL PRIMARY KEY,
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
                CONSTRAINT uq_queue_jobs_event_id UNIQUE (event_id)
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.dead_letter_jobs (
                id               UUID        NOT NULL PRIMARY KEY,
                job_id           UUID        NOT NULL,
                event_id         UUID        NOT NULL,
                failure_reason   TEXT        NOT NULL,
                created_at       TIMESTAMPTZ NOT NULL,
                stack_trace      TEXT        NULL,
                attempt_count    INTEGER     NOT NULL DEFAULT 0,
                worker_id        UUID        NULL,
                payload_snapshot JSONB       NOT NULL,
                replayed_at      TIMESTAMPTZ NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_agg PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.processed_events (
                event_id     UUID        NOT NULL PRIMARY KEY,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.worker_heartbeats (
                worker_id         UUID        NOT NULL PRIMARY KEY,
                worker_type       TEXT        NOT NULL,
                status            TEXT        NOT NULL,
                last_heartbeat_at TIMESTAMPTZ NOT NULL,
                started_at        TIMESTAMPTZ NOT NULL
            )
        ");
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
}
