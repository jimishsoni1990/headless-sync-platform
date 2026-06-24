<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Contracts\FilterSet;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Queries\PostQueryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the REST Delivery API query providers.
 *
 * Tests execute against live PostgreSQL (content.* projection tables).
 * Query Provider tests are independent of Resources (Doc 9 §28).
 *
 * DoD coverage (P1A-S5):
 *   1. All six endpoints respond correctly from content.* projections.
 *   2. Cursor pagination works — proven including the shared-sort-value edge case.
 *   3. No WordPress queries on the consumer path (ADR-040).
 *   4. /api/v1/ versioning prefix verified (route registration layer, tested separately).
 *   5. Soft-deleted rows excluded by default.
 *   6. status = 'publish' default; non-publish rows not returned.
 *   7. findBySlug returns null for missing or soft-deleted rows.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class DeliveryApiIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
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
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // PageQueryProvider
    // =========================================================================

    public function test_page_list_returns_seeded_pages(): void
    {
        $this->seedPage(postId: 1, slug: 'about', status: 'publish');
        $this->seedPage(postId: 2, slug: 'contact', status: 'publish');

        $provider = new PageQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(2, $page->rows);
        $slugs = array_column($page->rows, 'slug');
        self::assertContains('about', $slugs);
        self::assertContains('contact', $slugs);
    }

    public function test_page_list_excludes_soft_deleted(): void
    {
        $this->seedPage(postId: 10, slug: 'live', status: 'publish');
        $this->seedPage(postId: 11, slug: 'deleted', status: 'publish', deletedAt: '2024-01-01 00:00:00+00');

        $provider = new PageQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertSame('live', $page->rows[0]['slug']);
    }

    public function test_page_list_excludes_non_publish_by_default(): void
    {
        $this->seedPage(postId: 20, slug: 'draft-page', status: 'draft');
        $this->seedPage(postId: 21, slug: 'live-page', status: 'publish');

        $provider = new PageQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertSame('live-page', $page->rows[0]['slug']);
    }

    public function test_page_list_published_after_filter(): void
    {
        $this->seedPage(postId: 30, slug: 'old', status: 'publish', publishedAt: '2023-01-01 00:00:00+00');
        $this->seedPage(postId: 31, slug: 'new', status: 'publish', publishedAt: '2025-01-01 00:00:00+00');

        $provider = new PageQueryProvider($this->db);
        $page     = $provider->list(new FilterSet(
            publishedAfter: new \DateTimeImmutable('2024-01-01T00:00:00Z')
        ));

        self::assertCount(1, $page->rows);
        self::assertSame('new', $page->rows[0]['slug']);
    }

    public function test_page_list_cursor_pagination_no_skips_or_duplicates(): void
    {
        // Seed 5 pages with distinct published_at values.
        for ($i = 1; $i <= 5; $i++) {
            $this->seedPage(
                postId:      $i,
                slug:        'page-' . $i,
                status:      'publish',
                publishedAt: "202{$i}-06-01 00:00:00+00"
            );
        }

        $provider = new PageQueryProvider($this->db);

        // Page 1: limit 2
        $p1 = $provider->list(new FilterSet(limit: 2));
        self::assertCount(2, $p1->rows);
        self::assertNotNull($p1->nextCursor);

        // Page 2: next 2
        $p2 = $provider->list(new FilterSet(limit: 2, cursor: $p1->nextCursor));
        self::assertCount(2, $p2->rows);
        self::assertNotNull($p2->nextCursor);

        // Page 3: last 1
        $p3 = $provider->list(new FilterSet(limit: 2, cursor: $p2->nextCursor));
        self::assertCount(1, $p3->rows);
        self::assertNull($p3->nextCursor);

        // No slugs duplicated across pages.
        $allSlugs = array_merge(
            array_column($p1->rows, 'slug'),
            array_column($p2->rows, 'slug'),
            array_column($p3->rows, 'slug')
        );
        self::assertCount(5, $allSlugs);
        self::assertCount(5, array_unique($allSlugs));
    }

    public function test_page_list_cursor_pagination_shared_published_at_no_duplicates(): void
    {
        // All five pages share the same published_at — id tiebreaker must prevent duplicates.
        $sharedTs = '2024-06-15 12:00:00+00';
        for ($i = 1; $i <= 5; $i++) {
            $this->seedPage(
                postId:      $i + 100,
                slug:        'shared-' . $i,
                status:      'publish',
                publishedAt: $sharedTs
            );
        }

        $provider = new PageQueryProvider($this->db);

        $p1 = $provider->list(new FilterSet(limit: 2));
        self::assertCount(2, $p1->rows);

        $p2 = $provider->list(new FilterSet(limit: 2, cursor: $p1->nextCursor));
        self::assertCount(2, $p2->rows);

        $p3 = $provider->list(new FilterSet(limit: 2, cursor: $p2->nextCursor));
        self::assertCount(1, $p3->rows);
        self::assertNull($p3->nextCursor);

        $allSlugs = array_merge(
            array_column($p1->rows, 'slug'),
            array_column($p2->rows, 'slug'),
            array_column($p3->rows, 'slug')
        );
        self::assertCount(5, array_unique($allSlugs), 'No duplicates across pages sharing published_at');
    }

    public function test_page_find_by_slug_returns_row(): void
    {
        $this->seedPage(postId: 40, slug: 'find-me', status: 'publish');

        $provider = new PageQueryProvider($this->db);
        $row      = $provider->findBySlug('find-me');

        self::assertNotNull($row);
        self::assertSame('find-me', $row['slug']);
    }

    public function test_page_find_by_slug_returns_null_for_missing(): void
    {
        $provider = new PageQueryProvider($this->db);

        self::assertNull($provider->findBySlug('ghost'));
    }

    public function test_page_find_by_slug_returns_null_for_soft_deleted(): void
    {
        $this->seedPage(postId: 41, slug: 'dead-page', status: 'publish', deletedAt: '2024-01-01 00:00:00+00');

        self::assertNull((new PageQueryProvider($this->db))->findBySlug('dead-page'));
    }

    public function test_page_find_by_slug_returns_null_for_non_publish_status(): void
    {
        $this->seedPage(postId: 42, slug: 'draft-page', status: 'draft');

        self::assertNull((new PageQueryProvider($this->db))->findBySlug('draft-page'));
    }

    // =========================================================================
    // PostQueryProvider
    // =========================================================================

    public function test_post_list_returns_seeded_posts(): void
    {
        $this->seedPost(postId: 50, slug: 'hello-world', status: 'publish');
        $this->seedPost(postId: 51, slug: 'second-post', status: 'publish');

        $provider = new PostQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(2, $page->rows);
    }

    public function test_post_list_excludes_soft_deleted(): void
    {
        $this->seedPost(postId: 60, slug: 'live-post', status: 'publish');
        $this->seedPost(postId: 61, slug: 'dead-post', status: 'publish', deletedAt: '2024-01-01 00:00:00+00');

        $provider = new PostQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertSame('live-post', $page->rows[0]['slug']);
    }

    public function test_post_list_default_status_is_publish(): void
    {
        $this->seedPost(postId: 70, slug: 'draft', status: 'draft');
        $this->seedPost(postId: 71, slug: 'published', status: 'publish');

        $provider = new PostQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertSame('published', $page->rows[0]['slug']);
    }

    public function test_post_list_category_slug_filter_via_projection_join(): void
    {
        // Seed category + two posts; only one linked.
        $catUuid = $this->seedTaxonomy(termId: 100, slug: 'news', name: 'News');
        $post1Id = $this->seedPost(postId: 80, slug: 'news-post', status: 'publish');
        $post2Id = $this->seedPost(postId: 81, slug: 'other-post', status: 'publish');
        $this->seedEntityTaxonomy($post1Id, $catUuid);

        $provider = new PostQueryProvider($this->db);
        $page     = $provider->list(new FilterSet(categorySlug: 'news'));

        self::assertCount(1, $page->rows);
        self::assertSame('news-post', $page->rows[0]['slug']);
    }

    public function test_post_list_category_filter_returns_empty_when_no_match(): void
    {
        $this->seedPost(postId: 82, slug: 'no-cat-post', status: 'publish');

        $provider = new PostQueryProvider($this->db);
        $page     = $provider->list(new FilterSet(categorySlug: 'nonexistent-cat'));

        self::assertCount(0, $page->rows);
    }

    public function test_post_list_cursor_pagination_no_skips_or_duplicates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedPost(
                postId:      $i + 200,
                slug:        'post-' . $i,
                status:      'publish',
                publishedAt: "202{$i}-03-01 00:00:00+00"
            );
        }

        $provider = new PostQueryProvider($this->db);

        $p1 = $provider->list(new FilterSet(limit: 2));
        $p2 = $provider->list(new FilterSet(limit: 2, cursor: $p1->nextCursor));
        $p3 = $provider->list(new FilterSet(limit: 2, cursor: $p2->nextCursor));

        $all = array_merge(
            array_column($p1->rows, 'slug'),
            array_column($p2->rows, 'slug'),
            array_column($p3->rows, 'slug')
        );
        self::assertCount(5, $all);
        self::assertCount(5, array_unique($all));
    }

    public function test_post_list_cursor_shared_published_at_no_duplicates(): void
    {
        $sharedTs = '2024-09-01 00:00:00+00';
        for ($i = 1; $i <= 5; $i++) {
            $this->seedPost(
                postId:      $i + 300,
                slug:        'shared-post-' . $i,
                status:      'publish',
                publishedAt: $sharedTs
            );
        }

        $provider = new PostQueryProvider($this->db);

        $p1 = $provider->list(new FilterSet(limit: 2));
        $p2 = $provider->list(new FilterSet(limit: 2, cursor: $p1->nextCursor));
        $p3 = $provider->list(new FilterSet(limit: 2, cursor: $p2->nextCursor));

        $all = array_merge(
            array_column($p1->rows, 'slug'),
            array_column($p2->rows, 'slug'),
            array_column($p3->rows, 'slug')
        );
        self::assertCount(5, array_unique($all), 'No duplicates when posts share published_at');
    }

    public function test_post_find_by_slug_returns_row(): void
    {
        $this->seedPost(postId: 90, slug: 'find-post', status: 'publish');

        $provider = new PostQueryProvider($this->db);
        $row      = $provider->findBySlug('find-post');

        self::assertNotNull($row);
        self::assertSame('find-post', $row['slug']);
    }

    public function test_post_find_by_slug_returns_null_for_missing(): void
    {
        self::assertNull((new PostQueryProvider($this->db))->findBySlug('ghost'));
    }

    public function test_post_find_by_slug_returns_null_for_soft_deleted(): void
    {
        $this->seedPost(postId: 91, slug: 'dead-post', status: 'publish', deletedAt: '2024-01-01 00:00:00+00');

        self::assertNull((new PostQueryProvider($this->db))->findBySlug('dead-post'));
    }

    public function test_post_find_by_slug_returns_null_for_non_publish_status(): void
    {
        $this->seedPost(postId: 92, slug: 'draft-post', status: 'draft');

        self::assertNull((new PostQueryProvider($this->db))->findBySlug('draft-post'));
    }

    // =========================================================================
    // CategoryQueryProvider
    // =========================================================================

    public function test_category_list_returns_seeded_categories(): void
    {
        $this->seedTaxonomy(termId: 200, slug: 'tech', name: 'Technology');
        $this->seedTaxonomy(termId: 201, slug: 'sport', name: 'Sport');

        $provider = new CategoryQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(2, $page->rows);
    }

    public function test_category_list_excludes_soft_deleted(): void
    {
        $this->seedTaxonomy(termId: 210, slug: 'live-cat', name: 'Live');
        $this->seedTaxonomy(termId: 211, slug: 'dead-cat', name: 'Dead', deletedAt: '2024-01-01 00:00:00+00');

        $provider = new CategoryQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertSame('live-cat', $page->rows[0]['slug']);
    }

    public function test_category_list_sorted_by_name_asc(): void
    {
        $this->seedTaxonomy(termId: 220, slug: 'zebra', name: 'Zebra');
        $this->seedTaxonomy(termId: 221, slug: 'apple', name: 'Apple');
        $this->seedTaxonomy(termId: 222, slug: 'mango', name: 'Mango');

        $provider = new CategoryQueryProvider($this->db);
        $page     = $provider->list(new FilterSet());

        $names = array_column($page->rows, 'name');
        self::assertSame(['Apple', 'Mango', 'Zebra'], $names);
    }

    public function test_category_list_cursor_pagination_no_skips_or_duplicates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->seedTaxonomy(termId: 230 + $i, slug: 'cat-' . $i, name: 'Cat ' . $i);
        }

        $provider = new CategoryQueryProvider($this->db);

        $p1 = $provider->list(new FilterSet(limit: 2));
        $p2 = $provider->list(new FilterSet(limit: 2, cursor: $p1->nextCursor));
        $p3 = $provider->list(new FilterSet(limit: 2, cursor: $p2->nextCursor));

        $all = array_merge(
            array_column($p1->rows, 'slug'),
            array_column($p2->rows, 'slug'),
            array_column($p3->rows, 'slug')
        );
        self::assertCount(5, $all);
        self::assertCount(5, array_unique($all));
    }

    public function test_category_list_cursor_shared_name_no_duplicates(): void
    {
        // Two categories share the name "Duplicate" — id tiebreaker must prevent duplicates.
        $this->seedTaxonomy(termId: 240, slug: 'dup-a', name: 'Duplicate');
        $this->seedTaxonomy(termId: 241, slug: 'dup-b', name: 'Duplicate');
        $this->seedTaxonomy(termId: 242, slug: 'other', name: 'Other');

        $provider = new CategoryQueryProvider($this->db);

        $p1 = $provider->list(new FilterSet(limit: 2));
        self::assertCount(2, $p1->rows);

        $p2 = $provider->list(new FilterSet(limit: 2, cursor: $p1->nextCursor));
        self::assertCount(1, $p2->rows);
        self::assertNull($p2->nextCursor);

        $all = array_merge(
            array_column($p1->rows, 'slug'),
            array_column($p2->rows, 'slug')
        );
        self::assertCount(3, array_unique($all), 'No duplicates when categories share name');
    }

    public function test_category_find_by_slug_returns_row(): void
    {
        $this->seedTaxonomy(termId: 250, slug: 'find-cat', name: 'FindMe');

        $provider = new CategoryQueryProvider($this->db);
        $row      = $provider->findBySlug('find-cat');

        self::assertNotNull($row);
        self::assertSame('find-cat', $row['slug']);
    }

    public function test_category_find_by_slug_returns_null_for_missing(): void
    {
        self::assertNull((new CategoryQueryProvider($this->db))->findBySlug('ghost'));
    }

    public function test_category_find_by_slug_returns_null_for_soft_deleted(): void
    {
        $this->seedTaxonomy(termId: 251, slug: 'dead-cat', name: 'Dead', deletedAt: '2024-01-01 00:00:00+00');

        self::assertNull((new CategoryQueryProvider($this->db))->findBySlug('dead-cat'));
    }

    // =========================================================================
    // Infrastructure helpers
    // =========================================================================

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT') ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER') ?: 'postgres';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'postgres';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'hsp_test';

        $conn = @pg_connect("host={$host} port={$port} user={$user} password={$pass} dbname={$db}");
        if ($conn === false) {
            $this->markTestSkipped('PostgreSQL not available — set HSP_TEST_PGSQL_* env vars.');
        }
        return $conn;
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.pages (
                id               UUID         NOT NULL,
                source_post_id   BIGINT       NOT NULL,
                source_entity_type VARCHAR(50) NOT NULL DEFAULT \'page\',
                slug             VARCHAR(255) NOT NULL,
                title            TEXT         NOT NULL DEFAULT \'\',
                content          TEXT         NOT NULL DEFAULT \'\',
                status           VARCHAR(50)  NOT NULL,
                parent_id        BIGINT       NOT NULL DEFAULT 0,
                menu_order       INTEGER      NOT NULL DEFAULT 0,
                published_at     TIMESTAMPTZ  NOT NULL,
                updated_at       TIMESTAMPTZ  NOT NULL,
                deleted_at       TIMESTAMPTZ  NULL,
                checksum         VARCHAR(64)  NOT NULL DEFAULT \'\',
                meta_jsonb       JSONB        NOT NULL DEFAULT \'{}\',
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                synced_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT pk_test_pages PRIMARY KEY (id),
                CONSTRAINT uq_test_pages_source_post_id UNIQUE (source_post_id)
            )
        ');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.posts (
                id               UUID         NOT NULL,
                source_post_id   BIGINT       NOT NULL,
                source_entity_type VARCHAR(50) NOT NULL DEFAULT \'post\',
                slug             VARCHAR(255) NOT NULL,
                title            TEXT         NOT NULL DEFAULT \'\',
                content          TEXT         NOT NULL DEFAULT \'\',
                excerpt          TEXT         NOT NULL DEFAULT \'\',
                status           VARCHAR(50)  NOT NULL,
                author           VARCHAR(255) NOT NULL DEFAULT \'\',
                published_at     TIMESTAMPTZ  NOT NULL,
                updated_at       TIMESTAMPTZ  NOT NULL,
                deleted_at       TIMESTAMPTZ  NULL,
                checksum         VARCHAR(64)  NOT NULL DEFAULT \'\',
                meta_jsonb       JSONB        NOT NULL DEFAULT \'{}\',
                created_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                synced_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT pk_test_posts PRIMARY KEY (id),
                CONSTRAINT uq_test_posts_source_post_id UNIQUE (source_post_id)
            )
        ');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.taxonomies (
                id              UUID         NOT NULL,
                source_term_id  BIGINT       NOT NULL,
                taxonomy_type   VARCHAR(50)  NOT NULL DEFAULT \'category\',
                slug            VARCHAR(255) NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT         NOT NULL DEFAULT \'\',
                parent_id       BIGINT       NOT NULL DEFAULT 0,
                post_count      INTEGER      NOT NULL DEFAULT 0,
                deleted_at      TIMESTAMPTZ  NULL,
                checksum        VARCHAR(64)  NOT NULL DEFAULT \'\',
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                synced_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT pk_test_taxonomies PRIMARY KEY (id),
                CONSTRAINT uq_test_taxonomies_source_term_id UNIQUE (source_term_id)
            )
        ');

        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.entity_taxonomies (
                entity_id   UUID NOT NULL,
                taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_test_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ');
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

    private function seedPage(
        int     $postId,
        string  $slug,
        string  $status      = 'publish',
        string  $publishedAt = '2024-06-01 00:00:00+00',
        ?string $deletedAt   = null,
    ): string {
        $id        = $this->uuidv7();
        $deletedSql = $deletedAt !== null ? "'{$deletedAt}'::timestamptz" : 'NULL';
        pg_query(
            $this->pgConn,
            "INSERT INTO content.pages
                (id, source_post_id, slug, title, content, status, published_at, updated_at, deleted_at)
             VALUES ('{$id}'::uuid, {$postId}, '{$slug}', 'Title', '', '{$status}',
                     '{$publishedAt}'::timestamptz, '{$publishedAt}'::timestamptz, {$deletedSql})"
        );
        return $id;
    }

    private function seedPost(
        int     $postId,
        string  $slug,
        string  $status      = 'publish',
        string  $publishedAt = '2024-06-01 00:00:00+00',
        ?string $deletedAt   = null,
    ): string {
        $id         = $this->uuidv7();
        $deletedSql = $deletedAt !== null ? "'{$deletedAt}'::timestamptz" : 'NULL';
        pg_query(
            $this->pgConn,
            "INSERT INTO content.posts
                (id, source_post_id, slug, title, content, excerpt, status, author, published_at, updated_at, deleted_at)
             VALUES ('{$id}'::uuid, {$postId}, '{$slug}', 'Title', '', '', '{$status}', 'author',
                     '{$publishedAt}'::timestamptz, '{$publishedAt}'::timestamptz, {$deletedSql})"
        );
        return $id;
    }

    private function seedTaxonomy(
        int     $termId,
        string  $slug,
        string  $name,
        ?string $deletedAt = null,
    ): string {
        $id         = $this->uuidv7();
        $deletedSql = $deletedAt !== null ? "'{$deletedAt}'::timestamptz" : 'NULL';
        pg_query(
            $this->pgConn,
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, deleted_at)
             VALUES ('{$id}'::uuid, {$termId}, 'category', '{$slug}', '{$name}', {$deletedSql})"
        );
        return $id;
    }

    private function seedEntityTaxonomy(string $entityUuid, string $taxonomyUuid): void
    {
        pg_query(
            $this->pgConn,
            "INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id)
             VALUES ('{$entityUuid}'::uuid, '{$taxonomyUuid}'::uuid)"
        );
    }
}
