<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Onboarding;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\AggregateVersionCounter;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Events\Outbox\OutboxWriter;
use HSP\Core\Onboarding\Backfill\BackfillGate;
use HSP\Core\Workers\ProcessingCronRegistrar;
use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Onboarding\Backfill\BackfillService;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
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
use HSP\Tests\Integration\Reconciliation\StoreReconciliationSource;
use HSP\Tests\Integration\Reconciliation\WriteSpyConnection;
use HSP\Tests\Integration\Replay\FakeWpdb;
use HSP\Tests\Integration\Replay\FakeWpStore;
use HSP\Tests\Integration\Replay\ReplayReadingLoader;
use PHPUnit\Framework\TestCase;

/**
 * ONB-S2 — First-run backfill on LIVE MySQL + LIVE PostgreSQL (DECISION W (b)/(c)/(d)).
 *
 * Proves the onboarding backfill triggers a full-reconciliation re-emission through the normal
 * pipeline (BackfillService → ReconciliationService::reconcileFull() → ReplayService → outbox →
 * relay → dispatch → worker → content.*), with:
 *   - a WRITE-SPY proving ZERO direct `content.*` / `system.*` writes on the backfill path
 *     (DECISION W (b) / DECISION V (d) — mirrors GATE-S3);
 *   - all in-scope WordPress content projected to the delivery tables (WordPress-wins by
 *     construction, Rule 1);
 *   - derived-on-demand progress converging (expected == projected, zero in-flight) — DECISION Q;
 *   - the worker-heartbeat HARD PREREQUISITE blocking when no live worker exists and unblocking
 *     once a fresh heartbeat row is present (DECISION P / DECISION W (c)); no lifecycle action.
 *
 * The ONLY substitution is the WordPress-read boundary (DECISION H): a headless PHPUnit process
 * cannot bootstrap WordPress, so FakeWpStore stands in and StoreReconciliationSource reads it.
 * Everything downstream is the real runtime; heartbeat + migration reads hit LIVE PostgreSQL.
 */
final class BackfillIntegrationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_onbbackfill_';
    private string $outbox;
    private string $counters;

    private FakeWpStore $wp;

    private const REQUIRED_MIGRATIONS = [
        '0002_create_system_events', '0003_create_system_queue_jobs',
        '0005_create_system_aggregate_versions', '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
        '0002_create_content_pages', '0003_create_content_posts', '0004_create_content_taxonomies',
    ];

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

        // DECISION X (4) Option-C: the worker gate requires the processing cron to be scheduled.
        // Opt the WP-Cron stub into recording and schedule it by default; the block/unblock test
        // clears it to exercise the not-scheduled branch.
        $GLOBALS['_hsp_stub_scheduled'] = [ProcessingCronRegistrar::HOOK => 'hsp_processing'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_scheduled']);
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
    // Backfill converges + write-spy proves zero direct writes
    // =========================================================================

    public function test_backfill_reemits_all_content_and_converges_with_zero_direct_writes(): void
    {
        // A first-run WordPress site with existing published content and EMPTY delivery projections.
        $this->wp->putPost(10, 'publish', 'post', 'first-post');
        $this->wp->putPost(11, 'publish', 'post', 'second-post');
        $this->wp->putPost(20, 'publish', 'page', 'about');
        $this->wp->putTerm(30, 'news');

        $this->seedFreshHeartbeat();
        $this->seedAppliedMigrations();

        $progress = $this->progress();

        // Before backfill: nothing projected; not converged.
        self::assertFalse($progress->isConverged(), 'nothing projected yet → not converged');
        self::assertSame(4, $progress->snapshot()['expected_total']);
        self::assertSame(0, $progress->snapshot()['projected_total']);

        // Trigger the backfill through the real service, wrapping the reconciliation read handle in
        // a write-spy: the backfill path must issue NO direct projection write (re-emission only).
        $spy    = new WriteSpyConnection($this->db);
        $result = $this->backfillService($spy)->start();

        self::assertSame(4, $result->repairedCount(), 'every in-scope aggregate re-emitted');
        self::assertSame(0, $spy->executeCount, 'backfill performed NO direct PG write/DML');
        self::assertSame(0, $spy->beginCount, 'backfill opened NO write transaction');

        // The re-emitted events drain through the real pipeline onto the projections.
        $this->drainPipeline();

        // WordPress-wins by construction: every published aggregate is now projected.
        self::assertNotNull($this->fetchProjectionRow('content.posts', 'source_post_id', 10));
        self::assertNotNull($this->fetchProjectionRow('content.posts', 'source_post_id', 11));
        self::assertNotNull($this->fetchProjectionRow('content.pages', 'source_post_id', 20));
        self::assertNotNull($this->fetchProjectionRow('content.taxonomies', 'source_term_id', 30));

        // Derived progress now converges (expected == projected, zero in-flight) → completion signal.
        $snap = $progress->snapshot();
        self::assertSame(4, $snap['projected_total']);
        self::assertSame(0, $snap['in_flight']);
        self::assertTrue($snap['converged']);
        self::assertSame(100, $snap['percent']);
        self::assertTrue($progress->isConverged());
    }

    // =========================================================================
    // Heartbeat gate blocks with no live worker, unblocks once a fresh heartbeat exists
    // =========================================================================

    public function test_backfill_is_blocked_without_a_live_worker_and_unblocks_with_one(): void
    {
        $this->wp->putPost(40, 'publish', 'post', 'gated');
        $this->seedAppliedMigrations();

        // No heartbeat row at all → the worker gate blocks the backfill.
        $blockedGate = $this->gate();
        self::assertFalse($blockedGate->isReady());
        $worker = $this->gateByKey($blockedGate->summary(), BackfillGate::GATE_WORKER);
        self::assertFalse($worker['passed']);
        self::assertNotSame('', $worker['remediation']);

        // A stale heartbeat still blocks (older than the freshness threshold).
        $this->seedHeartbeat(ageSeconds: 3600);
        self::assertFalse($this->gate()->isReady(), 'stale heartbeat still blocks');

        // A fresh cycle heartbeat WITH the processing cron scheduled unblocks — no lifecycle
        // action was taken; only observation changed (DECISION X (4) Option-C, both halves).
        $this->seedHeartbeat(ageSeconds: 2);
        self::assertTrue($this->gate()->isReady(), 'fresh cycle heartbeat + scheduled cron unblocks the gate');

        // Option-C: clearing the processing cron re-blocks even though the heartbeat is fresh.
        $GLOBALS['_hsp_stub_scheduled'] = [];
        self::assertFalse($this->gate()->isReady(), 'cron not scheduled re-blocks despite a fresh heartbeat');
    }

    // =========================================================================
    // Assembly — real BackfillService over the live handles
    // =========================================================================

    private function backfillService(?WriteSpyConnection $spy = null): BackfillService
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
        $recon   = new ReconciliationService($conn, $source, $replay, 500);

        return new BackfillService($this->gate(), $recon);
    }

    private function gate(): BackfillGate
    {
        $reader = new BackfillReader(fn (): PostgresDatabaseConnection => $this->db);
        $probe  = new OnboardingConnectionProbe(fn (): PostgresDatabaseConnection => $this->db);

        return new BackfillGate($reader, new MigrationsAppliedCheck($probe), 60);
    }

    private function progress(): BackfillProgress
    {
        $source = new StoreReconciliationSource($this->wp, $this->mysqli, $this->outbox);
        $reader = new BackfillReader(fn (): PostgresDatabaseConnection => $this->db);

        return new BackfillProgress($source, $reader, 500);
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
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-0000000fbee1'))) {
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

    private function seedFreshHeartbeat(): void
    {
        $this->seedHeartbeat(ageSeconds: 2);
    }

    private function seedHeartbeat(int $ageSeconds): void
    {
        pg_query($this->pgConn, 'DELETE FROM system.worker_heartbeats');
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.worker_heartbeats
                 (worker_id, worker_type, status, last_heartbeat_at, started_at)
             VALUES ($1::uuid, 'event', 'running', NOW() - make_interval(secs => $2), NOW() - make_interval(secs => $2))",
            [$this->uuidv7(), $ageSeconds],
        );
    }

    private function seedAppliedMigrations(): void
    {
        foreach (self::REQUIRED_MIGRATIONS as $name) {
            pg_query_params(
                $this->pgConn,
                "INSERT INTO system.schema_versions (migration_name, applied_at, rolled_back_at)
                 VALUES ($1, NOW(), NULL)",
                [$name],
            );
        }
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

    /**
     * @param array{ready:bool,gates:list<array<string,mixed>>} $summary
     * @return array<string,mixed>
     */
    private function gateByKey(array $summary, string $key): array
    {
        foreach ($summary['gates'] as $g) {
            if ($g['key'] === $key) {
                return $g;
            }
        }
        self::fail("gate '{$key}' not found");
    }

    private function ctx(string $workerId): WorkerExecutionContext
    {
        return new WorkerExecutionContext($workerId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    // =========================================================================
    // Schema (mirrors frozen DDL; adds worker_heartbeats + schema_versions for the gate)
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
            CREATE TABLE system.worker_heartbeats (
                worker_id         UUID        NOT NULL PRIMARY KEY,
                worker_type       TEXT        NOT NULL,
                status            TEXT        NOT NULL,
                last_heartbeat_at TIMESTAMPTZ NOT NULL,
                started_at        TIMESTAMPTZ NOT NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.schema_versions (
                migration_name VARCHAR(255) NOT NULL,
                applied_at     TIMESTAMPTZ  NOT NULL,
                rolled_back_at TIMESTAMPTZ  NULL
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
