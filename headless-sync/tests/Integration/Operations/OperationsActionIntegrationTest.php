<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Operations;

use HSP\Core\Contracts\Operations\ActionResult;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\AggregateVersionCounter;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Events\Outbox\OutboxWriter;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Operations\Services\OperationsActionService;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
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
use HSP\Tests\Integration\Reconciliation\StoreReconciliationSource;
use HSP\Tests\Integration\Reconciliation\WriteSpyConnection;
use HSP\Tests\Integration\Replay\FakeWpStore;
use HSP\Tests\Integration\Replay\FakeWpdb;
use HSP\Tests\Integration\Replay\ReplayReadingLoader;
use PHPUnit\Framework\TestCase;

/**
 * OPSC-S4 — Operational Actions (Replay + Reconcile), end-to-end on LIVE MySQL + LIVE PostgreSQL.
 *
 * Drives the two permitted console actions THROUGH the real OPSC-S4 seam
 * (OperationsActionService → ReplayWorkerStrategy / ReconciliationWorkerStrategy → the ratified
 * ReplayService / ReconciliationService → outbox → relay → dispatch → worker → content.*), and
 * proves the OPSC-S4 DoD:
 *   - Replay + Reconcile actions invoke the ratified services ONLY, converging the projection to
 *     current WordPress state via re-emission (DECISION T/S/U).
 *   - Write-spy: ZERO direct content.* / system.* writes on the action path (mirrors GATE-S3) —
 *     the action seam holds no write primitive; repair is re-emission only (DECISION V (d)).
 *   - The audit line is emitted through the existing observability path (StructuredLogger — no
 *     new persistence).
 *
 * The ONLY substitution is the WordPress-read boundary (DECISION H): a headless PHPUnit process
 * cannot bootstrap WordPress, so FakeWpStore stands in for get_post/get_term (identical harness
 * to ReconciliationIntegrationTest / ReplayEngineIntegrationTest). Everything downstream is the
 * real runtime on real databases.
 */
final class OperationsActionIntegrationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_opscs4_';
    private string $outbox;
    private string $counters;

    private FakeWpStore $wp;

    /** @var list<string> captured audit log lines */
    private array $auditLines = [];

    protected function setUp(): void
    {
        $this->outbox   = $this->prefix . 'hsp_outbox';
        $this->counters = $this->prefix . 'hsp_aggregate_counters';

        $this->mysqli = $this->connectMysql();
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->wp     = new FakeWpStore();
        $this->auditLines = [];

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
    // Replay action (entity) — re-emit through the seam → projection converges
    // =========================================================================

    public function test_replay_entity_action_converges_a_corrupt_projection_through_the_seam(): void
    {
        // WordPress has a published post; project it correctly first via a real replay action.
        $this->wp->putPost(10, 'publish', 'post', 'correct-slug');
        $spy = new WriteSpyConnection($this->db);

        $result = $this->actionService($spy)->execute(OperationsActionService::ACTION_REPLAY, [
            'mode'           => 'entity',
            'aggregate_type' => 'post',
            'aggregate_id'   => '10',
        ]);

        self::assertInstanceOf(ActionResult::class, $result);
        self::assertSame('replay', $result->action);
        self::assertSame(1, $result->count, 'one synthetic event re-emitted');
        self::assertSame(0, $spy->executeCount, 'action path performed NO direct PG write');

        $this->drainPipeline();
        self::assertSame('correct-slug', $this->fetchProjectionRow('content.posts', 'source_post_id', 10)['slug']);

        // Corrupt the projection out-of-band. The checksum must diverge too, otherwise the
        // DECISION 3 write-suppress rule (recomputed projection checksum == stored checksum)
        // would correctly no-op the re-emission and nothing would change.
        pg_query_params(
            $this->pgConn,
            "UPDATE content.posts SET slug = 'corrupted', checksum = $1 WHERE source_post_id = 10",
            [str_repeat('9', 64)],
        );

        $spy2   = new WriteSpyConnection($this->db);
        $result = $this->actionService($spy2)->execute(OperationsActionService::ACTION_REPLAY, [
            'mode' => 'entity', 'aggregate_type' => 'post', 'aggregate_id' => '10',
        ]);

        self::assertTrue($result->ok);
        self::assertSame(0, $spy2->executeCount, 'repair rode re-emission, not a direct write');

        $this->drainPipeline();
        self::assertSame(
            'correct-slug',
            $this->fetchProjectionRow('content.posts', 'source_post_id', 10)['slug'],
            'projection repaired to current WordPress state via the action → pipeline',
        );
    }

    // =========================================================================
    // Reconcile action (drift) — missed capture repaired through the seam
    // =========================================================================

    public function test_reconcile_drift_action_repairs_a_missed_capture_through_the_seam(): void
    {
        // WordPress has a published post; the projection is empty (DECISION 1 post-commit gap).
        $this->wp->putPost(20, 'publish', 'post', 'recovered');
        $spy = new WriteSpyConnection($this->db);

        $result = $this->actionService($spy)->execute(OperationsActionService::ACTION_RECONCILE, [
            'mode' => 'drift',
        ]);

        self::assertSame('reconcile', $result->action);
        self::assertSame(1, $result->count, 'missed capture detected + repaired');
        self::assertSame('drift', $result->detail['mode']);
        self::assertSame(0, $spy->executeCount, 'reconcile action performed NO direct PG write');

        $this->drainPipeline();

        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 20);
        self::assertNotNull($row, 'projection reprojected via re-emission through the action seam');
        self::assertSame('recovered', $row['slug']);
        self::assertNull($row['deleted_at']);
    }

    // =========================================================================
    // The audit half of the DoD: an executed action emits one structured audit line
    // =========================================================================

    public function test_executed_action_emits_a_structured_audit_line(): void
    {
        $this->wp->putPost(30, 'publish', 'post', 'audited');

        $this->actionService($this->db)->execute(OperationsActionService::ACTION_REPLAY, [
            'mode' => 'entity', 'aggregate_type' => 'post', 'aggregate_id' => '30',
        ]);

        self::assertCount(1, $this->auditLines, 'exactly one audit line per action');
        $decoded = json_decode($this->auditLines[0], true);
        self::assertIsArray($decoded);
        self::assertSame('operations.action', $decoded['event']);
        self::assertSame('replay', $decoded['action']);
        self::assertTrue($decoded['ok']);
    }

    // =========================================================================
    // Assembly — build the real OPSC-S4 action seam over the given (possibly spied) handle
    // =========================================================================

    private function actionService(\HSP\Core\Database\DatabaseConnectionInterface $conn): OperationsActionService
    {
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

        $audit = new StructuredLogger(function (string $line): void {
            $this->auditLines[] = $line;
        });

        return new OperationsActionService(
            new ReplayWorkerStrategy($replay),
            new ReconciliationWorkerStrategy($recon),
            $audit,
        );
    }

    private function drainPipeline(): void
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
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-0000000fac54'))) {
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
    // Reads / helpers
    // =========================================================================

    /** @return array<string,mixed>|null */
    private function fetchProjectionRow(string $table, string $keyCol, int $keyVal): ?array
    {
        $r = pg_query_params($this->pgConn, "SELECT * FROM {$table} WHERE {$keyCol} = \$1", [$keyVal]);
        return pg_fetch_assoc($r) ?: null;
    }

    private function ctx(string $workerId): WorkerExecutionContext
    {
        return new WorkerExecutionContext($workerId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    // =========================================================================
    // Schema (mirrors frozen DDL — same shapes as ReconciliationIntegrationTest)
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
}
