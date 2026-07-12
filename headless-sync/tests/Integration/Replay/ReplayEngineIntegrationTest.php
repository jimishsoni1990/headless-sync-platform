<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Replay;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\AggregateVersionCounter;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Events\Outbox\OutboxWriter;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\EventProvider;
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
use HSP\Modules\Content\Replay\ContentReplayEmitter;
use HSP\Modules\Content\Subscribers\ContentSubscriber;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Modules\Content\WpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * OPS-S2 — Replay Engine (DECISION T): entity + date-range replay end-to-end on
 * LIVE MySQL + LIVE PostgreSQL.
 *
 * Proves replay is projection repair via synthetic re-emission:
 *   ReplayService → ContentReplayEmitter (reads current WP state, decides .updated/.deleted)
 *   → real OutboxWriter (fresh aggregate_version from wp_hsp_aggregate_counters, DECISION 2)
 *   → wp_hsp_outbox → RelayWorkerStrategy → system.events → EventDispatcher → queue
 *   → EventWorkerStrategy (DECISION J stale guard) → content.* projection.
 *
 * The ONLY substitution is the WordPress state reload boundary (DECISION H): a headless
 * PHPUnit process cannot bootstrap WordPress, so a controllable in-memory WP store stands
 * in for get_post()/get_term(). Everything downstream — counter, outbox, relay, dispatch,
 * worker, adapters, guard, PG — is the real runtime.
 *
 * DoD coverage:
 *   1. entity replay reprojects to correct final state (incl. deleted-entity → tombstone)
 *   2. date-range replay: all affected aggregates reproject; boundary-spanning + a
 *      since-deleted aggregate covered
 *   3. both modes idempotent: replay twice → identical final state, no duplicate rows,
 *      versions strictly advance (GREATEST guard holds)
 *   4. synthetic events pass THROUGH the DECISION J guard (new version > stored; a
 *      processed_events row is present)
 *   5. traceability: one correlation_id per run; causation_id present
 */
final class ReplayEngineIntegrationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_replay_';
    private string $outbox;
    private string $counters;

    /** In-memory WordPress store the emitter reads (stands in for get_post/get_term). */
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
    // DoD 1 — Entity replay reprojects to correct final state
    // =========================================================================

    public function test_entity_replay_reprojects_a_published_post_to_correct_final_state(): void
    {
        // Current WordPress state: post 10 is published. The projection is empty (as if the
        // original sync was lost). Replaying the entity must reproject it.
        $this->wp->putPost(10, 'publish', 'post', 'hello-world');

        $result = $this->replayStrategy()->replayEntity('post', '10');

        self::assertSame(1, $result->count());
        self::assertSame('content.post.updated', $result->emitted[0]['event_type']);

        $this->drainPipeline();

        self::assertSame(1, $this->countRows('content.posts'), 'post reprojected exactly once');
        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 10);
        self::assertNotNull($row);
        self::assertSame('hello-world', $row['slug'], 'current WP slug reprojected');
        self::assertNull($row['deleted_at'], 'live post is not tombstoned');

        // DoD 4 — the synthetic event passed THROUGH the DECISION J guard: a processed_events
        // row exists and the aggregate version is recorded (guard not bypassed).
        self::assertSame(1, $this->countRows('system.processed_events'), 'guard passed → event processed');
        self::assertSame(1, $this->fetchAggregateVersion('post', '10'), 'aggregate version recorded');
    }

    public function test_entity_replay_of_a_deleted_entity_tombstones_the_projection(): void
    {
        // A published post was previously synced; the projection has a live row.
        $this->wp->putPost(20, 'publish', 'post', 'doomed');
        $this->replayStrategy()->replayEntity('post', '20');
        $this->drainPipeline();
        self::assertNull($this->fetchProjectionRow('content.posts', 'source_post_id', 20)['deleted_at']);

        // The post is deleted in WordPress during an outage window. Replay reads CURRENT
        // (absent) state → emits .deleted → tombstones the projection (DECISION T point 2).
        $this->wp->deletePost(20);

        $result = $this->replayStrategy()->replayEntity('post', '20');
        self::assertSame('content.post.deleted', $result->emitted[0]['event_type']);

        $this->drainPipeline();

        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 20);
        self::assertNotNull($row, 'row retained (soft delete)');
        self::assertNotNull($row['deleted_at'], 'projection tombstoned to match current WP reality');
    }

    // =========================================================================
    // DoD 2 — Date-range replay reprojects all affected aggregates
    // =========================================================================

    public function test_date_range_replay_reprojects_all_in_window_including_boundary_and_deleted(): void
    {
        // Seed system.events history across a window with three distinct aggregates:
        //   post 1   — event squarely inside the window
        //   page 2   — event ON the 'from' boundary (half-open [from,to): included)
        //   post 3   — event inside the window, but the post is DELETED in WP since then
        // Plus an out-of-window post 4 (before 'from') that must NOT be replayed.
        $from = new \DateTimeImmutable('2026-07-10T00:00:00Z');
        $to   = new \DateTimeImmutable('2026-07-11T00:00:00Z');

        $this->seedHistoricalEvent('post', '1', '2026-07-10 12:00:00+00');
        $this->seedHistoricalEvent('page', '2', '2026-07-10 00:00:00+00'); // on 'from' boundary
        $this->seedHistoricalEvent('post', '3', '2026-07-10 18:00:00+00');
        $this->seedHistoricalEvent('post', '4', '2026-07-09 23:59:59+00'); // before window — excluded

        // Current WordPress state: 1 and 2 are public; 3 was deleted; 4 is public (but out of window).
        $this->wp->putPost(1, 'publish', 'post', 'post-one');
        $this->wp->putPost(2, 'publish', 'page', 'page-two');
        // post 3 intentionally absent from WP → tombstone
        $this->wp->putPost(4, 'publish', 'post', 'post-four');

        $result = $this->replayStrategy()->replayRange($from, $to);

        // Exactly three distinct in-window aggregates discovered; post 4 excluded.
        self::assertSame(3, $result->count(), 'three in-window aggregates replayed');
        $ids = array_map(fn ($e) => $e['aggregate_type'] . ':' . $e['aggregate_id'], $result->emitted);
        sort($ids);
        self::assertSame(['page:2', 'post:1', 'post:3'], $ids, 'boundary included, out-of-window excluded');

        $this->drainPipeline();

        // 1 and 2 reproject live; 3 is not live (deleted in WP → .deleted); 4 untouched.
        self::assertSame('post-one', $this->fetchProjectionRow('content.posts', 'source_post_id', 1)['slug']);
        self::assertSame('page-two', $this->fetchProjectionRow('content.pages', 'source_post_id', 2)['slug']);
        // Post 3 was never projected before this run; a .deleted for a non-existent projection
        // row is a tombstone no-op (DECISION I) — the meaningful invariant is that post 3 is
        // NOT present as a live (non-deleted) row. Its stale/absent state matches current WP.
        $post3 = $this->fetchProjectionRow('content.posts', 'source_post_id', 3);
        self::assertTrue($post3 === null || $post3['deleted_at'] !== null, 'post 3 is not a live projection row');
        // The .deleted synthetic event was still processed (guard passed, tombstone recorded).
        self::assertSame(1, $this->fetchAggregateVersion('post', '3'), 'post 3 tombstone event processed');
        self::assertNull($this->fetchProjectionRow('content.posts', 'source_post_id', 4), 'out-of-window post 4 not reprojected');

        // Traceability (DoD 5): one correlation_id groups the whole run.
        self::assertNotSame('', $result->correlationId);
        self::assertNotSame('', $result->causationId);
        $this->assertOneCorrelationIdForRun($result->correlationId, 3);
    }

    // =========================================================================
    // DoD 3 — Both modes idempotent; versions strictly advance (GREATEST guard holds)
    // =========================================================================

    public function test_entity_replay_twice_is_idempotent_and_advances_version(): void
    {
        $this->wp->putPost(30, 'publish', 'post', 'idem');

        $r1 = $this->replayStrategy()->replayEntity('post', '30');
        $this->drainPipeline();
        $v1 = $this->fetchAggregateVersion('post', '30');

        $r2 = $this->replayStrategy()->replayEntity('post', '30');
        $this->drainPipeline();
        $v2 = $this->fetchAggregateVersion('post', '30');

        // No duplicate projection rows (upsert on source_post_id).
        self::assertSame(1, $this->countRows('content.posts'), 'still exactly one projection row');
        // Versions strictly advance via the counter — no regression (GREATEST guard holds).
        self::assertGreaterThan($v1, $v2, 'aggregate version strictly advanced on second replay');
        // Distinct synthetic events each run.
        self::assertNotSame($r1->emitted[0]['event_id'], $r2->emitted[0]['event_id']);

        // Final projection state identical after both runs (same current WP state).
        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 30);
        self::assertSame('idem', $row['slug']);
    }

    // =========================================================================
    // DoD 4 — Synthetic event passes THROUGH the DECISION J guard (not bypassed)
    // =========================================================================

    public function test_synthetic_event_version_exceeds_stored_and_passes_the_guard(): void
    {
        $this->wp->putPost(40, 'publish', 'post', 'guarded');

        // First replay establishes latest_processed_version.
        $this->replayStrategy()->replayEntity('post', '40');
        $this->drainPipeline();
        $stored = $this->fetchAggregateVersion('post', '40');

        // Second replay must emit a version strictly greater than the stored one, so the
        // Resolve-stage guard (version <= stored → ack, zero writes) does NOT suppress it.
        $result = $this->replayStrategy()->replayEntity('post', '40');
        $emittedVersion = $result->emitted[0]['aggregate_version'];
        self::assertGreaterThan($stored, $emittedVersion, 'synthetic version > stored → guard passes naturally');

        $this->drainPipeline();

        // Guard passed → the second event is in processed_events and the version advanced.
        self::assertSame(2, $this->countRows('system.processed_events'), 'both synthetic events processed (guard not suppressing)');
        self::assertSame($emittedVersion, $this->fetchAggregateVersion('post', '40'), 'stored version advanced to the new synthetic version');
    }

    public function test_historical_system_events_are_never_mutated_by_replay(): void
    {
        // Seed a historical event and record its identity.
        $this->seedHistoricalEvent('post', '50', '2026-07-10 12:00:00+00');
        $before = pg_fetch_all(pg_query($this->pgConn, 'SELECT id, aggregate_version FROM system.events ORDER BY id'));

        $this->wp->putPost(50, 'publish', 'post', 'immutable');
        $this->replayStrategy()->replayEntity('post', '50');

        // Replay APPENDS a new outbox row → after relay, a NEW system.events row exists;
        // the historical row is untouched (same id, same aggregate_version).
        $this->relayOnly();
        $historical = pg_fetch_assoc(pg_query_params(
            $this->pgConn,
            'SELECT aggregate_version FROM system.events WHERE id = $1::uuid',
            [$before[0]['id']],
        ));
        self::assertSame($before[0]['aggregate_version'], $historical['aggregate_version'], 'historical event row unchanged');
        self::assertSame(2, $this->countRows('system.events'), 'replay appended a new event, did not rewrite');
    }

    // =========================================================================
    // Replay front-end assembly (real emit path)
    // =========================================================================

    private function replayStrategy(): ReplayWorkerStrategy
    {
        $wpdb    = new FakeWpdb($this->mysqli, $this->prefix);
        $counter = new AggregateVersionCounter($wpdb);
        $writer  = new OutboxWriter($wpdb, $counter);
        $emitter = new ContentReplayEmitter(
            new EventProvider($writer),
            new ReplayReadingLoader($this->wp),
        );

        return new ReplayWorkerStrategy(new ReplayService($this->db, [$emitter]));
    }

    // =========================================================================
    // Downstream pipeline (real relay → dispatch → worker)
    // =========================================================================

    private function drainPipeline(): void
    {
        $this->relayOnly();

        $queue = new DatabaseQueueProvider($this->db);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();

        $strategy = new EventWorkerStrategy($queue, $this->makeWiredEventRegistry(), $this->db, retryLimit: 10);
        $guard = 0;
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-00000000face'))) {
            if (++$guard > 200) {
                self::fail('drainPipeline did not drain the queue');
            }
        }
    }

    private function relayOnly(): void
    {
        (new RelayWorkerStrategy(
            new MysqliOutboxConnection($this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        ))->tick();
    }

    /** Wire the real EventRegistry → ContentSubscriber → all 9 content handlers. */
    private function makeWiredEventRegistry(): EventRegistry
    {
        $pageLoader = new ReplayReadingLoader($this->wp, 'page');
        $postLoader = new ReplayReadingLoader($this->wp, 'post');
        $termLoader = new ReplayReadingLoader($this->wp, 'post');

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

    // =========================================================================
    // system.events history seeding (date-range discovery source)
    // =========================================================================

    private function seedHistoricalEvent(string $aggregateType, string $aggregateId, string $createdAt): string
    {
        $id  = $this->uuidv7();
        $eventType = "content.{$aggregateType}." . ($aggregateType === 'category' ? 'created' : 'created');
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.events
                 (id, event_type, event_version, aggregate_type, aggregate_id, payload,
                  created_at, aggregate_version, source_updated_at, checksum, correlation_id, causation_id)
             VALUES ($1::uuid, $2, 1, $3, $4, '{}'::jsonb, $5::timestamptz, 1, $5::timestamptz,
                     $6, $7::uuid, NULL)",
            [$id, $eventType, $aggregateType, $aggregateId, $createdAt, str_repeat('a', 64), $this->uuidv7()],
        );

        // These historical events were already dispatched+processed in production. Mark them
        // dispatched (a retained completed queue_jobs row) so the dispatcher's NOT EXISTS
        // anti-join skips them — otherwise this test harness would re-dispatch raw history.
        // This models the real invariant (DECISION L(d): completed rows are retained).
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.queue_jobs
                 (id, event_id, queue_name, status, attempts, available_at, completed_at)
             VALUES ($1::uuid, $2::uuid, 'content', 'completed', 1, $3::timestamptz, $3::timestamptz)",
            [$this->uuidv7(), $id, $createdAt],
        );

        return $id;
    }

    private function assertOneCorrelationIdForRun(string $correlationId, int $expectedNewEvents): void
    {
        // Every synthetic event relayed for this run shares the same correlation_id.
        $r = pg_query_params(
            $this->pgConn,
            'SELECT COUNT(*) AS c FROM system.events WHERE correlation_id = $1::uuid',
            [$correlationId],
        );
        self::assertSame($expectedNewEvents, (int) (pg_fetch_assoc($r)['c'] ?? 0));
    }

    // =========================================================================
    // Reads
    // =========================================================================

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
        return new WorkerExecutionContext($workerId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    // =========================================================================
    // Schema (mirrors frozen DDL — same shapes as ReliabilityValidationTest)
    // =========================================================================

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
