<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Contracts\FilterSet;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Tests\Support\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * Tags against live PostgreSQL (P1B-S3 DoD).
 *
 * Covers what a fake connection cannot: that tags and categories genuinely coexist in one
 * content.taxonomies table without leaking into each other's endpoints, that the tag-filtered
 * listing keeps its cursor guarantees across the join, and that the join is index-backed in BOTH
 * directions at a realistic size.
 *
 * Schema comes from the REAL migration files.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class TagsIntegrationTest extends TestCase
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
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // No new table: both taxonomies in one projection, told apart by type
    // =========================================================================

    public function test_a_tag_and_a_category_sharing_a_slug_do_not_leak_into_each_other(): void
    {
        // The collision that makes the taxonomy_type predicate load-bearing: WordPress allows a
        // tag and a category to share a slug, because slugs are unique only WITHIN a taxonomy.
        $this->seedTerm(1, 'news', 'News Category', 'category');
        $this->seedTerm(2, 'news', 'News Tag', 'post_tag');

        $category = (new CategoryQueryProvider($this->db))->findBySlug('news');
        $tag      = (new CategoryQueryProvider($this->db, 'post_tag'))->findBySlug('news');

        self::assertNotNull($category);
        self::assertNotNull($tag);
        self::assertSame('News Category', $category['name']);
        self::assertSame('News Tag', $tag['name']);
    }

    public function test_the_categories_listing_never_returns_tags(): void
    {
        $this->seedTerm(1, 'guides', 'Guides', 'category');
        $this->seedTerm(2, 'php', 'PHP', 'post_tag');
        $this->seedTerm(3, 'news', 'News', 'post_tag');

        $rows = (new CategoryQueryProvider($this->db))->list(new FilterSet(limit: 50))->rows;

        self::assertCount(1, $rows);
        self::assertSame('guides', $rows[0]['slug']);
    }

    public function test_the_tags_listing_never_returns_categories(): void
    {
        $this->seedTerm(1, 'guides', 'Guides', 'category');
        $this->seedTerm(2, 'php', 'PHP', 'post_tag');

        $rows = (new CategoryQueryProvider($this->db, 'post_tag'))->list(new FilterSet(limit: 50))->rows;

        self::assertCount(1, $rows);
        self::assertSame('php', $rows[0]['slug']);
    }

    // =========================================================================
    // Filtering posts by tag
    // =========================================================================

    public function test_filtering_posts_by_tag_returns_only_tagged_posts(): void
    {
        $tagId = $this->seedTerm(2, 'php', 'PHP', 'post_tag');
        $a     = $this->seedPost(1, 'tagged');
        $this->seedPost(2, 'untagged');
        $this->linkTerm($a, $tagId);

        $page = (new PostQueryProvider($this->db))->list(new FilterSet(tagSlug: 'php', limit: 50));

        self::assertCount(1, $page->rows);
        self::assertSame('tagged', $page->rows[0]['slug']);
    }

    public function test_a_category_filter_does_not_match_a_tag_of_the_same_slug(): void
    {
        // Without the taxonomy_type predicate in the filter this returns the post and the bug is
        // invisible until a site happens to name a tag and a category alike.
        $tagId = $this->seedTerm(2, 'news', 'News Tag', 'post_tag');
        $post  = $this->seedPost(1, 'tagged-only');
        $this->linkTerm($post, $tagId);

        $page = (new PostQueryProvider($this->db))->list(new FilterSet(categorySlug: 'news', limit: 50));

        self::assertCount(0, $page->rows, '?category=news must not match a post carrying the TAG news');
    }

    public function test_a_soft_deleted_tag_stops_matching_and_stops_being_published(): void
    {
        $tagId = $this->seedTerm(2, 'php', 'PHP', 'post_tag');
        $post  = $this->seedPost(1, 'tagged');
        $this->linkTerm($post, $tagId);

        $this->db->execute("UPDATE content.taxonomies SET deleted_at = now() WHERE id = \$1", [$tagId]);

        $page = (new PostQueryProvider($this->db))->list(new FilterSet(tagSlug: 'php', limit: 50));
        self::assertCount(0, $page->rows, 'a deleted tag matches nothing');

        $row = (new PostQueryProvider($this->db))->findBySlug('tagged');
        self::assertNotNull($row, 'the post itself is still served');
        self::assertSame([], (new PostResource())->toArray($row)['tags'], 'and no longer lists the tag');
    }

    // =========================================================================
    // The published payload
    // =========================================================================

    public function test_a_post_publishes_its_tags_in_slug_order(): void
    {
        $php  = $this->seedTerm(2, 'php', 'PHP', 'post_tag');
        $news = $this->seedTerm(3, 'news', 'News', 'post_tag');
        $cat  = $this->seedTerm(4, 'guides', 'Guides', 'category');
        $post = $this->seedPost(1, 'tagged');
        $this->linkTerm($post, $php);
        $this->linkTerm($post, $news);
        $this->linkTerm($post, $cat);

        $body = (new PostResource())->toArray(
            (new PostQueryProvider($this->db))->findBySlug('tagged')
        );

        // Categories are NOT tags: the aggregate is scoped to post_tag.
        self::assertCount(2, $body['tags']);
        self::assertSame(['news', 'php'], array_column($body['tags'], 'slug'), 'ordered by slug');
    }

    // =========================================================================
    // The PIPELINE links posts to tags — not just hand-seeded fixtures
    // =========================================================================

    public function test_the_real_upsert_handler_links_a_post_to_its_tags(): void
    {
        // REGRESSION (P1B-S3 join bug): every other test in this class seeds
        // content.entity_taxonomies by hand, which is exactly why the missing link-write went
        // unnoticed — tag terms synced, /tags worked, and yet no post ever had a tag because
        // PostAdapter rewrote the join table from categoryIds alone. This test drives the REAL
        // handler so the pipeline itself has to produce the link.
        $this->seedTerm(2, 'php', 'PHP', 'post_tag');
        $this->seedTerm(3, 'guides', 'Guides', 'category');

        $loader = new \HSP\Tests\Unit\Content\FakeWpContentLoader();
        $loader->postResult = [
            'ID' => 7, 'post_title' => 'Tagged', 'post_content' => '', 'post_excerpt' => '',
            'post_name' => 'tagged', 'post_status' => 'publish', 'post_type' => 'post',
            'post_author' => '1', 'post_date_gmt' => '2024-01-01 00:00:00',
            'post_modified_gmt' => '2024-01-01 00:00:00',
        ];
        $loader->categoryIdsResult = [3];
        $loader->termIdsResult     = ['post_tag' => [2]];

        (new \HSP\Modules\Content\Handlers\PostUpsertHandler(
            $loader,
            new \HSP\Modules\Content\Extractors\PostExtractor(new \HSP\Modules\Content\Validation\PostValidator()),
            new \HSP\Modules\Content\Transformers\PostTransformer(),
            new \HSP\Modules\Content\Adapters\PostAdapter($this->db),
        ))->handle(new FakeTagEvent());

        $body = (new PostResource())->toArray(
            (new PostQueryProvider($this->db))->findBySlug('tagged')
        );

        self::assertSame(['php'], array_column($body['tags'], 'slug'), 'the pipeline linked the tag');

        // …and the post is still reachable through the tag filter.
        $page = (new PostQueryProvider($this->db))->list(new FilterSet(tagSlug: 'php', limit: 10));
        self::assertCount(1, $page->rows);

        // BOTH taxonomies survive the full-replace rewrite — a category-only rewrite would have
        // deleted the tag link.
        $links = (int) $this->db->query('SELECT COUNT(*) AS c FROM content.entity_taxonomies')[0]['c'];
        self::assertSame(2, $links, 'one category link and one tag link');
    }

    // =========================================================================
    // Cursor guarantees survive the join
    // =========================================================================

    public function test_paging_a_tag_filtered_listing_skips_and_duplicates_nothing(): void
    {
        $tagId = $this->seedTerm(2, 'php', 'PHP', 'post_tag');

        // Every post shares one published_at, so only the id tiebreaker separates them — the
        // case a naive cursor gets wrong, now exercised THROUGH the tag join.
        for ($i = 1; $i <= 25; $i++) {
            $post = $this->seedPost($i, 'post-' . $i, sharedTimestamp: true);
            $this->linkTerm($post, $tagId);
        }

        $provider = new PostQueryProvider($this->db);
        $seen     = [];
        $cursor   = null;

        do {
            $page = $provider->list(new FilterSet(tagSlug: 'php', cursor: $cursor, limit: 7));
            foreach ($page->rows as $row) {
                $seen[] = $row['slug'];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(25, $seen);
        self::assertCount(25, array_unique($seen));
    }

    // =========================================================================
    // Performance DoD — index-backed in BOTH directions
    // =========================================================================

    public function test_the_tag_filter_join_is_index_backed_in_both_directions(): void
    {
        // ALL THREE tables realistically sized, or the planner rightly prefers a scan and the
        // assertion proves nothing (the lesson P1B-S2 paid for). entity_taxonomies especially:
        // with a hundred link rows a sequential scan IS the cheap plan, so the index only has to
        // earn its place once the join table is big. Seeded set-based — 10,000 inserts in a PHP
        // loop would dominate the suite runtime for no extra coverage.
        $tagId = $this->seedTerm(2, 'php', 'PHP', 'post_tag');

        $this->db->execute(
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                 checksum, created_at, updated_at, synced_at)
             SELECT gen_random_uuid(), 1000 + g, 'post_tag', 'filler-' || g, 'Filler', '', 0, 0,
                    repeat('c', 64), now(), now(), now()
             FROM generate_series(1, 1000) g"
        );

        $this->db->execute(
            "INSERT INTO content.posts
                (id, source_post_id, source_entity_type, slug, title, content, excerpt, status,
                 author, featured_media_id, published_at, updated_at, checksum, meta_jsonb,
                 created_at, synced_at)
             SELECT gen_random_uuid(), 1000 + g, 'post', 'bulk-' || g, 'Post', '', '', 'publish',
                    'editor', 0, now() - (g || ' minutes')::interval, now(), repeat('a', 64),
                    '{}'::jsonb, now(), now()
             FROM generate_series(1, 1000) g"
        );

        // ~10,000 links across the filler tags, plus 100 posts carrying the tag under test.
        $this->db->execute(
            "INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id)
             SELECT p.id, t.id
             FROM content.posts p
             JOIN content.taxonomies t ON t.slug LIKE 'filler-%'
             WHERE (p.source_post_id + t.source_term_id) % 100 = 0
             ON CONFLICT DO NOTHING"
        );

        $this->db->execute(
            "INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id)
             SELECT p.id, \$1::uuid FROM content.posts p WHERE p.source_post_id % 10 = 0
             ON CONFLICT DO NOTHING",
            [$tagId]
        );

        $this->db->execute('ANALYZE content.posts');
        $this->db->execute('ANALYZE content.taxonomies');
        $this->db->execute('ANALYZE content.entity_taxonomies');

        $linkCount = (int) $this->db->query('SELECT COUNT(*) AS c FROM content.entity_taxonomies')[0]['c'];
        self::assertGreaterThan(
            5000,
            $linkCount,
            'the join table must be large enough that a sequential scan is genuinely the expensive plan',
        );

        $plan = $this->explain(
            "SELECT p.id
             FROM content.posts p
             WHERE p.deleted_at IS NULL AND p.status = 'publish'
               AND EXISTS (
                   SELECT 1 FROM content.entity_taxonomies et
                   JOIN content.taxonomies t ON t.id = et.taxonomy_id
                   WHERE et.entity_id = p.id AND t.slug = 'php'
                     AND t.taxonomy_type = 'post_tag' AND t.deleted_at IS NULL
               )
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT 21"
        );

        // term → entities rides idx_content_entity_taxonomies_taxonomy_id; the hop back to the
        // term rides the taxonomies PK. Neither may degrade to a full scan.
        self::assertStringNotContainsString('Seq Scan on entity_taxonomies', $plan, "Plan:\n{$plan}");
        self::assertStringNotContainsString('Seq Scan on taxonomies', $plan, "Plan:\n{$plan}");
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedTerm(int $sourceTermId, string $slug, string $name, string $type): string
    {
        $rows = $this->db->query(
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                 checksum, created_at, updated_at, synced_at)
             VALUES (gen_random_uuid(), \$1, \$2, \$3, \$4, '', 0, 0, repeat('c', 64), now(), now(), now())
             RETURNING id",
            [$sourceTermId, $type, $slug, $name]
        );

        return (string) $rows[0]['id'];
    }

    private function seedPost(int $sourceId, string $slug, bool $sharedTimestamp = false): string
    {
        $publishedAt = $sharedTimestamp
            ? '2024-01-01 00:00:00+00'
            : (new \DateTimeImmutable('2024-01-01T00:00:00Z'))->modify("+{$sourceId} minutes")->format('Y-m-d H:i:sP');

        $rows = $this->db->query(
            "INSERT INTO content.posts
                (id, source_post_id, source_entity_type, slug, title, content, excerpt, status,
                 author, featured_media_id, published_at, updated_at, checksum, meta_jsonb,
                 created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, 'post', \$2, 'Post', '', '', 'publish', 'editor', 0,
                     \$3::timestamptz, \$3::timestamptz, repeat('a', 64), '{}'::jsonb, now(), now())
             RETURNING id",
            [$sourceId, $slug, $publishedAt]
        );

        return (string) $rows[0]['id'];
    }

    private function linkTerm(string $entityId, string $taxonomyId): void
    {
        $this->db->execute(
            'INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id) VALUES ($1::uuid, $2::uuid)
             ON CONFLICT DO NOTHING',
            [$entityId, $taxonomyId]
        );
    }

    private function explain(string $sql): string
    {
        $rows = $this->db->query('EXPLAIN ' . $sql);

        return implode("\n", array_map(static fn (array $r): string => (string) reset($r), $rows));
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');

        foreach ([
            '0003_create_content_posts.sql',
            '0004_create_content_taxonomies.sql',
            '0005_create_content_entity_taxonomies.sql',
        ] as $file) {
            $sql = file_get_contents(__DIR__ . '/../../../modules/Content/Migrations/' . $file);
            self::assertIsString($sql, "migration {$file} must be readable");
            self::assertNotFalse(
                pg_query($this->pgConn, $sql),
                "migration {$file} must apply: " . pg_last_error($this->pgConn)
            );
        }

        // content.posts needs featured_media_id (P1B-S2) since the query provider selects it.
        ContentSchema::ensureFeaturedMediaSupport($this->pgConn);

        // The pipeline-driven regression test runs the REAL adapter, whose DECISION 3
        // transaction also writes system.processed_events and system.aggregate_versions.
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
    }

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: 'postgres';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'postgres';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'postgres';

        $conn = @pg_connect(
            "host={$host} port={$port} user={$user} password={$pass} dbname={$db}",
            PGSQL_CONNECT_FORCE_NEW
        );

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping tag integration tests.");
        }

        return $conn;
    }
}

/**
 * Minimal EventInterface double for driving the post upsert handler.
 */
final class FakeTagEvent implements \HSP\Core\Contracts\EventInterface
{
    public function getId(): string            { return '01900000-0000-7000-8000-000000000777'; }
    public function getEventType(): string     { return 'content.post.updated'; }
    public function getEventVersion(): int     { return 1; }
    public function getAggregateType(): string { return 'post'; }
    public function getAggregateId(): string   { return '7'; }
    public function getAggregateVersion(): int { return 1; }
    public function getPayload(): array        { return []; }
    public function getChecksum(): string      { return str_repeat('d', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable       { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string  { return null; }
}
