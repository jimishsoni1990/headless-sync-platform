<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Gate;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Queue\DeadLetterRepository;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
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
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * GATE-S1 — Architecture Validation Gate: Reliability Validation.
 *
 * IMPLEMENTATION_PLAN.md §4 → Reliability Validation criteria (verbatim):
 *   1. Successful sync processing under normal load
 *   2. Replay succeeds for single event, entity, and date-range replay modes
 *   3. DLQ recovery: failed job replays to correct final state
 *
 * This is a GATE session: evidence only, no production code changes. Each satisfiable
 * criterion is proven end-to-end against LIVE MySQL + LIVE PostgreSQL by assembling the
 * real runtime components (RelayWorkerStrategy → EventDispatcher → EventWorkerStrategy →
 * ContentSubscriber → content.* adapters). No fakes on the pipeline itself — the only
 * substitution is FakeWpContentLoader, which stands in for the WordPress state-reload
 * boundary (DECISION H) that a headless PHPUnit process cannot bootstrap.
 *
 * Criterion 2 (entity + date-range replay) is a STOP-and-flag: OPS-S1 shipped
 * single-event replay ONLY (verified: ReplayWorkerStrategy is a stub; DeadLetterRepository
 * ::replay() and `hsp dlq replay` accept a single DLQ id / event_id; no entity or
 * date-range replay mode exists in core/, modules/, or tools/). Per the gate brief and
 * CLAUDE.md freeze rule, replay features are NOT built in a gate session. This test proves
 * the single-event mode that DOES exist and documents the gap; the FAIL is recorded in
 * STATUS.md as FLAG-GATES1-1.
 *
 * Environment (self-skips if a DB is genuinely absent):
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class ReliabilityValidationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_gate_';
    private string $outbox;

    protected function setUp(): void
    {
        $this->outbox = $this->prefix . 'hsp_outbox';

        $this->mysqli = $this->connectMysql();
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);

        $this->createMysqlSchema();
        $this->createPgsqlSchema();
    }

    protected function tearDown(): void
    {
        if ($this->mysqli !== null) {
            $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
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
    // Criterion 1 — Successful sync processing under normal load
    //   Full pipeline: WP edit (outbox) → relay → dispatch → worker → projection.
    // =========================================================================

    public function test_criterion1_full_pipeline_syncs_a_batch_end_to_end_under_normal_load(): void
    {
        // "Normal load": a realistic mixed batch of content edits captured in the outbox.
        // 6 posts + 4 pages + 2 categories = 12 aggregates, each version 1 (create).
        $expected = [];
        for ($i = 1; $i <= 6; $i++) {
            $eid = $this->insertOutboxRow(ContentEventTypes::POST_CREATED, 'post', (string) $i, 1);
            $expected['post'][$i] = $eid;
        }
        for ($i = 1; $i <= 4; $i++) {
            $eid = $this->insertOutboxRow(ContentEventTypes::PAGE_CREATED, 'page', (string) $i, 1);
            $expected['page'][$i] = $eid;
        }
        for ($i = 1; $i <= 2; $i++) {
            $eid = $this->insertOutboxRow(ContentEventTypes::CATEGORY_CREATED, 'category', (string) $i, 1);
            $expected['category'][$i] = $eid;
        }

        // ---- Stage 1: Relay (MySQL wp_hsp_outbox → PostgreSQL system.events) ----
        $relay = new RelayWorkerStrategy(
            new MysqliOutboxConnection($this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        );
        self::assertTrue($relay->tick(), 'relay tick processed the outbox batch');
        self::assertSame(12, $this->countRows('system.events'), 'all 12 outbox rows relayed to system.events');
        self::assertSame(0, $this->pendingOutboxCount(), 'no outbox rows left pending after relay');

        // ---- Stage 2: Dispatch (system.events → system.queue_jobs) ----
        $queue      = new DatabaseQueueProvider($this->db);
        $dispatcher = new EventDispatcher($this->db, $queue, 100);
        $batch      = $dispatcher->dispatchBatch();
        self::assertSame(12, $batch->count(), 'dispatcher enqueued all 12 events');
        self::assertSame(12, $this->countRows('system.queue_jobs'), '12 queue jobs created');

        // ---- Stage 3 + 4: Worker claims each job → ContentSubscriber → adapter → projection ----
        $registry = $this->makeWiredEventRegistry();
        $strategy = new EventWorkerStrategy($queue, $registry, $this->db, retryLimit: 10);

        // Drain the queue: one execute() per available job (the WorkerEngine loop, unrolled).
        $processed = 0;
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-00000000c0de'))) {
            if (++$processed > 50) {
                self::fail('worker did not drain the queue — possible infinite loop');
            }
        }
        self::assertSame(12, $processed, 'worker processed exactly 12 jobs');

        // ---- Final projection state is correct and complete ----
        self::assertSame(6, $this->countRows('content.posts'),      'all 6 posts projected');
        self::assertSame(4, $this->countRows('content.pages'),      'all 4 pages projected');
        self::assertSame(2, $this->countRows('content.taxonomies'), 'both categories projected');

        // Every job acked as completed; nothing dead-lettered.
        self::assertSame(12, $this->countJobsWithStatus('completed'), 'all jobs completed');
        self::assertSame(0, $this->countRows('system.dead_letter_jobs'), 'no dead letters on the happy path');

        // aggregate_versions + processed_events recorded for every aggregate (DECISION 3).
        self::assertSame(12, $this->countRows('system.aggregate_versions'), 'a version row per aggregate');
        self::assertSame(12, $this->countRows('system.processed_events'),   'a processed_events row per event');

        // Spot-check that a known post landed with the expected identity (round-trip fidelity).
        $post3 = $this->fetchProjectionRow('content.posts', 'source_post_id', 3);
        self::assertNotNull($post3, 'post 3 present in projection');
        self::assertSame('test-post-3', $post3['slug'], 'post 3 slug round-tripped through the full pipeline');
    }

    public function test_criterion1_update_event_reprojects_the_aggregate_end_to_end(): void
    {
        // Create then update the same post through the full pipeline. The worker reloads
        // current WP state per event (ADR-044 / DECISION H), so the projected slug comes
        // from the loader, not the outbox payload — both events project the same aggregate.
        // The reliability signal here is that a second (higher-version) event re-runs the
        // full pipeline cleanly and advances the aggregate version without duplicating rows.
        $create = $this->insertOutboxRow(ContentEventTypes::POST_CREATED, 'post', '77', 1);
        $this->runFullPipelineOnce();

        self::assertSame(1, $this->countRows('content.posts'), 'one post row after create');
        self::assertSame(1, $this->fetchAggregateVersion('post', '77'), 'aggregate at version 1');

        $update = $this->insertOutboxRow(ContentEventTypes::POST_UPDATED, 'post', '77', 2);
        $this->runFullPipelineOnce();

        self::assertSame(1, $this->countRows('content.posts'), 'still one post row (idempotent upsert on source_post_id)');
        self::assertSame(2, $this->fetchAggregateVersion('post', '77'), 'aggregate advanced to version 2');
        self::assertSame(2, $this->countRows('system.processed_events'), 'both events recorded');
        self::assertNotSame($create, $update, 'distinct events');
    }

    // =========================================================================
    // Criterion 3 — DLQ recovery: failed job replays to correct final state
    //   A job exhausts the ADR-022 retry limit → lands in system.dead_letter_jobs
    //   with full OPEN-3 context → `hsp dlq replay` (DeadLetterRepository::replay)
    //   reprocesses it to the correct final projection state and stamps replayed_at.
    // =========================================================================

    public function test_criterion3_exhausted_job_dead_letters_then_replays_to_correct_final_state(): void
    {
        // Relay + dispatch a single post-create event through the real front of the pipeline.
        $eventId = $this->insertOutboxRow(ContentEventTypes::POST_CREATED, 'post', '500', 1);

        $relay = new RelayWorkerStrategy(
            new MysqliOutboxConnection($this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        );
        $relay->tick();

        $queue = new DatabaseQueueProvider($this->db, ['retry_limit' => 3]);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();
        self::assertSame(1, $this->countRows('system.queue_jobs'));

        // Force the job to the retry limit so the next failure is terminal.
        $this->forceJobAttempts($eventId, 3);

        // A registry whose handler always throws → terminal failure on this attempt.
        $failing = new EventRegistry();
        $failing->register(ContentEventTypes::POST_CREATED, function (): void {
            throw new \RuntimeException('projection boom (forced for DLQ proof)');
        });
        $failStrategy = new EventWorkerStrategy($queue, $failing, $this->db, retryLimit: 3);

        self::assertTrue($failStrategy->execute($this->ctx('01900000-0000-7000-8000-0000000dead1')));

        // The job dead-lettered with full OPEN-3 context; no projection written.
        self::assertSame(1, $this->countRows('system.dead_letter_jobs'), 'exactly one DLQ row');
        $dlq = $this->fetchDlqRow($eventId);
        self::assertSame($eventId, $dlq['event_id']);
        self::assertStringContainsString('projection boom', $dlq['failure_reason']);
        self::assertNotSame('', (string) $dlq['stack_trace'], 'stack_trace captured (OPEN-3)');
        self::assertNotNull($dlq['payload_snapshot'], 'payload_snapshot captured (OPEN-3 / DECISION A)');
        self::assertNull($dlq['replayed_at'], 'not yet replayed');
        self::assertSame(0, $this->countRows('content.posts'), 'no projection row while the job is dead-lettered');
        self::assertSame('dead_lettered', $this->jobStatus($eventId), 'queue row retained as dead_lettered (DECISION L(d))');

        // ---- Replay via the DECISION S lifecycle (hsp dlq replay uses DeadLetterRepository) ----
        $repo  = new DeadLetterRepository($this->db);
        $dlqId = (string) $dlq['id'];
        self::assertSame($eventId, $repo->replay($dlqId), 'replay re-enqueues the original event_id');

        // A fresh, claimable job now exists (attempts reset to 0). DLQ row preserved + stamped.
        self::assertNotNull($this->dlqReplayedAt($dlqId), 'replayed_at stamped (DECISION S)');
        self::assertSame(1, $this->countRows('system.dead_letter_jobs'), 'DLQ row is permanent audit — not deleted');

        // ---- Drive the replayed job to its correct final projection state with a HEALTHY worker ----
        $healthy = $this->makeWiredEventRegistry();
        $ok      = new EventWorkerStrategy($queue, $healthy, $this->db, retryLimit: 3);
        self::assertTrue($ok->execute($this->ctx('01900000-0000-7000-8000-0000000dead2')), 'replayed job claimed + processed');

        // Correct final state: the projection now exists and matches the source.
        self::assertSame(1, $this->countRows('content.posts'), 'replay produced the correct final projection state');
        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 500);
        self::assertSame('test-post-500', $row['slug'], 'projected slug matches the replayed event');
        self::assertSame(1, $this->fetchAggregateVersion('post', '500'), 'aggregate version recorded');
        self::assertSame('completed', $this->jobStatus($eventId), 'replayed job acked as completed');
    }

    // =========================================================================
    // Criterion 2 — Replay: single event, entity, AND date-range modes.
    //   STOP-and-flag guard. Proves single-event replay exists; asserts entity and
    //   date-range modes are ABSENT (so this test fails loudly if someone silently
    //   builds them in a gate session, or if the gap is closed in a proper session
    //   without updating the gate record). Recorded as FLAG-GATES1-1 in STATUS.md.
    // =========================================================================

    public function test_criterion2_only_single_event_replay_is_implemented(): void
    {
        // Single-event replay DOES exist and is proven by criterion 3 above via
        // DeadLetterRepository::replay(string $dlqId). Confirm its shape here.
        $repo = new \ReflectionClass(DeadLetterRepository::class);
        self::assertTrue($repo->hasMethod('replay'), 'single-event replay entry point exists');
        $params = $repo->getMethod('replay')->getParameters();
        self::assertCount(1, $params, 'replay() takes exactly one argument (a single DLQ id) — single-event mode');
        self::assertSame('dlqId', $params[0]->getName(), 'replay() is keyed by a single DLQ row id');

        // Entity replay and date-range replay are NOT implemented anywhere. There is no
        // replayEntity()/replayDateRange() method, and ReplayWorkerStrategy is a stub.
        self::assertFalse($repo->hasMethod('replayEntity'), 'entity replay is NOT implemented (STOP-and-flag)');
        self::assertFalse($repo->hasMethod('replayDateRange'), 'date-range replay is NOT implemented (STOP-and-flag)');

        self::markTestIncomplete(
            'GATE-S1 criterion 2 FAILS: entity and date-range replay modes are not implemented. '
            . 'OPS-S1 shipped single-event replay only (ReplayWorkerStrategy is a stub; '
            . 'DeadLetterRepository::replay() and `hsp dlq replay` are single-DLQ-id only). '
            . 'Per the gate brief and CLAUDE.md freeze rule, replay features are NOT built in a '
            . 'gate session. Recorded as FLAG-GATES1-1; gate cannot pass until an authorized '
            . 'session implements entity + date-range replay (Doc 4 §24).'
        );
    }

    // =========================================================================
    // Pipeline assembly helpers
    // =========================================================================

    /** Wire the real EventRegistry → ContentSubscriber → all 9 content handlers. */
    private function makeWiredEventRegistry(): EventRegistry
    {
        // Type-specific loaders: PageValidator/PostValidator each require a matching
        // post_type, and the loader only knows the id — so the page handler gets a
        // page-typed loader and the post handler a post-typed one (as in HandlerSpineIntegrationTest).
        $pageLoader = new GateReloadingLoader('page');
        $postLoader = new GateReloadingLoader('post');
        $termLoader = new GateReloadingLoader('post'); // post_type irrelevant for terms

        $pageAdapter     = new PageAdapter($this->db);
        $postAdapter     = new PostAdapter($this->db);
        $categoryAdapter = new CategoryAdapter($this->db);

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

    /** Run relay → dispatch → drain-queue once over whatever is currently pending. */
    private function runFullPipelineOnce(): void
    {
        (new RelayWorkerStrategy(
            new MysqliOutboxConnection($this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        ))->tick();

        $queue = new DatabaseQueueProvider($this->db);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();

        $strategy = new EventWorkerStrategy($queue, $this->makeWiredEventRegistry(), $this->db, retryLimit: 10);
        $guard = 0;
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-00000000beef'))) {
            if (++$guard > 100) {
                self::fail('runFullPipelineOnce did not drain the queue');
            }
        }
    }

    // =========================================================================
    // Outbox (MySQL) seeding
    // =========================================================================

    private function insertOutboxRow(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
        ?string $slugOverride = null,
    ): string {
        $id            = $this->uuidv7();
        $correlationId = $this->uuidv7();
        $now           = gmdate('Y-m-d H:i:s');
        $slug          = $slugOverride ?? "test-{$aggregateType}-{$aggregateId}";
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

    private function pendingOutboxCount(): int
    {
        $r = $this->mysqli->query("SELECT COUNT(*) AS c FROM `{$this->outbox}` WHERE `status` = 'pending'");
        return (int) ($r->fetch_assoc()['c'] ?? 0);
    }

    // =========================================================================
    // Queue / DLQ manipulation + reads (PostgreSQL)
    // =========================================================================

    private function forceJobAttempts(string $eventId, int $attempts): void
    {
        pg_query_params(
            $this->pgConn,
            'UPDATE system.queue_jobs SET attempts = $2 WHERE event_id = $1::uuid',
            [$eventId, $attempts],
        );
    }

    private function jobStatus(string $eventId): ?string
    {
        $r = pg_query_params(
            $this->pgConn,
            'SELECT status FROM system.queue_jobs WHERE event_id = $1::uuid ORDER BY available_at DESC LIMIT 1',
            [$eventId],
        );
        return (pg_fetch_assoc($r) ?: [])['status'] ?? null;
    }

    private function countJobsWithStatus(string $status): int
    {
        $r = pg_query_params($this->pgConn, 'SELECT COUNT(*) AS c FROM system.queue_jobs WHERE status = $1', [$status]);
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    /** @return array<string,mixed> */
    private function fetchDlqRow(string $eventId): array
    {
        $r = pg_query_params(
            $this->pgConn,
            'SELECT id, event_id, failure_reason, stack_trace, attempt_count, payload_snapshot, replayed_at
             FROM system.dead_letter_jobs WHERE event_id = $1::uuid',
            [$eventId],
        );
        return pg_fetch_assoc($r) ?: [];
    }

    private function dlqReplayedAt(string $dlqId): ?string
    {
        $r = pg_query_params($this->pgConn, 'SELECT replayed_at FROM system.dead_letter_jobs WHERE id = $1::uuid', [$dlqId]);
        return (pg_fetch_assoc($r) ?: [])['replayed_at'] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function fetchProjectionRow(string $table, string $keyCol, int $keyVal): ?array
    {
        $r = pg_query_params($this->pgConn, "SELECT * FROM {$table} WHERE {$keyCol} = \$1", [$keyVal]);
        return pg_fetch_assoc($r) ?: null;
    }

    private function fetchAggregateVersion(string $aggType, string $aggId): int
    {
        $r = pg_query_params(
            $this->pgConn,
            'SELECT latest_processed_version FROM system.aggregate_versions WHERE aggregate_type = $1 AND aggregate_id = $2',
            [$aggType, $aggId],
        );
        return (int) ((pg_fetch_assoc($r) ?: [])['latest_processed_version'] ?? 0);
    }

    private function countRows(string $table): int
    {
        $r = pg_query($this->pgConn, "SELECT COUNT(*) AS c FROM {$table}");
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    private function ctx(string $workerId): WorkerExecutionContext
    {
        return new WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // =========================================================================
    // Schema (mirrors the frozen DDL — same shapes as RelayEndToEndTest +
    // HandlerSpineIntegrationTest + OperationalBaselineIntegrationTest).
    // =========================================================================

    private function createMysqlSchema(): void
    {
        $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
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
            CREATE TABLE content.pages (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT 'page',
                slug               VARCHAR(255) NOT NULL,
                title              TEXT         NOT NULL,
                content            TEXT         NOT NULL,
                status             VARCHAR(50)  NOT NULL,
                parent_id          BIGINT       NOT NULL DEFAULT 0,
                menu_order         INTEGER      NOT NULL DEFAULT 0,
                published_at       TIMESTAMPTZ  NOT NULL,
                updated_at         TIMESTAMPTZ  NOT NULL,
                deleted_at         TIMESTAMPTZ  NULL,
                checksum           VARCHAR(64)  NOT NULL,
                meta_jsonb         JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_pages PRIMARY KEY (id),
                CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.posts (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT 'post',
                slug               VARCHAR(255) NOT NULL,
                title              TEXT         NOT NULL,
                content            TEXT         NOT NULL,
                excerpt            TEXT         NOT NULL,
                status             VARCHAR(50)  NOT NULL,
                author             VARCHAR(255) NOT NULL,
                published_at       TIMESTAMPTZ  NOT NULL,
                updated_at         TIMESTAMPTZ  NOT NULL,
                deleted_at         TIMESTAMPTZ  NULL,
                checksum           VARCHAR(64)  NOT NULL,
                meta_jsonb         JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_posts PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.taxonomies (
                id              UUID         NOT NULL,
                source_term_id  BIGINT       NOT NULL,
                taxonomy_type   VARCHAR(50)  NOT NULL,
                slug            VARCHAR(255) NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT         NOT NULL DEFAULT '',
                parent_id       BIGINT       NOT NULL DEFAULT 0,
                post_count      INTEGER      NOT NULL DEFAULT 0,
                deleted_at      TIMESTAMPTZ  NULL,
                checksum        VARCHAR(64)  NOT NULL,
                created_at      TIMESTAMPTZ  NOT NULL,
                updated_at      TIMESTAMPTZ  NOT NULL,
                synced_at       TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_taxonomies PRIMARY KEY (id),
                CONSTRAINT uq_content_taxonomies_source_term_id UNIQUE (source_term_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.entity_taxonomies (
                entity_id   UUID NOT NULL,
                taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ");
    }

    // =========================================================================
    // Connections
    // =========================================================================

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

/**
 * A WpContentLoader that reconstructs a deterministic source row for whatever post/term id
 * the handler asks for — echoing the id back through post_name / slug so a projection
 * round-trip is verifiable (e.g. post 3 → slug 'test-post-3'). This stands in for the real
 * WordPress state reload (DECISION H) that a headless PHPUnit process cannot bootstrap; the
 * rest of the pipeline (relay, dispatch, worker, adapters, PostgreSQL) is the real thing.
 */
final class GateReloadingLoader extends FakeWpContentLoader
{
    public function __construct(private readonly string $postType = 'post') {}

    public function loadPost(int $postId): ?array
    {
        // Echo the requested id back through post_name so a projection round-trip is
        // verifiable (post 3 → slug 'test-post-3'). post_type matches the handler that
        // invoked us (PageValidator/PostValidator each require a matching type).
        return [
            'ID'                => $postId,
            'post_title'        => "Title {$postId}",
            'post_content'      => '<p>Body</p>',
            'post_excerpt'      => 'Excerpt',
            'post_name'         => "test-{$this->postType}-{$postId}",
            'post_status'       => 'publish',
            'post_type'         => $this->postType,
            'post_author'       => '1',
            'post_date_gmt'     => '2024-01-01 00:00:00',
            'post_modified_gmt' => '2024-06-01 00:00:00',
            'post_parent'       => '0',
            'menu_order'        => '0',
        ];
    }

    public function loadPostMeta(int $postId): array
    {
        return [];
    }

    public function loadPostCategoryIds(int $postId): array
    {
        return [];
    }

    public function loadTerm(int $termId): ?array
    {
        return [
            'term_id'     => $termId,
            'name'        => "Category {$termId}",
            'slug'        => "test-category-{$termId}",
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        ];
    }
}
