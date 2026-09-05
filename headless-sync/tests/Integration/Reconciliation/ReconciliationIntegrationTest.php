<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Reconciliation;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\AggregateVersionCounter;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Events\Outbox\OutboxWriter;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
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
use HSP\Tests\Integration\Replay\FakeWpdb;
use HSP\Tests\Integration\Replay\FakeWpStore;
use HSP\Tests\Integration\Replay\ReplayReadingLoader;
use PHPUnit\Framework\TestCase;
use HSP\Tests\Support\ContentSchema;

/**
 * OPS-S3 — Reconciliation MVP (DECISION U): drift detection + WordPress-wins repair via
 * DECISION T re-emission, end-to-end on LIVE MySQL + LIVE PostgreSQL.
 *
 * Proves reconciliation repairs the delivery side ONLY by re-emission through the normal
 * pipeline (ReconciliationService → ReplayService::replayEntity → ContentReplayEmitter →
 * outbox → relay → dispatch → worker → content.*), never by a direct PG write.
 *
 * The ONLY substitution is the WordPress-read boundary (DECISION H): a headless PHPUnit
 * process cannot bootstrap WordPress, so FakeWpStore stands in for get_post/get_term and the
 * detection source (StoreReconciliationSource) reads it. Everything downstream — outbox,
 * relay, dispatch, worker, adapters, guard, PG — is the real runtime. The pending-outbox
 * suppression signal is read from the LIVE MySQL outbox table (real, not faked).
 *
 * DoD coverage:
 *   (i)   missed capture — WP updated, outbox absent → drift detected → re-emit → converges
 *   (ii)  in-flight suppression — captured-not-relayed and relayed-not-processed suppressed
 *   (iii) orphan — WP deleted, no .deleted event → full sweep → tombstone via re-emission
 *   (iv)  checksum recompute catches a category rename hourly-invisible case
 *   (v)   WordPress-wins — PG projection mutated out-of-band → repaired to WP via pipeline;
 *         ReconciliationService performs NO direct projection write (no execute() on its handle)
 */
final class ReconciliationIntegrationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_recon_';
    private string $outbox;
    private string $counters;

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
        // P1B-S2: the content query providers LEFT JOIN content.media to resolve the
        // featured image, so any test touching them needs that table plus the
        // featured_media_id column present.
        ContentSchema::ensureFeaturedMediaSupport($this->pgConn);
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
    // (i) Missed capture — WP newer than / absent from delivery → detect → re-emit
    // =========================================================================

    public function test_missed_capture_is_detected_and_repaired_through_the_pipeline(): void
    {
        // WordPress has a published post; the projection is empty (the original capture was
        // lost in the DECISION 1 post-commit gap). No outbox row exists for it.
        $this->wp->putPost(10, 'publish', 'post', 'recovered');

        $result = $this->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(1, $result->repairedCount(), 'missed capture detected + repaired');
        self::assertSame('missed_capture', $result->repaired[0]['reason']);
        self::assertSame(0, $result->suppressed);

        // Re-emission flowed through the real pipeline → projection converges.
        $this->drainPipeline();

        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 10);
        self::assertNotNull($row, 'projection reprojected via re-emission');
        self::assertSame('recovered', $row['slug']);
        self::assertNull($row['deleted_at']);
        self::assertSame(1, $this->fetchAggregateVersion('post', '10'), 'aggregate_versions advanced monotonically');
        self::assertSame(1, $this->countRows('system.processed_events'), 'guard passed → event processed');
    }

    // =========================================================================
    // (ii) In-flight suppression — do NOT re-emit for events still in the pipeline
    // =========================================================================

    public function test_captured_not_relayed_is_suppressed(): void
    {
        // WP updated. A pending (unrelayed) outbox row exists → capture is in-flight.
        $this->wp->putPost(20, 'publish', 'post', 'inflight', '2026-07-05 00:00:00');
        $this->seedProjection('content.posts', 20, 'stale', '2026-07-01 00:00:00+00');
        $this->seedPendingOutbox('post', '20');

        $result = $this->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(0, $result->repairedCount(), 'in-flight capture not re-emitted');
        self::assertSame(1, $result->suppressed);
        // No synthetic event was appended: the only outbox row is the pending one we seeded.
        self::assertSame(1, $this->countOutboxRows(), 'no synthetic re-emission for in-flight aggregate');
    }

    public function test_relayed_not_processed_is_suppressed(): void
    {
        // WP updated. A system.events row exists with version > latest_processed_version
        // (relayed, not yet worked) → in-flight on the PG side.
        $this->wp->putPost(21, 'publish', 'post', 'inflight2', '2026-07-05 00:00:00');
        $this->seedProjection('content.posts', 21, 'stale', '2026-07-01 00:00:00+00');
        $this->seedAggregateVersion('post', '21', 1);
        $this->seedHistoricalEvent('post', '21', '2026-07-05 00:00:00+00', aggregateVersion: 2);

        $result = $this->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(0, $result->repairedCount(), 'relayed-not-processed not re-emitted');
        self::assertSame(1, $result->suppressed);
    }

    // =========================================================================
    // (iii) Orphan — WP deleted with no .deleted event → full sweep tombstones via re-emit
    // =========================================================================

    public function test_orphan_is_tombstoned_via_re_emission_in_full_mode(): void
    {
        // A live projection row exists, but WordPress has no such post (deleted without a
        // .deleted event ever emitted — the missed-delete case). It is NOT in FakeWpStore.
        $this->seedProjection('content.posts', 30, 'ghost', '2026-07-01 00:00:00+00');

        // Hourly drift is WP→PG only → never sees the orphan.
        $drift = $this->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(0, $drift->repairedCount(), 'drift mode does not sweep orphans');

        // Full mode sweeps PG→WP, finds the orphan, re-emits → .deleted → tombstone.
        $full = $this->reconcile(ReconciliationService::MODE_FULL);
        self::assertSame(1, $full->repairedCount());
        self::assertSame('orphan', $full->repaired[0]['reason']);

        $this->drainPipeline();

        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 30);
        self::assertNotNull($row, 'row retained (soft delete)');
        self::assertNotNull($row['deleted_at'], 'orphan tombstoned to match WordPress reality');
    }

    // =========================================================================
    // (iv) Checksum recompute catches a category rename invisible to hourly drift
    // =========================================================================

    public function test_category_rename_is_caught_by_checksum_recompute_not_by_hourly_drift(): void
    {
        // A category exists in WP and is projected, but its NAME changed in WP without a
        // capture (terms have no modified timestamp — D2). The projection checksum is stale.
        $this->wp->putTerm(40, 'news');
        // Seed a projection row whose checksum does NOT match the current WP canonical checksum.
        $this->seedTaxonomyProjection(40, 'news', 'Old Name', str_repeat('f', 64));

        // Hourly drift: existence-only for categories → rename invisible.
        $drift = $this->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(0, $drift->repairedCount(), 'category rename invisible to hourly drift');

        // Nightly incremental: checksum recompute detects the drift and re-emits.
        $incr = $this->reconcile(ReconciliationService::MODE_INCREMENTAL);
        self::assertSame(1, $incr->repairedCount());
        self::assertSame('checksum_drift', $incr->repaired[0]['reason']);

        $this->drainPipeline();

        // Projection converges to current WP canonical state (name repaired).
        $row = $this->fetchProjectionRow('content.taxonomies', 'source_term_id', 40);
        self::assertNotNull($row);
        self::assertSame('Category 40', $row['name'], 'name reprojected from current WP state');
        self::assertNull($row['deleted_at']);
    }

    // =========================================================================
    // (v) WordPress-wins: out-of-band PG mutation repaired to WP; NO direct PG write
    // =========================================================================

    public function test_out_of_band_projection_mutation_is_repaired_to_wordpress_state(): void
    {
        // Project the post correctly first (through a real reconcile+drain).
        $this->wp->putPost(50, 'publish', 'post', 'correct-slug');
        $this->reconcile(ReconciliationService::MODE_DRIFT);
        $this->drainPipeline();
        self::assertSame('correct-slug', $this->fetchProjectionRow('content.posts', 'source_post_id', 50)['slug']);

        // Someone corrupts the projection out-of-band (slug + checksum diverge from WP).
        pg_query_params(
            $this->pgConn,
            "UPDATE content.posts SET slug = 'corrupted', checksum = $1 WHERE source_post_id = 50",
            [str_repeat('9', 64)],
        );

        // Incremental reconcile recomputes the checksum, detects divergence, and repairs
        // by RE-EMISSION — WordPress wins. The service itself performs no direct PG write.
        $spyDb = new WriteSpyConnection($this->db);
        $result = $this->reconcile(ReconciliationService::MODE_INCREMENTAL, $spyDb);

        self::assertSame(1, $result->repairedCount());
        self::assertSame('checksum_drift', $result->repaired[0]['reason']);
        self::assertSame(0, $spyDb->executeCount, 'ReconciliationService performed NO direct PG write/DML');

        $this->drainPipeline();

        self::assertSame('correct-slug', $this->fetchProjectionRow('content.posts', 'source_post_id', 50)['slug'],
            'projection repaired to current WordPress state via the pipeline');
    }

    // =========================================================================
    // Assembly
    // =========================================================================

    private function reconcile(string $mode, ?WriteSpyConnection $spy = null): \HSP\Core\Reconciliation\ReconciliationResult
    {
        $conn = $spy ?? $this->db;

        $wpdb    = new FakeWpdb($this->mysqli, $this->prefix);
        $counter = new AggregateVersionCounter($wpdb);
        $writer  = new OutboxWriter($wpdb, $counter);
        $emitter = new ContentReplayEmitter(
            new EventProvider($writer),
            new ReplayReadingLoader($this->wp),
        );
        $replay  = new ReplayService($conn, [$emitter]);

        $source  = new StoreReconciliationSource($this->wp, $this->mysqli, $this->outbox);
        $service = new ReconciliationService($conn, $source, $replay, 500);

        return $service->reconcile($mode);
    }

    private function drainPipeline(): void
    {
        (new RelayWorkerStrategy(
            new MysqliOutboxConnection(fn () => $this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        ))->tick();

        $queue = new DatabaseQueueProvider($this->db);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();

        $strategy = new EventWorkerStrategy($queue, $this->makeWiredEventRegistry(), $this->db, retryLimit: 10);
        $guard = 0;
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-0000000face1'))) {
            if (++$guard > 200) {
                self::fail('drainPipeline did not drain the queue');
            }
        }
    }

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
    // Seeding
    // =========================================================================

    private function seedProjection(string $table, int $sourceId, string $slug, string $updatedAt): void
    {
        $now = '2026-07-01 00:00:00+00';
        pg_query_params(
            $this->pgConn,
            "INSERT INTO {$table}
                 (id, source_post_id, source_entity_type, slug, title, content, excerpt,
                  status, author, published_at, updated_at, deleted_at, checksum, meta_jsonb,
                  created_at, synced_at)
             VALUES ($1::uuid, $2, 'post', $3, 'T', 'B', 'E', 'publish', 'a',
                     $4::timestamptz, $4::timestamptz, NULL, $5, '{}'::jsonb, $6::timestamptz, $6::timestamptz)",
            [$this->uuidv7(), $sourceId, $slug, $updatedAt, str_repeat('c', 64), $now],
        );
    }

    private function seedTaxonomyProjection(int $termId, string $slug, string $name, string $checksum): void
    {
        $now = '2026-07-01 00:00:00+00';
        pg_query_params(
            $this->pgConn,
            "INSERT INTO content.taxonomies
                 (id, source_term_id, taxonomy_type, slug, name, description, parent_id,
                  post_count, deleted_at, checksum, created_at, updated_at, synced_at)
             VALUES ($1::uuid, $2, 'category', $3, $4, '', 0, 0, NULL, $5, $6::timestamptz, $6::timestamptz, $6::timestamptz)",
            [$this->uuidv7(), $termId, $slug, $name, $checksum, $now],
        );
    }

    private function seedPendingOutbox(string $aggregateType, string $aggregateId): void
    {
        $this->mysqli->query(
            "INSERT INTO `{$this->outbox}`
                 (id, event_type, event_version, aggregate_type, aggregate_id, aggregate_version,
                  source_updated_at, checksum, correlation_id, causation_id, payload, status, created_at)
             VALUES ('" . $this->uuidv7() . "', 'content.{$aggregateType}.updated', 1, '{$aggregateType}', '{$aggregateId}', 5,
                     '2026-07-05 00:00:00', '" . str_repeat('a', 64) . "', '" . $this->uuidv7() . "', NULL,
                     '{}', 'pending', '2026-07-05 00:00:00')"
        );
    }

    private function seedAggregateVersion(string $type, string $id, int $version): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.aggregate_versions (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, $3, now())",
            [$type, $id, $version],
        );
    }

    private function seedHistoricalEvent(string $type, string $id, string $createdAt, int $aggregateVersion = 1): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.events
                 (id, event_type, event_version, aggregate_type, aggregate_id, payload,
                  created_at, aggregate_version, source_updated_at, checksum, correlation_id, causation_id)
             VALUES ($1::uuid, $2, 1, $3, $4, '{}'::jsonb, $5::timestamptz, $6, $5::timestamptz,
                     $7, $8::uuid, NULL)",
            [$this->uuidv7(), "content.{$type}.updated", $type, $id, $createdAt, $aggregateVersion, str_repeat('a', 64), $this->uuidv7()],
        );
    }

    // =========================================================================
    // Reads / helpers
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

    private function countOutboxRows(): int
    {
        $r = $this->mysqli->query("SELECT COUNT(*) AS c FROM `{$this->outbox}`");
        return (int) ($r->fetch_assoc()['c'] ?? 0);
    }

    private function ctx(string $workerId): WorkerExecutionContext
    {
        return new WorkerExecutionContext($workerId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    // =========================================================================
    // Schema (mirrors frozen DDL — same shapes as ReplayEngineIntegrationTest)
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
