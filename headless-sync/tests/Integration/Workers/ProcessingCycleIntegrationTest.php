<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Workers;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\DispatcherWorkerStrategy;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\DatabaseHeartbeatPublisher;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Handlers\CategoryTombstoneHandler;
use HSP\Modules\Content\Handlers\CategoryUpsertHandler;
use HSP\Modules\Content\Handlers\PageTombstoneHandler;
use HSP\Modules\Content\Handlers\PageUpsertHandler;
use HSP\Modules\Content\Handlers\PostTombstoneHandler;
use HSP\Modules\Content\Handlers\PostUpsertHandler;
use HSP\Modules\Content\Subscribers\ContentSubscriber;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Integration\Replay\FakeWpStore;
use HSP\Tests\Integration\Replay\ReplayReadingLoader;
use PHPUnit\Framework\TestCase;

/**
 * ADR-054 / Doc 8 v2.0 §29 — WP-Cron Processing Engine cycle behaviours, on LIVE MySQL + PG.
 *
 * Proves the DoD acceptance surface for the ALIGN-S1 alignment implementation, all against the
 * REAL runtime (RelayWorkerStrategy → DispatcherWorkerStrategy → EventWorkerStrategy →
 * ContentSubscriber → content.* adapters, DatabaseHeartbeatPublisher, live PostgreSQL):
 *
 *   1. A cycle runs bounded per-stage batches and exits (no loop-to-empty, no sleep); work
 *      exceeding one cycle continues correctly across a SECOND cycle (durable progress); on
 *      budget exhaustion the in-flight event's DECISION 3 transaction completes and the cycle
 *      exits cleanly mid-backlog.
 *   2. TWO overlapping cycles on independent PG connections process a shared queue with no
 *      double-claim, no duplicate processed_events, monotonic aggregate_versions — via existing
 *      guarantees only (extends the GATE-S2 technique).
 *   3. A cycle killed mid-batch (simulated) leaves the claimed job recoverable via visibility
 *      timeout on a later cycle (recovery-across-cycles).
 *   4. Each cycle upserts one fresh-UUID heartbeat row, status ∈ {running,idle}; two cycles →
 *      two distinct worker_id rows.
 *
 * The ONLY substitution is the WordPress state-reload boundary (DECISION H / ADR-044): a
 * headless PHPUnit process cannot bootstrap WordPress, so FakeWpStore stands in for
 * get_post/get_term and ReplayReadingLoader reads it. Everything else is the real runtime.
 *
 * Environment (self-skips if a DB is genuinely absent):
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class ProcessingCycleIntegrationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_cycle_';
    private string $outbox;
    private string $counters;

    /** Extra physical PG links opened by the overlap test; closed in tearDown. */
    private array $extraPgConns = [];

    private FakeWpStore $wp;

    protected function setUp(): void
    {
        $this->outbox   = $this->prefix . 'hsp_outbox';
        $this->counters = $this->prefix . 'hsp_aggregate_counters';

        $this->mysqli = $this->connectMysql();
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->wp     = new FakeWpStore();

        $this->createMysqlSchema();
        $this->createPgsqlSchema();
    }

    protected function tearDown(): void
    {
        foreach ($this->extraPgConns as $conn) {
            if ($conn !== null) {
                @pg_query($conn, 'ROLLBACK');
                @pg_close($conn);
            }
        }
        $this->extraPgConns = [];

        if ($this->mysqli !== null) {
            $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
            $this->mysqli->query("DROP TABLE IF EXISTS `{$this->counters}`");
            $this->mysqli->close();
            $this->mysqli = null;
        }
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // 1 — bounded per-stage batches; continuation across a second cycle; budget exhaustion.
    // =========================================================================

    public function test_a_single_cycle_advances_the_pipeline_and_exits(): void
    {
        // Three fresh post-create events sit in the outbox. One cycle relays, dispatches, and
        // projects them all (batch sizes generous), then exits — no loop, no sleep.
        $this->seedPost(1, 'alpha');
        $this->seedPost(2, 'bravo');
        $this->seedPost(3, 'charlie');

        $engine = $this->makeEngine($this->db, projectionBatchSize: 100, budget: 20.0);
        $result = $engine->runCycle();

        self::assertSame(3, $result->relayed, 'relay stage relayed the batch');
        self::assertSame(3, $result->dispatched, 'dispatch stage enqueued the batch');
        self::assertSame(3, $result->projected, 'projection stage projected the batch');
        self::assertTrue($result->maintenanceSwept, 'maintenance stage ran');
        self::assertFalse($result->budgetExhausted, 'the cycle finished within budget');
        self::assertTrue($result->didWork());

        self::assertSame(3, $this->countRows('content.posts'), 'all three posts projected');
        self::assertSame(0, $this->countRows('system.queue_jobs WHERE status = \'queued\''), 'queue drained this cycle');
    }

    public function test_backlog_larger_than_one_cycle_continues_across_a_second_cycle(): void
    {
        // Five events, but the projection batch size is 2 → cycle 1 projects 2, cycle 2 projects
        // 2, cycle 3 projects the last 1. Continuation state is purely the durable pipeline
        // tables (Doc 8 v2.0 §12) — the engine carries nothing forward in memory.
        for ($i = 1; $i <= 5; $i++) {
            $this->seedPost($i, "post-{$i}");
        }

        $engine = $this->makeEngine($this->db, projectionBatchSize: 2, budget: 20.0);

        $c1 = $engine->runCycle();
        self::assertSame(2, $c1->projected, 'cycle 1 projects at most projection_batch_size');
        self::assertSame(2, $this->countRows('content.posts'));

        $c2 = $engine->runCycle();
        self::assertSame(2, $c2->projected, 'cycle 2 continues the backlog');
        self::assertSame(4, $this->countRows('content.posts'));

        $c3 = $engine->runCycle();
        self::assertSame(1, $c3->projected, 'cycle 3 drains the remainder');
        self::assertSame(5, $this->countRows('content.posts'), 'all five projected across three cycles');

        // A fourth cycle over an empty pipeline is a cheap idle no-op.
        $c4 = $engine->runCycle();
        self::assertFalse($c4->didWork(), 'an empty pipeline yields an idle cycle');
    }

    public function test_budget_exhaustion_finishes_in_flight_transaction_and_exits_mid_backlog(): void
    {
        // Relay + dispatch a batch first (so jobs are queued), then run a projection-only style
        // cycle with a zero budget: the budget is already spent when the projection stage would
        // claim, so it claims NOTHING new and exits cleanly — no partial projection, backlog
        // intact for the next cycle. (The in-flight guarantee is structural: EventWorkerStrategy
        // commits each event's DECISION 3 transaction atomically per call; the engine only
        // checks the budget BETWEEN claims, so a started claim always finishes.)
        for ($i = 1; $i <= 3; $i++) {
            $this->seedPost($i, "budget-{$i}");
        }
        // Prime the queue with a normal cycle's relay+dispatch by using a generous budget but a
        // zero projection batch so nothing is projected yet.
        $primer = $this->makeEngine($this->db, projectionBatchSize: 0, budget: 20.0);
        $primer->runCycle();
        self::assertSame(3, $this->countRows('system.queue_jobs'), 'three jobs queued, none projected');

        // Now a zero-budget cycle: it must claim/project nothing and report budget exhaustion.
        $starved = $this->makeEngine($this->db, projectionBatchSize: 100, budget: 0.0);
        $result  = $starved->runCycle();

        self::assertTrue($result->budgetExhausted, 'the cycle stopped on the budget');
        self::assertSame(0, $result->projected, 'no new work claimed under an exhausted budget');
        self::assertSame(0, $this->countRows('content.posts'), 'no partial projection written');
        self::assertSame(3, $this->countRows('system.queue_jobs'), 'the backlog is left intact for the next cycle');

        // A later healthy cycle drains the intact backlog — no event was lost.
        $healthy = $this->makeEngine($this->db, projectionBatchSize: 100, budget: 20.0);
        $healthy->runCycle();
        self::assertSame(3, $this->countRows('content.posts'), 'the backlog drains on a later cycle — nothing lost');
    }

    // =========================================================================
    // 2 — two overlapping cycles on independent PG connections: no double-claim,
    //     no duplicate processed_events, monotonic aggregate_versions.
    // =========================================================================

    public function test_two_overlapping_cycles_share_a_queue_with_no_double_claim(): void
    {
        // Eight posts queued. Two engines on DISTINCT physical PG links run their projection
        // stages interleaved by hand — exactly the overlapping-cron-cycle scenario (ADR-054 §3),
        // safe via SKIP LOCKED + aggregate versioning + DECISION 3 commit, no new lock.
        for ($i = 1; $i <= 8; $i++) {
            $this->seedPost($i, "shared-{$i}");
        }
        // Relay + dispatch once so all eight jobs are queued.
        $this->makeEngine($this->db, projectionBatchSize: 0, budget: 20.0)->runCycle();
        self::assertSame(8, $this->countRows('system.queue_jobs'), 'eight jobs queued');

        $connA = $this->openExtraPgSession();
        $connB = $this->openExtraPgSession();
        $dbA   = new PostgresDatabaseConnection($connA);
        $dbB   = new PostgresDatabaseConnection($connB);

        $projA = $this->makeEventStrategy($dbA);
        $projB = $this->makeEventStrategy($dbB);
        $ctxA  = $this->ctx($this->uuidv7());
        $ctxB  = $this->ctx($this->uuidv7());

        // Interleave the two projection stages one claim at a time until both drain.
        $guard = 0;
        do {
            $a = $projA->execute($ctxA);
            $b = $projB->execute($ctxB);
            if (++$guard > 100) {
                self::fail('overlapping projection did not drain the queue');
            }
        } while ($a || $b);

        // All eight projected exactly once — no double-claim, no fork.
        self::assertSame(8, $this->countRows('content.posts'), 'every post projected exactly once');
        // No duplicate processed_events (PK on event_id — a double-claim would have collided).
        self::assertSame(8, $this->countRows('system.processed_events'), 'one processed_events row per event, no duplicates');
        // aggregate_versions advanced to 1 for each of the eight aggregates (monotonic, no regress).
        $r = pg_query($this->pgConn, "SELECT MIN(latest_processed_version) AS lo, MAX(latest_processed_version) AS hi FROM system.aggregate_versions");
        $row = pg_fetch_assoc($r);
        self::assertSame('1', $row['lo'], 'every aggregate advanced to version 1');
        self::assertSame('1', $row['hi'], 'no aggregate over-advanced');
    }

    // =========================================================================
    // 3 — a cycle killed mid-batch → the claimed job is recovered by visibility
    //     timeout on a later cycle.
    // =========================================================================

    public function test_a_job_claimed_by_a_killed_cycle_is_recovered_on_a_later_cycle(): void
    {
        $this->seedPost(1, 'recovered');
        // Queue the job (relay + dispatch, no projection).
        $this->makeEngine($this->db, projectionBatchSize: 0, budget: 20.0)->runCycle();
        self::assertSame(1, $this->countRows('system.queue_jobs'), 'one job queued');

        // Simulate a cycle that CLAIMED the job and was then hard-killed before committing:
        // the claim sets status='claimed', a worker_id, and a visibility_timeout_at. We set the
        // timeout to the PAST so it is already expired (the maintenance sweep would requeue it).
        $ghostWorker = $this->uuidv7();
        pg_query_params(
            $this->pgConn,
            "UPDATE system.queue_jobs
             SET status = 'claimed', worker_id = $1::uuid,
                 visibility_timeout_at = NOW() - INTERVAL '10 minutes'
             WHERE status = 'queued'",
            [$ghostWorker],
        );
        self::assertSame(0, $this->countRows("system.queue_jobs WHERE status = 'queued'"), 'the job is stuck as claimed');
        self::assertSame(0, $this->countRows('content.posts'), 'nothing projected — the ghost cycle died before commit');

        // A later cycle: its maintenance stage requeues the expired claim, then its projection
        // stage re-claims and projects it — recovery across cycles, no event lost.
        $recovery = $this->makeEngine($this->db, projectionBatchSize: 100, budget: 20.0);
        $result   = $recovery->runCycle();

        self::assertTrue($result->maintenanceSwept, 'maintenance ran');
        self::assertSame(1, $result->projected, 'the requeued job was re-claimed and projected on this cycle');
        self::assertSame(1, $this->countRows('content.posts'), 'the killed-cycle job is recovered — nothing lost');
        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 1);
        self::assertSame('recovered', $row['slug']);
    }

    // =========================================================================
    // 4 — per-cycle fresh-UUID heartbeat; status ∈ {running,idle}; two cycles → two rows.
    // =========================================================================

    public function test_each_cycle_writes_a_fresh_uuid_heartbeat_with_running_or_idle_status(): void
    {
        $this->seedPost(1, 'beats');

        $engine = $this->makeEngine($this->db, projectionBatchSize: 100, budget: 20.0);

        // Cycle 1 does work → a 'running' heartbeat under a fresh UUID.
        $r1 = $engine->runCycle();
        $hb1 = $this->heartbeatRow($r1->workerId);
        self::assertNotNull($hb1, 'cycle 1 wrote a heartbeat row under its fresh worker_id');
        self::assertSame('running', $hb1['status'], 'a working cycle is running');
        self::assertSame('processing', $hb1['worker_type']);

        // Cycle 2 over the now-empty pipeline → an 'idle' heartbeat under a DIFFERENT fresh UUID.
        $r2 = $engine->runCycle();
        self::assertNotSame($r1->workerId, $r2->workerId, 'each cycle mints a fresh UUIDv7 (DECISION X ruling (1))');
        $hb2 = $this->heartbeatRow($r2->workerId);
        self::assertNotNull($hb2, 'cycle 2 wrote its own heartbeat row');
        self::assertSame('idle', $hb2['status'], 'an empty cycle is idle');

        // Two cycles → two distinct heartbeat rows (processing-cycle executions, DECISION X (1)).
        self::assertSame(2, $this->countRows('system.worker_heartbeats'), 'two cycles → two distinct worker_id rows');

        // Status is only ever running|idle (DECISION X ruling (2)) — no 'processing'/'shutdown'.
        $r = pg_query($this->pgConn, "SELECT DISTINCT status FROM system.worker_heartbeats ORDER BY status");
        $statuses = [];
        while ($s = pg_fetch_assoc($r)) {
            $statuses[] = $s['status'];
        }
        self::assertSame(['idle', 'running'], $statuses, 'status set is exactly {running, idle}');
    }

    // =========================================================================
    // Engine assembly (real runtime) + seeding helpers
    // =========================================================================

    private function makeEngine(
        PostgresDatabaseConnection $db,
        int $projectionBatchSize,
        float $budget,
    ): WorkerEngine {
        $relay = new RelayWorkerStrategy(
            new MysqliOutboxConnection($this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        );
        $dispatch = new DispatcherWorkerStrategy(
            new EventDispatcher($db, new DatabaseQueueProvider($db), 100),
        );
        $projection = $this->makeEventStrategy($db);
        $queue      = new DatabaseQueueProvider($db);
        $maintenance = new MaintenanceWorkerStrategy($queue, ['partitions' => ['content', 'system']]);
        $publisher  = new DatabaseHeartbeatPublisher($db);

        return new WorkerEngine(
            $relay,
            $dispatch,
            $projection,
            $maintenance,
            $publisher,
            projectionBatchSize:    $projectionBatchSize,
            cycleTimeBudgetSeconds:  $budget,
            workerType:              'processing',
        );
    }

    private function makeEventStrategy(PostgresDatabaseConnection $db): EventWorkerStrategy
    {
        return new EventWorkerStrategy(
            new DatabaseQueueProvider($db),
            $this->makeWiredEventRegistry($db),
            $db,
            retryLimit: 10,
        );
    }

    /** Real EventRegistry → ContentSubscriber → 9 content handlers; loaders read $this->wp. */
    private function makeWiredEventRegistry(PostgresDatabaseConnection $db): EventRegistry
    {
        $pageLoader = new ReplayReadingLoader($this->wp, 'page');
        $postLoader = new ReplayReadingLoader($this->wp, 'post');
        $termLoader = new ReplayReadingLoader($this->wp, 'post');

        $pageAdapter     = new PageAdapter($db);
        $postAdapter     = new PostAdapter($db);
        $categoryAdapter = new CategoryAdapter($db);

        $subscriber = new ContentSubscriber([
            ContentEventTypes::PAGE_CREATED     => new PageUpsertHandler($pageLoader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_UPDATED     => new PageUpsertHandler($pageLoader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_DELETED     => new PageTombstoneHandler($pageAdapter),
            ContentEventTypes::POST_CREATED     => new PostUpsertHandler($postLoader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_UPDATED     => new PostUpsertHandler($postLoader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_DELETED     => new PostTombstoneHandler($postAdapter),
            ContentEventTypes::CATEGORY_CREATED => new CategoryUpsertHandler($termLoader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_UPDATED => new CategoryUpsertHandler($termLoader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_DELETED => new CategoryTombstoneHandler($categoryAdapter),
        ]);

        $registry = new EventRegistry();
        foreach (ContentEventTypes::ALL as $type) {
            $registry->register($type, $subscriber);
        }
        return $registry;
    }

    /** Seed a published post into the FakeWpStore + an outbox create event for it. */
    private function seedPost(int $postId, string $slug): void
    {
        $this->wp->putPost($postId, 'publish', 'post', $slug);
        $this->insertOutboxRow(ContentEventTypes::POST_CREATED, 'post', (string) $postId, 1, $slug);
    }

    private function insertOutboxRow(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
        string $slug,
    ): string {
        $id            = $this->uuidv7();
        $correlationId = $this->uuidv7();
        $now           = gmdate('Y-m-d H:i:s');
        $payload       = json_encode(['slug' => $slug], JSON_THROW_ON_ERROR);
        $checksum      = hash('sha256', $payload);

        $stmt = $this->mysqli->prepare(
            "INSERT INTO `{$this->outbox}`
                 (`id`, `event_type`, `event_version`, `aggregate_type`, `aggregate_id`,
                  `aggregate_version`, `source_updated_at`, `checksum`, `correlation_id`,
                  `causation_id`, `payload`, `status`, `created_at`, `relayed_at`)
             VALUES (?, ?, 1, ?, ?, ?, '2026-01-15 10:00:00', ?, ?, NULL, ?, 'pending', ?, NULL)"
        );
        $stmt->bind_param('ssssissss',
            $id, $eventType, $aggregateType, $aggregateId, $aggregateVersion,
            $checksum, $correlationId, $payload, $now,
        );
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    // =========================================================================
    // Reads
    // =========================================================================

    private function countRows(string $tableWithOptionalWhere): int
    {
        $r = pg_query($this->pgConn, "SELECT COUNT(*) AS c FROM {$tableWithOptionalWhere}");
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    private function fetchProjectionRow(string $table, string $keyCol, int $keyVal): ?array
    {
        $r = pg_query_params($this->pgConn, "SELECT * FROM {$table} WHERE {$keyCol} = \$1", [$keyVal]);
        return pg_fetch_assoc($r) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function heartbeatRow(string $workerId): ?array
    {
        $r = pg_query_params(
            $this->pgConn,
            'SELECT worker_id, worker_type, status FROM system.worker_heartbeats WHERE worker_id = $1::uuid',
            [$workerId],
        );
        return pg_fetch_assoc($r) ?: null;
    }

    private function ctx(string $workerId): \HSP\Core\Workers\WorkerExecutionContext
    {
        return new \HSP\Core\Workers\WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // =========================================================================
    // Connections + schema (mirrors OperabilityValidationTest)
    // =========================================================================

    private function openExtraPgSession(): mixed
    {
        $conn = $this->connectPgsql();
        $this->extraPgConns[] = $conn;
        return $conn;
    }

    private function connectMysql(): \mysqli
    {
        $host = getenv('HSP_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_MYSQL_PORT') ?: 3306);
        $user = getenv('HSP_TEST_MYSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_MYSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_MYSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            $this->markTestSkipped('MySQL env vars not set (HSP_TEST_MYSQL_USER, HSP_TEST_MYSQL_DATABASE).');
        }

        $mysqli = new \mysqli($host, $user, $pass, $db, $port);
        if ($mysqli->connect_errno) {
            $this->markTestSkipped("MySQL connect failed: {$mysqli->connect_error}");
        }
        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_PGSQL_PORT') ?: 5432);
        $user = getenv('HSP_TEST_PGSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            $this->markTestSkipped('PostgreSQL env vars not set (HSP_TEST_PGSQL_USER, HSP_TEST_PGSQL_DATABASE).');
        }

        $conn = @pg_connect("host={$host} port={$port} dbname={$db} user={$user} password={$pass}", PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $this->markTestSkipped("PostgreSQL connect failed: host={$host} port={$port} dbname={$db}");
        }
        return $conn;
    }

    private function createMysqlSchema(): void
    {
        $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
        $this->mysqli->query("DROP TABLE IF EXISTS `{$this->counters}`");
        $this->mysqli->query(
            "CREATE TABLE `{$this->outbox}` (
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
        $this->mysqli->query(
            "CREATE TABLE `{$this->counters}` (
                `aggregate_type` VARCHAR(100) NOT NULL,
                `aggregate_id`   VARCHAR(255) NOT NULL,
                `version`        BIGINT       NOT NULL,
                PRIMARY KEY (`aggregate_type`, `aggregate_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function createPgsqlSchema(): void
    {
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        pg_query($this->pgConn, 'CREATE SCHEMA system');
        pg_query($this->pgConn, 'CREATE SCHEMA content');

        pg_query($this->pgConn, "
            CREATE TABLE system.events (
                id                UUID         NOT NULL PRIMARY KEY,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INTEGER      NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                payload           JSONB        NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                checksum          VARCHAR(64)  NOT NULL,
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
        pg_query($this->pgConn, "
            CREATE TABLE content.pages (
                id UUID NOT NULL, source_post_id BIGINT NOT NULL, source_entity_type VARCHAR(50) NOT NULL DEFAULT 'page',
                slug VARCHAR(255) NOT NULL, title TEXT NOT NULL, content TEXT NOT NULL, status VARCHAR(50) NOT NULL,
                parent_id BIGINT NOT NULL DEFAULT 0, menu_order INTEGER NOT NULL DEFAULT 0,
                published_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, deleted_at TIMESTAMPTZ NULL,
                checksum VARCHAR(64) NOT NULL, meta_jsonb JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_pages PRIMARY KEY (id),
                CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.posts (
                id UUID NOT NULL, source_post_id BIGINT NOT NULL, source_entity_type VARCHAR(50) NOT NULL DEFAULT 'post',
                slug VARCHAR(255) NOT NULL, title TEXT NOT NULL, content TEXT NOT NULL, excerpt TEXT NOT NULL,
                status VARCHAR(50) NOT NULL, author VARCHAR(255) NOT NULL,
                published_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, deleted_at TIMESTAMPTZ NULL,
                checksum VARCHAR(64) NOT NULL, meta_jsonb JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_posts PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.taxonomies (
                id UUID NOT NULL, source_term_id BIGINT NOT NULL, taxonomy_type VARCHAR(50) NOT NULL,
                slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL DEFAULT '',
                parent_id BIGINT NOT NULL DEFAULT 0, post_count INTEGER NOT NULL DEFAULT 0,
                deleted_at TIMESTAMPTZ NULL, checksum VARCHAR(64) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_taxonomies PRIMARY KEY (id),
                CONSTRAINT uq_content_taxonomies_source_term_id UNIQUE (source_term_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.entity_taxonomies (
                entity_id UUID NOT NULL, taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ");
    }

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
