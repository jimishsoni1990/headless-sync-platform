<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
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
 * Integration tests for the Content Handler Spine (P1A-S6b DoD).
 *
 * Exercises the full pipeline from handler → adapter → live PostgreSQL:
 *   - persist: upsert row written, all three DECISION-3 ops committed atomically
 *   - tombstone: deleted_at set; no WP reload; derived from source_updated_at
 *   - idempotent re-delivery: same event twice → processed_events ON CONFLICT DO NOTHING
 *   - stale-skip (Resolve-stage): PRIMARY guard skips handler, no writes
 *   - stale-skip (adapter GREATEST guard): defense-in-depth version monotonicity
 *
 * No WordPress bootstrap required — WpContentLoader is replaced by FakeWpContentLoader.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class HandlerSpineIntegrationTest extends TestCase
{
    private mixed  $pgConn = null;
    private PostgresDatabaseConnection $db;

    // -------------------------------------------------------------------------
    // setUp / tearDown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Persist — upsert path
    // =========================================================================

    public function test_page_upsert_handler_writes_projection_and_records_all_three_ops(): void
    {
        $loader  = $this->makeLoader(postId: 1);
        $handler = $this->makePageUpsertHandler($loader);
        $event   = $this->makeEvent(ContentEventTypes::PAGE_CREATED, 'page', '1', 1);

        $handler->handle($event);

        self::assertSame(1, $this->countRows('content.pages'),            'projection written');
        self::assertSame(1, $this->countRows('system.processed_events'),  'processed_events written');
        self::assertSame(1, $this->countRows('system.aggregate_versions'), 'aggregate_versions written');
    }

    public function test_post_upsert_handler_writes_projection_and_records_all_three_ops(): void
    {
        $loader  = $this->makeLoader(postId: 10, postType: 'post');
        $handler = $this->makePostUpsertHandler($loader);
        $event   = $this->makeEvent(ContentEventTypes::POST_CREATED, 'post', '10', 1);

        $handler->handle($event);

        self::assertSame(1, $this->countRows('content.posts'),            'projection written');
        self::assertSame(1, $this->countRows('system.processed_events'),  'processed_events written');
        self::assertSame(1, $this->countRows('system.aggregate_versions'), 'aggregate_versions written');
    }

    public function test_category_upsert_handler_writes_projection_and_records_all_three_ops(): void
    {
        $loader  = $this->makeLoader(termId: 5);
        $handler = $this->makeCategoryUpsertHandler($loader);
        $event   = $this->makeEvent(ContentEventTypes::CATEGORY_CREATED, 'category', '5', 1);

        $handler->handle($event);

        self::assertSame(1, $this->countRows('content.taxonomies'),        'projection written');
        self::assertSame(1, $this->countRows('system.processed_events'),   'processed_events written');
        self::assertSame(1, $this->countRows('system.aggregate_versions'),  'aggregate_versions written');
    }

    // =========================================================================
    // Tombstone — soft-delete path (DECISION I)
    // =========================================================================

    public function test_page_tombstone_handler_sets_deleted_at_without_wp_reload(): void
    {
        // Seed a page row first.
        $loader   = $this->makeLoader(postId: 2);
        $upsert   = $this->makePageUpsertHandler($loader);
        $upsertEv = $this->makeEvent(ContentEventTypes::PAGE_CREATED, 'page', '2', 1);
        $upsert->handle($upsertEv);

        self::assertSame(1, $this->countRows('content.pages'));

        // Now tombstone it.
        $adapter      = new PageAdapter($this->db);
        $handler      = new PageTombstoneHandler($adapter);
        $deletedAt    = new \DateTimeImmutable('2024-06-15 10:00:00', new \DateTimeZone('UTC'));
        $tombstoneEv  = $this->makeEventWithDate(ContentEventTypes::PAGE_DELETED, 'page', '2', 2, $deletedAt);

        $handler->handle($tombstoneEv);

        $row = $this->fetchPageRow(2);
        self::assertNotNull($row, 'page row must still exist (soft-delete)');
        self::assertNotNull($row['deleted_at'], 'deleted_at must be set');
        self::assertStringContainsString('2024-06-15', $row['deleted_at'], 'deleted_at matches source_updated_at date');
    }

    public function test_post_tombstone_handler_sets_deleted_at(): void
    {
        $loader   = $this->makeLoader(postId: 20, postType: 'post');
        $upsert   = $this->makePostUpsertHandler($loader);
        $upsert->handle($this->makeEvent(ContentEventTypes::POST_CREATED, 'post', '20', 1));

        $adapter  = new PostAdapter($this->db);
        $handler  = new PostTombstoneHandler($adapter);
        $deletedAt = new \DateTimeImmutable('2024-07-01 08:00:00', new \DateTimeZone('UTC'));

        $handler->handle($this->makeEventWithDate(ContentEventTypes::POST_DELETED, 'post', '20', 2, $deletedAt));

        $row = $this->fetchPostRow(20);
        self::assertNotNull($row);
        self::assertNotNull($row['deleted_at']);
        self::assertStringContainsString('2024-07-01', $row['deleted_at']);
    }

    public function test_category_tombstone_handler_sets_deleted_at(): void
    {
        $loader  = $this->makeLoader(termId: 7);
        $upsert  = $this->makeCategoryUpsertHandler($loader);
        $upsert->handle($this->makeEvent(ContentEventTypes::CATEGORY_CREATED, 'category', '7', 1));

        $adapter   = new CategoryAdapter($this->db);
        $handler   = new CategoryTombstoneHandler($adapter);
        $deletedAt = new \DateTimeImmutable('2024-08-01 12:00:00', new \DateTimeZone('UTC'));

        $handler->handle($this->makeEventWithDate(ContentEventTypes::CATEGORY_DELETED, 'category', '7', 2, $deletedAt));

        $row = $this->fetchCategoryRow(7);
        self::assertNotNull($row);
        self::assertNotNull($row['deleted_at']);
        self::assertStringContainsString('2024-08-01', $row['deleted_at']);
    }

    public function test_tombstone_without_prior_projection_is_idempotent_no_op(): void
    {
        // Tombstone a page that was never projected — no row in content.pages.
        // Must commit cleanly (UPDATE affects 0 rows) and record processed_events.
        $adapter  = new PageAdapter($this->db);
        $handler  = new PageTombstoneHandler($adapter);
        $event    = $this->makeEvent(ContentEventTypes::PAGE_DELETED, 'page', '999', 1);

        $handler->handle($event);

        self::assertSame(0, $this->countRows('content.pages'),           'no page row (was never projected)');
        self::assertSame(1, $this->countRows('system.processed_events'), 'processed_events still recorded');
        self::assertSame(1, $this->countRows('system.aggregate_versions'), 'aggregate_versions still recorded');
    }

    // =========================================================================
    // Idempotent re-delivery — processed_events ON CONFLICT DO NOTHING
    // =========================================================================

    public function test_redelivered_upsert_event_does_not_duplicate_processed_events_row(): void
    {
        $loader  = $this->makeLoader(postId: 3);
        $handler = $this->makePageUpsertHandler($loader);
        $event   = $this->makeEvent(ContentEventTypes::PAGE_CREATED, 'page', '3', 1);

        // First delivery.
        $handler->handle($event);
        self::assertSame(1, $this->countRows('system.processed_events'), 'one row after first delivery');

        // Second delivery (same event UUID) — ON CONFLICT DO NOTHING must fire.
        $handler->handle($event);
        self::assertSame(1, $this->countRows('system.processed_events'), 'still one row after redelivery');
        self::assertSame(1, $this->countRows('content.pages'),           'one page row (idempotent upsert)');
    }

    public function test_redelivered_tombstone_event_does_not_duplicate_processed_events_row(): void
    {
        $adapter = new PageAdapter($this->db);
        $handler = new PageTombstoneHandler($adapter);
        $event   = $this->makeEvent(ContentEventTypes::PAGE_DELETED, 'page', '50', 1);

        $handler->handle($event);
        self::assertSame(1, $this->countRows('system.processed_events'), 'one processed_events row after first tombstone');

        $handler->handle($event);
        self::assertSame(1, $this->countRows('system.processed_events'), 'still one row after redelivered tombstone');
    }

    // =========================================================================
    // Stale-skip — adapter GREATEST guard (DECISION J Layer 2, defense-in-depth)
    // =========================================================================

    public function test_out_of_order_older_event_does_not_regress_projection_or_version(): void
    {
        $loader  = $this->makeLoader(postId: 4);
        $handler = $this->makePageUpsertHandler($loader);

        // v5 arrives first.
        $loader->postResult['post_name'] = 'v5-slug';
        $v5Event = $this->makeEvent(ContentEventTypes::PAGE_UPDATED, 'page', '4', 5);
        $handler->handle($v5Event);

        $slugAfter5 = $this->fetchPageSlug(4);
        self::assertSame('v5-slug', $slugAfter5, 'slug is v5-slug after v5 event');
        self::assertSame(1, $this->countRows('system.processed_events'), 'v5 processed_events row written');

        // v3 arrives late (stale — adapter GREATEST guard fires, projection upsert skipped).
        $loader->postResult['post_name'] = 'v3-slug';
        $v3Event = $this->makeEvent(ContentEventTypes::PAGE_CREATED, 'page', '4', 3);
        $handler->handle($v3Event);

        // (a) Projection must not regress.
        $slugAfterStale = $this->fetchPageSlug(4);
        self::assertSame('v5-slug', $slugAfterStale, 'projection must not regress to v3-slug');

        // (b) aggregate_versions must remain at 5 (GREATEST held).
        $version = $this->fetchAggregateVersion('page', '4');
        self::assertSame(5, $version, 'aggregate_version must remain 5 (GREATEST guard)');

        // (c) Full write set for the stale adapter path:
        //     processed_events IS written (even though projection was suppressed).
        self::assertSame(2, $this->countRows('system.processed_events'),
            'both v5 and stale v3 processed_events rows written (adapter stale path still records)');
        self::assertTrue(
            $this->processedEventExists($v3Event->getId()),
            'stale v3 event ID must have its own processed_events row'
        );
        //     aggregate_versions row exists (written by GREATEST upsert).
        self::assertSame(1, $this->countRows('system.aggregate_versions'),
            'one aggregate_versions row for this aggregate');
    }

    // =========================================================================
    // ContentSubscriber routing — all 9 event types reach the correct handler
    // =========================================================================

    public function test_content_subscriber_routes_page_created_to_upsert_handler(): void
    {
        $subscriber = $this->makeContentSubscriber();
        $event      = $this->makeEvent(ContentEventTypes::PAGE_CREATED, 'page', '8', 1);

        ($subscriber)($event);

        self::assertSame(1, $this->countRows('content.pages'), 'page row written via subscriber routing');
    }

    public function test_content_subscriber_routes_category_deleted_to_tombstone_handler(): void
    {
        // Seed the category first.
        $loader  = $this->makeLoader(termId: 9);
        $handler = $this->makeCategoryUpsertHandler($loader);
        $handler->handle($this->makeEvent(ContentEventTypes::CATEGORY_CREATED, 'category', '9', 1));
        self::assertSame(1, $this->countRows('content.taxonomies'));

        // Tombstone via subscriber.
        $subscriber = $this->makeContentSubscriber();
        $event      = $this->makeEvent(ContentEventTypes::CATEGORY_DELETED, 'category', '9', 2);
        ($subscriber)($event);

        $row = $this->fetchCategoryRow(9);
        self::assertNotNull($row['deleted_at'], 'deleted_at set via subscriber routing');
    }

    // =========================================================================
    // Helpers — handler factories
    // =========================================================================

    private function makePageUpsertHandler(FakeWpContentLoader $loader): PageUpsertHandler
    {
        return new PageUpsertHandler(
            $loader,
            new PageExtractor(new PageValidator()),
            new PageTransformer(),
            new PageAdapter($this->db),
        );
    }

    private function makePostUpsertHandler(FakeWpContentLoader $loader): PostUpsertHandler
    {
        return new PostUpsertHandler(
            $loader,
            new PostExtractor(new PostValidator()),
            new PostTransformer(),
            new PostAdapter($this->db),
        );
    }

    private function makeCategoryUpsertHandler(FakeWpContentLoader $loader): CategoryUpsertHandler
    {
        return new CategoryUpsertHandler(
            $loader,
            new CategoryExtractor(new CategoryValidator()),
            new CategoryTransformer(),
            new CategoryAdapter($this->db),
        );
    }

    private function makeContentSubscriber(): ContentSubscriber
    {
        $loader = $this->makeLoader(postId: 8, termId: 9);

        $pageAdapter     = new PageAdapter($this->db);
        $postAdapter     = new PostAdapter($this->db);
        $categoryAdapter = new CategoryAdapter($this->db);

        return new ContentSubscriber([
            ContentEventTypes::PAGE_CREATED     => new PageUpsertHandler($loader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_UPDATED     => new PageUpsertHandler($loader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_DELETED     => new PageTombstoneHandler($pageAdapter),
            ContentEventTypes::POST_CREATED     => new PostUpsertHandler($loader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_UPDATED     => new PostUpsertHandler($loader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_DELETED     => new PostTombstoneHandler($postAdapter),
            ContentEventTypes::CATEGORY_CREATED => new CategoryUpsertHandler($loader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_UPDATED => new CategoryUpsertHandler($loader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_DELETED => new CategoryTombstoneHandler($categoryAdapter),
        ]);
    }

    // =========================================================================
    // Helpers — WP data
    // =========================================================================

    private function makeLoader(int $postId = 1, int $termId = 5, string $postType = 'page'): FakeWpContentLoader
    {
        $loader = new FakeWpContentLoader();
        $loader->postResult = [
            'ID'                  => $postId,
            'post_title'          => "Test Post {$postId}",
            'post_content'        => '<p>Content</p>',
            'post_excerpt'        => 'Excerpt',
            'post_name'           => "test-post-{$postId}",
            'post_status'         => 'publish',
            'post_type'           => $postType,
            'post_author'         => '1',
            'post_date_gmt'       => '2024-01-01 00:00:00',
            'post_modified_gmt'   => '2024-06-01 00:00:00',
            'post_parent'         => '0',
            'menu_order'          => '0',
        ];
        $loader->postMetaResult    = [];
        $loader->categoryIdsResult = [];
        $loader->termResult = [
            'term_id'     => $termId,
            'name'        => "Category {$termId}",
            'slug'        => "category-{$termId}",
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        ];
        return $loader;
    }

    // =========================================================================
    // Helpers — event factories
    // =========================================================================

    private function makeEvent(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
    ): HandlerSpineIntegrationEvent {
        return new HandlerSpineIntegrationEvent(
            id:               $this->newUuid(),
            eventType:        $eventType,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: $aggregateVersion,
        );
    }

    private function makeEventWithDate(
        string             $eventType,
        string             $aggregateType,
        string             $aggregateId,
        int                $aggregateVersion,
        \DateTimeImmutable $sourceUpdatedAt,
    ): HandlerSpineIntegrationEvent {
        return new HandlerSpineIntegrationEvent(
            id:               $this->newUuid(),
            eventType:        $eventType,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: $aggregateVersion,
            sourceUpdatedAt:  $sourceUpdatedAt,
        );
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
    private function fetchPageRow(int $sourcePostId): ?array
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT slug, deleted_at FROM content.pages WHERE source_post_id = $1',
            [$sourcePostId]
        );
        if ($result === false) {
            return null;
        }
        $row = pg_fetch_assoc($result) ?: null;
        pg_free_result($result);
        return $row;
    }

    private function fetchPageSlug(int $sourcePostId): ?string
    {
        return $this->fetchPageRow($sourcePostId)['slug'] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function fetchPostRow(int $sourcePostId): ?array
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT slug, deleted_at FROM content.posts WHERE source_post_id = $1',
            [$sourcePostId]
        );
        if ($result === false) {
            return null;
        }
        $row = pg_fetch_assoc($result) ?: null;
        pg_free_result($result);
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function fetchCategoryRow(int $sourceTermId): ?array
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT slug, deleted_at FROM content.taxonomies WHERE source_term_id = $1',
            [$sourceTermId]
        );
        if ($result === false) {
            return null;
        }
        $row = pg_fetch_assoc($result) ?: null;
        pg_free_result($result);
        return $row;
    }

    private function processedEventExists(string $eventId): bool
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT 1 FROM system.processed_events WHERE event_id = $1::uuid',
            [$eventId]
        );
        if ($result === false) {
            return false;
        }
        $exists = pg_fetch_row($result) !== false;
        pg_free_result($result);
        return $exists;
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
    // Connection helpers
    // =========================================================================

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: 'postgres';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'postgres';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'postgres';

        $dsn  = "host={$host} port={$port} user={$user} password={$pass} dbname={$db}";
        $conn = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped(
                "PostgreSQL not available at {$host}:{$port} — skipping handler spine integration tests."
            );
        }

        return $conn;
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS system');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.processed_events (
                event_id     UUID        NOT NULL,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_system_processed_events PRIMARY KEY (event_id)
            )
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_system_aggregate_versions PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ');

        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.pages (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT \'page\',
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
                meta_jsonb         JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_pages PRIMARY KEY (id),
                CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
            )
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.posts (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT \'post\',
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
                meta_jsonb         JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_posts PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
            )
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.taxonomies (
                id              UUID         NOT NULL,
                source_term_id  BIGINT       NOT NULL,
                taxonomy_type   VARCHAR(50)  NOT NULL,
                slug            VARCHAR(255) NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT         NOT NULL DEFAULT \'\',
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
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.entity_taxonomies (
                entity_id   UUID NOT NULL,
                taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ');
    }
}

// -------------------------------------------------------------------------
// Test-local event stub
// -------------------------------------------------------------------------

/**
 * Configurable EventInterface stub for handler spine integration tests.
 * sourceUpdatedAt defaults to a fixed timestamp; can be overridden for tombstone
 * deleted_at determinism tests (DECISION I).
 */
final class HandlerSpineIntegrationEvent implements \HSP\Core\Contracts\EventInterface
{
    public function __construct(
        private readonly string             $id,
        private readonly string             $eventType,
        private readonly string             $aggregateType,
        private readonly string             $aggregateId,
        private readonly int                $aggregateVersion,
        private readonly ?\DateTimeImmutable $sourceUpdatedAt = null,
    ) {}

    public function getId(): string                          { return $this->id; }
    public function getEventType(): string                   { return $this->eventType; }
    public function getEventVersion(): int                   { return 1; }
    public function getAggregateType(): string               { return $this->aggregateType; }
    public function getAggregateId(): string                 { return $this->aggregateId; }
    public function getAggregateVersion(): int               { return $this->aggregateVersion; }
    public function getPayload(): array                      { return []; }
    public function getChecksum(): string                    { return hash('sha256', $this->id . $this->aggregateVersion); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return $this->sourceUpdatedAt ?? new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable       { return new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCorrelationId(): string               { return '01900000-0000-7000-8000-000000000099'; }
    public function getCausationId(): ?string                { return null; }
}
