<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Handlers\PostUpsertHandler;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Support\ContentSchema;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Category references on posts against live PostgreSQL (Finding 003).
 *
 * What a fake connection cannot prove, and this file does:
 *   - the aggregate resolves REAL relationship rows through the DECISION AJ source-term join;
 *   - a category and a tag sharing a slug genuinely do not leak into each other's list;
 *   - gaining and losing a category through the REAL handler changes the published payload;
 *   - a relationship written BEFORE its term projected resolves the moment the term lands, with
 *     no rewrite of the post — the order-independence DECISION AJ bought, now claimed at read
 *     time rather than accidentally re-broken there;
 *   - a post in several categories still appears exactly ONCE in a page, and the cursor is
 *     unchanged;
 *   - the aggregate is index-backed at a realistic size.
 *
 * Schema comes from the REAL migration files.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class PostCategoryReferencesIntegrationTest extends TestCase
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
    // The capability: which categories does this post belong to?
    // =========================================================================

    public function test_a_post_publishes_the_single_category_it_belongs_to(): void
    {
        $etf  = $this->seedTerm(10, 'etf', 'ETF', 'category');
        $post = $this->seedPost(1, 'one-category');
        $this->linkTerm($post, $etf);

        self::assertSame(
            [['slug' => 'etf', 'name' => 'ETF']],
            $this->publish('one-category')['categories'],
        );
    }

    public function test_a_post_publishes_every_category_it_belongs_to_with_no_primary_designation(): void
    {
        $post = $this->seedPost(1, 'multi-category');
        foreach ([[10, 'sip', 'SIP'], [11, 'etf', 'ETF'], [12, 'index', 'Index']] as [$id, $slug, $name]) {
            $this->linkTerm($post, $this->seedTerm($id, $slug, $name, 'category'));
        }

        $categories = $this->publish('multi-category')['categories'];

        // ALL of them, ordered by slug. Not the first row, not the lowest term id, not one
        // elected "primary" — WordPress defines no such thing and the choice is the consumer's.
        self::assertSame(['etf', 'index', 'sip'], array_column($categories, 'slug'));
        foreach ($categories as $reference) {
            self::assertSame(['slug', 'name'], array_keys($reference));
        }
    }

    public function test_a_post_with_no_categories_publishes_an_empty_list(): void
    {
        $this->seedPost(1, 'uncategorised');

        self::assertSame([], $this->publish('uncategorised')['categories']);
    }

    // =========================================================================
    // Discrimination — one shared projection, two taxonomies
    // =========================================================================

    public function test_a_category_and_a_tag_sharing_a_slug_stay_in_their_own_lists(): void
    {
        // Legal in WordPress: slugs are unique only WITHIN a taxonomy. Without the taxonomy_type
        // predicate each list would contain both terms, and the bug stays invisible until a site
        // happens to name a tag and a category alike.
        $category = $this->seedTerm(10, 'shared', 'Shared Category', 'category');
        $tag      = $this->seedTerm(11, 'shared', 'Shared Tag', 'post_tag');
        $post     = $this->seedPost(1, 'collision');
        $this->linkTerm($post, $category);
        $this->linkTerm($post, $tag);

        $body = $this->publish('collision');

        self::assertSame([['slug' => 'shared', 'name' => 'Shared Category']], $body['categories']);
        self::assertSame([['slug' => 'shared', 'name' => 'Shared Tag']], $body['tags']);
    }

    public function test_a_soft_deleted_category_stops_being_published(): void
    {
        $etf  = $this->seedTerm(10, 'etf', 'ETF', 'category');
        $sip  = $this->seedTerm(11, 'sip', 'SIP', 'category');
        $post = $this->seedPost(1, 'tombstoned-category');
        $this->linkTerm($post, $etf);
        $this->linkTerm($post, $sip);

        $this->db->execute('UPDATE content.taxonomies SET deleted_at = now() WHERE id = $1', [$etf]);

        // The relationship row is deliberately left alone — it is the post's own state and
        // deleting it as a read workaround would lose the link if the term ever returns.
        self::assertSame(
            2,
            (int) $this->db->query(
                'SELECT COUNT(*) AS c FROM content.entity_taxonomies WHERE entity_id = $1::uuid',
                [$post]
            )[0]['c'],
            'both relationship rows are retained — the tombstone is filtered on the READ side, '
            . 'not worked around by deleting the post\'s own state',
        );
        self::assertSame(['sip'], array_column($this->publish('tombstoned-category')['categories'], 'slug'));
    }

    // =========================================================================
    // Through the REAL pipeline — gaining and losing a category
    // =========================================================================

    public function test_a_post_that_gains_a_category_publishes_it(): void
    {
        $this->seedTerm(10, 'etf', 'ETF', 'category');
        $this->projectPost(categoryIds: []);

        self::assertSame([], $this->publish('piped')['categories']);

        $this->projectPost(categoryIds: [10]);

        self::assertSame(['etf'], array_column($this->publish('piped')['categories'], 'slug'));
    }

    public function test_a_post_that_loses_a_category_stops_publishing_it(): void
    {
        $this->seedTerm(10, 'etf', 'ETF', 'category');
        $this->seedTerm(11, 'sip', 'SIP', 'category');

        $this->projectPost(categoryIds: [10, 11]);
        self::assertSame(['etf', 'sip'], array_column($this->publish('piped')['categories'], 'slug'));

        $this->projectPost(categoryIds: [11]);
        self::assertSame(['sip'], array_column($this->publish('piped')['categories'], 'slug'));
    }

    // =========================================================================
    // DECISION AJ order-independence, claimed at READ time
    // =========================================================================

    public function test_a_relationship_written_before_its_category_resolves_when_the_category_lands(): void
    {
        // The Finding 004 ordering, which is the NORMAL one on a backfill: the post projects
        // first and its category has not been projected yet. Under DECISION AJ the relationship
        // row is still written — it keys on the source term id, which exists whether or not the
        // term's projection row does.
        $this->projectPost(categoryIds: [10]);

        self::assertSame(
            1,
            (int) $this->db->query('SELECT COUNT(*) AS c FROM content.entity_taxonomies')[0]['c'],
            'the link is persisted even though the category has not projected yet (DECISION AJ)',
        );
        self::assertSame([], $this->publish('piped')['categories'], 'and cannot resolve to a name yet');

        $before = $this->db->query('SELECT checksum, synced_at FROM content.posts WHERE slug = $1', ['piped'])[0];

        // The category projects LATER, through its own pipeline. The post is NOT touched.
        $this->seedTerm(10, 'etf', 'ETF', 'category');

        self::assertSame(
            ['etf'],
            array_column($this->publish('piped')['categories'], 'slug'),
            'the same unmodified post row now resolves its category — the read path inherits '
            . "AJ's order-independence instead of recreating the ordering dependency",
        );

        $after = $this->db->query('SELECT checksum, synced_at FROM content.posts WHERE slug = $1', ['piped'])[0];
        self::assertSame($before, $after, 'no rewrite of the post was required');
    }

    // =========================================================================
    // Pagination — a multi-category post is still ONE row
    // =========================================================================

    public function test_a_post_in_several_categories_appears_exactly_once_in_a_listing(): void
    {
        foreach ([[10, 'etf'], [11, 'sip'], [12, 'index']] as [$id, $slug]) {
            $this->seedTerm($id, $slug, strtoupper($slug), 'category');
        }

        for ($i = 1; $i <= 5; $i++) {
            $post = $this->seedPost($i, 'post-' . $i);
            foreach ([10, 11, 12] as $termId) {
                $this->db->execute(
                    'INSERT INTO content.entity_taxonomies (entity_id, source_term_id)
                     VALUES ($1::uuid, $2) ON CONFLICT DO NOTHING',
                    [$post, $termId]
                );
            }
        }

        $page = (new PostQueryProvider($this->db))->list(new ContentFilterSet(limit: 50));

        self::assertCount(5, $page->rows, 'three categories each must not become fifteen rows');
        self::assertCount(5, array_unique(array_column($page->rows, 'slug')));
        self::assertNull($page->nextCursor, 'five of five fit in one page — no phantom next page');
        foreach ($page->rows as $row) {
            self::assertCount(3, (new PostResource())->toArray($row)['categories']);
        }
    }

    public function test_paging_with_category_references_skips_and_duplicates_nothing(): void
    {
        $etf = $this->seedTerm(10, 'etf', 'ETF', 'category');
        $sip = $this->seedTerm(11, 'sip', 'SIP', 'category');

        // One shared published_at, so only the id tiebreaker separates the rows — the case a
        // naive cursor gets wrong, now exercised with the category aggregate present.
        for ($i = 1; $i <= 25; $i++) {
            $post = $this->seedPost($i, 'post-' . $i, sharedTimestamp: true);
            $this->linkTerm($post, $etf);
            if ($i % 2 === 0) {
                $this->linkTerm($post, $sip);
            }
        }

        $provider = new PostQueryProvider($this->db);
        $seen     = [];
        $cursor   = null;
        $pages    = 0;

        do {
            $page = $provider->list(new ContentFilterSet(cursor: $cursor, limit: 7));
            foreach ($page->rows as $row) {
                $seen[] = $row['slug'];
            }
            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        self::assertCount(25, $seen);
        self::assertCount(25, array_unique($seen));
    }

    // =========================================================================
    // Performance — one query, index-backed
    // =========================================================================

    public function test_publishing_categories_costs_exactly_one_query_for_a_whole_page(): void
    {
        $etf = $this->seedTerm(10, 'etf', 'ETF', 'category');
        for ($i = 1; $i <= 20; $i++) {
            $this->linkTerm($this->seedPost($i, 'post-' . $i), $etf);
        }

        $counting = new CountingPgConnection($this->pgConn);
        $page     = (new PostQueryProvider($counting))->list(new ContentFilterSet(limit: 20));

        self::assertCount(20, $page->rows);
        self::assertSame(
            1,
            $counting->queries,
            'Categories are aggregated in the same round-trip as the posts; one query per post '
            . 'would make this page cost 21.',
        );
        foreach ($page->rows as $row) {
            self::assertSame(['etf'], array_column((new PostResource())->toArray($row)['categories'], 'slug'));
        }
    }

    /**
     * The relationship lookup — the half that scales with CONTENT volume — must be index-backed.
     *
     * This is the assertion that matters at the 100,000-record target: the aggregate runs once
     * per post row in the page, so a sequential scan of the link table would cost page_size ×
     * every link on the site.
     */
    public function test_the_post_to_link_lookup_is_index_backed(): void
    {
        $this->seedRealisticCorpus(terms: 1000);

        self::assertGreaterThan(
            5000,
            (int) $this->db->query('SELECT COUNT(*) AS c FROM content.entity_taxonomies')[0]['c'],
            'the join table must be large enough that a sequential scan is genuinely the expensive plan',
        );

        $plan = $this->explainAggregate();

        self::assertStringContainsString('pk_content_entity_taxonomies', $plan, "Plan:\n{$plan}");
        self::assertStringNotContainsString('Seq Scan on entity_taxonomies', $plan, "Plan:\n{$plan}");
    }

    /**
     * The hop from a link to its TERM has an index path, and the planner takes it once the term
     * table is big enough for it to matter.
     *
     * Deliberately asserted at a large term count rather than a small one. `content.taxonomies`
     * holds terms, not content — a real site has tens or hundreds — and at that size PostgreSQL
     * correctly prices a scan of the small table below repeated index lookups. Asserting "never a
     * seq scan" would therefore be asserting that the planner makes the WRONG choice on a normal
     * site. What must be true is that the access path exists and is used when it earns its keep,
     * which is what this pins: at 50,000 terms the plan is a Memoized index scan on
     * uq_content_taxonomies_source_term_id, needing no new index and no migration.
     */
    public function test_the_link_to_term_hop_uses_the_source_term_index_when_terms_are_many(): void
    {
        $this->seedRealisticCorpus(terms: 50_000);

        $plan = $this->explainAggregate();

        self::assertStringContainsString(
            'uq_content_taxonomies_source_term_id',
            $plan,
            "The term hop must ride the existing unique index once the term table is large.\nPlan:\n{$plan}",
        );
    }

    /** 1,000 posts, `$terms` category terms, ~100 links per post. Set-based — a PHP loop here
     *  would dominate the suite runtime for no extra coverage. */
    private function seedRealisticCorpus(int $terms): void
    {
        $this->db->execute(
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                 checksum, created_at, updated_at, synced_at)
             SELECT gen_random_uuid(), 1000 + g, 'category', 'filler-' || g, 'Filler', '', 0, 0,
                    repeat('c', 64), now(), now(), now()
             FROM generate_series(1, \$1) g",
            [$terms]
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

        $this->db->execute(
            "INSERT INTO content.entity_taxonomies (entity_id, source_term_id)
             SELECT p.id, t.source_term_id
             FROM content.posts p
             JOIN content.taxonomies t ON t.taxonomy_type = 'category'
             WHERE t.source_term_id % GREATEST(\$1 / 100, 1) = 0
             ON CONFLICT DO NOTHING",
            [$terms]
        );

        foreach (['content.posts', 'content.taxonomies', 'content.entity_taxonomies'] as $table) {
            $this->db->execute('ANALYZE ' . $table);
        }
    }

    /** The exact aggregate PostQueryProvider runs, over a realistic listing page. */
    private function explainAggregate(): string
    {
        return $this->explain(
            "SELECT p.id,
                    COALESCE((
                        SELECT json_agg(json_build_object('slug', t.slug, 'name', t.name) ORDER BY t.slug)
                        FROM content.entity_taxonomies et
                        JOIN content.taxonomies t ON t.source_term_id = et.source_term_id
                        WHERE et.entity_id = p.id
                          AND t.taxonomy_type = 'category'
                          AND t.deleted_at IS NULL
                    ), '[]') AS categories_json
             FROM content.posts p
             WHERE p.deleted_at IS NULL AND p.status = 'publish'
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT 21"
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<string,mixed> */
    private function publish(string $slug): array
    {
        $row = (new PostQueryProvider($this->db))->findBySlug($slug);
        self::assertNotNull($row, "post {$slug} must be served");

        return (new PostResource())->toArray($row);
    }

    /**
     * Drive the REAL handler → extractor → transformer → adapter path, so the relationship rows
     * are produced by the pipeline rather than hand-seeded (the blind spot Finding 004 exposed).
     *
     * @param list<int> $categoryIds
     */
    private function projectPost(array $categoryIds): void
    {
        $loader = new FakeWpContentLoader();
        $loader->postResult = [
            'ID' => 7, 'post_title' => 'Piped', 'post_content' => '', 'post_excerpt' => '',
            'post_name' => 'piped', 'post_status' => 'publish', 'post_type' => 'post',
            'post_author' => '1', 'post_date_gmt' => '2024-01-01 00:00:00',
            'post_modified_gmt' => '2024-01-01 00:00:00',
        ];
        $loader->categoryIdsResult = $categoryIds;
        $loader->termIdsResult     = ['post_tag' => []];

        (new PostUpsertHandler(
            $loader,
            new PostExtractor(new PostValidator()),
            new PostTransformer(),
            new PostAdapter($this->db),
        ))->handle(new FakePostCategoryEvent());
    }

    private function seedTerm(int $sourceTermId, string $slug, string $name, string $type): string
    {
        return (string) $this->db->query(
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                 checksum, created_at, updated_at, synced_at)
             VALUES (gen_random_uuid(), \$1, \$2, \$3, \$4, '', 0, 0, repeat('c', 64), now(), now(), now())
             RETURNING id",
            [$sourceTermId, $type, $slug, $name]
        )[0]['id'];
    }

    private function seedPost(int $sourceId, string $slug, bool $sharedTimestamp = false): string
    {
        $publishedAt = $sharedTimestamp
            ? '2024-01-01 00:00:00+00'
            : (new \DateTimeImmutable('2024-01-01T00:00:00Z'))->modify("+{$sourceId} minutes")->format('Y-m-d H:i:sP');

        return (string) $this->db->query(
            "INSERT INTO content.posts
                (id, source_post_id, source_entity_type, slug, title, content, excerpt, status,
                 author, featured_media_id, published_at, updated_at, checksum, meta_jsonb,
                 created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, 'post', \$2, 'Post', '', '', 'publish', 'editor', 0,
                     \$3::timestamptz, \$3::timestamptz, repeat('a', 64), '{}'::jsonb, now(), now())
             RETURNING id",
            [$sourceId, $slug, $publishedAt]
        )[0]['id'];
    }

    /** Link by the term's projection UUID; the row itself stores the term's SOURCE id (0009). */
    private function linkTerm(string $entityId, string $taxonomyId): void
    {
        $this->db->execute(
            'INSERT INTO content.entity_taxonomies (entity_id, source_term_id)
             SELECT $1::uuid, t.source_term_id FROM content.taxonomies t WHERE t.id = $2::uuid
             ON CONFLICT DO NOTHING',
            [$entityId, $taxonomyId]
        );
    }

    private function explain(string $sql): string
    {
        return implode("\n", array_map(
            static fn (array $r): string => (string) reset($r),
            $this->db->query('EXPLAIN ' . $sql)
        ));
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');

        foreach ([
            '0003_create_content_posts.sql',
            '0004_create_content_taxonomies.sql',
            '0005_create_content_entity_taxonomies.sql',
            '0008_align_content_taxonomy_indexes.sql',
            '0009_align_content_entity_taxonomies_to_source_term_id.sql',
        ] as $file) {
            $sql = file_get_contents(__DIR__ . '/../../../modules/Content/Migrations/' . $file);
            self::assertIsString($sql, "migration {$file} must be readable");
            self::assertNotFalse(
                pg_query($this->pgConn, $sql),
                "migration {$file} must apply: " . pg_last_error($this->pgConn)
            );
        }

        ContentSchema::ensureFeaturedMediaSupport($this->pgConn);

        // The pipeline-driven tests run the REAL adapter, whose DECISION 3 transaction also
        // writes system.processed_events and system.aggregate_versions.
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
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping category-reference tests.");
        }

        return $conn;
    }
}

/** Minimal EventInterface double for driving the post upsert handler. */
final class FakePostCategoryEvent implements \HSP\Core\Contracts\EventInterface
{
    private static int $seq = 0;

    private readonly string $id;

    public function __construct()
    {
        // A fresh event id per projection, so a second run is a genuine new event rather than a
        // replay the adapter is entitled to skip.
        $this->id = sprintf('01900000-0000-7000-8000-%012d', ++self::$seq);
    }

    public function getId(): string            { return $this->id; }
    public function getEventType(): string     { return 'content.post.updated'; }
    public function getEventVersion(): int     { return 1; }
    public function getAggregateType(): string { return 'post'; }
    public function getAggregateId(): string   { return '7'; }
    public function getAggregateVersion(): int { return self::$seq; }
    public function getPayload(): array        { return []; }
    public function getChecksum(): string      { return str_repeat('d', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable       { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string  { return null; }
}
