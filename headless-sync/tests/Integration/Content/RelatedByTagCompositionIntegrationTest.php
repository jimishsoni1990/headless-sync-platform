<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Handlers\CategoryUpsertHandler;
use HSP\Modules\Content\Handlers\PostUpsertHandler;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Support\ContentSchema;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Related-by-tag composition from public contracts alone (Finding 002).
 *
 * The original frontend finding was that a post-detail page needed related content and HSP
 * published no `related_posts` relationship. The conclusion of the investigation is that it
 * should not: WordPress defines no universal related-post rule, so "related" is an editorial
 * policy the site owns. What HSP owes the consumer is the FACTS — which tags a post carries, and
 * a way to ask for other posts carrying one — and this file proves those facts are sufficient.
 *
 * THE RULE THIS FILE OBEYS. Every assertion reads what a consumer over HTTP would receive: the
 * PostResource payload, nothing else. No projection UUID, no source_post_id, no source_term_id,
 * no WordPress term id and no query against the projection tables is allowed to reach the
 * composition helper below — if any of those were needed, the contract would be insufficient and
 * this file would be unable to express the workflow at all.
 *
 * AND WHAT IT DOES NOT ESTABLISH. `relatedTo()` is a TEST policy — lexicographically first tag,
 * exclude the source, take N. It exists to prove the API is sufficient, and it is deliberately
 * not in production code. HSP publishes no primary tag, no relevance ranking and no related-post
 * contract; a different site may reasonably choose a different tag, a different count, or not to
 * use tags at all.
 *
 * Convergence is driven through the REAL handler -> extractor -> transformer -> adapter path.
 * Nothing here hand-seeds a relationship row: the Finding 004 lesson is that a hand-seeded join
 * table proves the query and hides the pipeline.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class RelatedByTagCompositionIntegrationTest extends TestCase
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
    // The workflow the finding asked about
    // =========================================================================

    public function test_a_consumer_composes_related_posts_from_public_payloads_alone(): void
    {
        $this->seedCorpus();

        // 1. The consumer holds the post it is rendering — GET /posts/{slug}.
        $source = $this->fetchPost('post-a');

        // 2. Its tags are typed public references, so the policy can read them without guessing.
        self::assertSame(['crypto', 'stocks'], array_column($source['tags'], 'slug'));
        self::assertSame(['crypto', 'stocks'], array_column($source['tags'], 'name'));

        // 3..5. Choose a tag, ask for that tag's posts, drop the source post.
        $related = $this->relatedTo($source, take: 3);

        self::assertSame(['post-d', 'post-b'], array_column($related, 'slug'));
        self::assertNotContains('post-a', array_column($related, 'slug'), 'the source post is gone');
        self::assertNotContains(
            'post-c',
            array_column($related, 'slug'),
            'post-c carries `stocks`, not the chosen tag — the filter is doing the selecting',
        );
    }

    public function test_the_composition_needs_no_identifier_beyond_two_slugs(): void
    {
        // The sufficiency claim, stated as an assertion rather than as prose: strip the payload
        // down to the two fields the policy actually reads and it still produces the same set.
        $this->seedCorpus();

        $full = $this->relatedTo($this->fetchPost('post-a'), take: 3);

        $source    = $this->fetchPost('post-a');
        $minimal   = ['slug' => $source['slug'], 'tags' => $source['tags']];
        $minimalOut = $this->relatedTo($minimal, take: 3);

        self::assertSame(array_column($full, 'slug'), array_column($minimalOut, 'slug'));

        foreach (['id', 'source_post_id', 'source_term_id', 'term_id', 'taxonomy_id'] as $internal) {
            self::assertArrayNotHasKey($internal, $source);
            self::assertArrayNotHasKey($internal, $source['tags'][0]);
        }
    }

    public function test_an_untagged_post_yields_no_related_posts_under_this_policy(): void
    {
        // The empty branch is contract, not an accident: `tags: []` (never null) is what lets the
        // consumer decide "no related content" without a request and without a null check.
        $this->seedCorpus();

        $source = $this->fetchPost('post-e');

        self::assertSame([], $source['tags']);
        self::assertSame([], $this->relatedTo($source, take: 3));
    }

    public function test_a_tag_shared_with_a_category_never_pulls_in_the_category_members(): void
    {
        // WordPress guarantees slug uniqueness only WITHIN a taxonomy, and the live site really
        // does own a tag `crypto` and a category `crypto`. post-f is in the CATEGORY crypto and
        // carries no tags at all; without the taxonomy_type predicate it would surface as a
        // related post and nobody would notice until a site named both alike.
        $this->seedCorpus();

        $related = $this->relatedTo($this->fetchPost('post-a'), take: 10);

        self::assertNotContains('post-f', array_column($related, 'slug'));
        self::assertSame(['crypto'], array_column($this->fetchPost('post-f')['categories'], 'slug'));
        self::assertSame([], $this->fetchPost('post-f')['tags']);
    }

    public function test_a_multi_tag_post_publishes_its_tags_in_the_documented_slug_order(): void
    {
        // Deterministic, so a consumer choosing "the first tag" gets the same answer on every
        // request and its cached responses do not churn. Deterministic is NOT primary: HSP makes
        // no claim that `crypto` matters more to this post than `stocks`.
        $this->seedCorpus();

        self::assertSame(['crypto', 'stocks'], array_column($this->fetchPost('post-a')['tags'], 'slug'));

        // Same relationship set, opposite source order — the published order does not follow it.
        $this->projectPost(1, 'post-a', tagIds: [21, 20], categoryIds: [], version: 2);

        self::assertSame(['crypto', 'stocks'], array_column($this->fetchPost('post-a')['tags'], 'slug'));
    }

    // =========================================================================
    // Convergence through the real pipeline
    // =========================================================================

    public function test_tagging_a_post_at_source_makes_it_appear_in_both_surfaces(): void
    {
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        $this->projectPost(1, 'post-a', tagIds: [], categoryIds: []);

        self::assertSame([], $this->fetchPost('post-a')['tags']);
        self::assertSame([], $this->filterByTag('crypto'));

        // The editor adds the tag; the ordinary post handler runs, nothing tag-specific.
        $this->projectPost(1, 'post-a', tagIds: [20], categoryIds: [], version: 2);

        self::assertSame(['crypto'], array_column($this->fetchPost('post-a')['tags'], 'slug'));
        self::assertSame(['post-a'], $this->filterByTag('crypto'));
    }

    public function test_untagging_a_post_at_source_removes_it_from_both_surfaces(): void
    {
        // The half that a full-replace join rewrite exists for, and the half an "add" test cannot
        // see: a shrinking term set has to DELETE links, not merely fail to insert them.
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        $this->projectTerm(21, 'stocks', 'stocks', 'post_tag');
        $this->projectPost(1, 'post-a', tagIds: [20, 21], categoryIds: []);

        self::assertSame(['crypto', 'stocks'], array_column($this->fetchPost('post-a')['tags'], 'slug'));

        $this->projectPost(1, 'post-a', tagIds: [21], categoryIds: [], version: 2);

        self::assertSame(['stocks'], array_column($this->fetchPost('post-a')['tags'], 'slug'));
        self::assertSame([], $this->filterByTag('crypto'), 'and the filter stops returning it');
        self::assertSame(['post-a'], $this->filterByTag('stocks'), 'while the kept tag is untouched');
    }

    public function test_removing_the_last_tag_leaves_an_empty_list_not_a_stale_one(): void
    {
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        $this->projectPost(1, 'post-a', tagIds: [20], categoryIds: []);
        $this->projectPost(1, 'post-a', tagIds: [], categoryIds: [], version: 2);

        self::assertSame([], $this->fetchPost('post-a')['tags']);
        self::assertSame([], $this->filterByTag('crypto'));
    }

    // =========================================================================
    // Cursor behaviour under client-side exclusion
    // =========================================================================

    public function test_paging_a_tag_filtered_listing_preserves_the_predicate_and_the_source_post_can_be_dropped(): void
    {
        // The realistic shape of the problem: the source post is itself one of the tag's posts,
        // so it occupies a result slot on some page, and the consumer wants N related posts after
        // dropping it. Fetching another page is ordinary cursor behaviour — the API is not bent
        // to guarantee an exact count after a client-side filter.
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        $this->projectTerm(21, 'stocks', 'stocks', 'post_tag');

        for ($i = 1; $i <= 12; $i++) {
            $this->projectPost($i, 'crypto-' . $i, tagIds: [20], categoryIds: []);
        }
        // Decoys on the other tag: a leak would show up as one of these in the walk.
        for ($i = 20; $i <= 24; $i++) {
            $this->projectPost($i, 'stocks-' . $i, tagIds: [21], categoryIds: []);
        }

        $sourceSlug = 'crypto-7';
        $seen       = [];
        $pages      = 0;
        $cursor     = null;

        do {
            $page = (new PostQueryProvider($this->db))
                ->list(new ContentFilterSet(tagSlug: 'crypto', cursor: $cursor, limit: 5));

            foreach ((new PostResource())->toCollection($page->rows, $page->nextCursor)['data'] as $body) {
                self::assertStringStartsWith('crypto-', $body['slug'], 'no leakage from the other tag');
                $seen[] = $body['slug'];
            }

            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null);

        self::assertSame(3, $pages, '12 matching posts at 5 per page');
        self::assertCount(12, $seen);
        self::assertCount(12, array_unique($seen), 'no duplicates across page boundaries');
        self::assertNull($cursor, 'the terminal cursor is null');
        self::assertContains($sourceSlug, $seen, 'the source post really is inside the result set');

        // Client-side exclusion removes exactly one row and disturbs nothing else.
        $related = array_values(array_filter($seen, static fn (string $s): bool => $s !== $sourceSlug));

        self::assertCount(11, $related);
        self::assertCount(11, array_unique($related));
    }

    public function test_three_related_posts_can_need_a_second_page_and_that_is_normal(): void
    {
        // Exactly the case a consumer hits with a 3-post widget: page 1 holds the source post, so
        // it yields two candidates and the consumer follows next_cursor for the third. The point
        // is that this WORKS from the public contract, not that HSP should compensate for it.
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        for ($i = 1; $i <= 6; $i++) {
            $this->projectPost($i, 'crypto-' . $i, tagIds: [20], categoryIds: []);
        }

        $source    = $this->fetchPost('crypto-5');
        $collected = [];
        $cursor    = null;
        $requests  = 0;

        do {
            $page = (new PostQueryProvider($this->db))
                ->list(new ContentFilterSet(tagSlug: 'crypto', cursor: $cursor, limit: 3));
            $body = (new PostResource())->toCollection($page->rows, $page->nextCursor);
            $requests++;

            if ($requests === 1) {
                self::assertSame(
                    ['crypto-6', 'crypto-5', 'crypto-4'],
                    array_column($body['data'], 'slug'),
                    'page one really does spend a slot on the source post',
                );
            }

            foreach ($body['data'] as $candidate) {
                if ($candidate['slug'] !== $source['slug']) {
                    $collected[] = $candidate['slug'];
                }
            }

            $cursor = $body['next_cursor'];
        } while ($cursor !== null && count($collected) < 3);

        self::assertSame(2, $requests, 'the third candidate came from the second page');
        self::assertSame(['crypto-6', 'crypto-4', 'crypto-3'], array_slice($collected, 0, 3));
    }

    public function test_the_filtered_listing_is_ordinary_newest_first_post_order(): void
    {
        // What the published ordering guarantee (Finding 002 B2) means for this workflow: the
        // filter selects, it does not re-rank. A consumer may present these as "related", but the
        // order it receives is publication order and HSP claims nothing more.
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        $this->projectPost(1, 'oldest', tagIds: [20], categoryIds: [], publishedAt: '2024-01-01T00:00:00Z');
        $this->projectPost(2, 'middle', tagIds: [20], categoryIds: [], publishedAt: '2024-02-01T00:00:00Z');
        $this->projectPost(3, 'newest', tagIds: [20], categoryIds: [], publishedAt: '2024-03-01T00:00:00Z');

        self::assertSame(['newest', 'middle', 'oldest'], $this->filterByTag('crypto'));
    }

    public function test_posts_sharing_a_publication_timestamp_do_not_shuffle_between_requests(): void
    {
        // The other half of the published guarantee. Without the tie-breaker these rows could
        // come back in any order, and a "related posts" block would reorder itself at random on
        // every render while the cursor silently skipped or repeated rows.
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        for ($i = 1; $i <= 6; $i++) {
            $this->projectPost($i, 'same-time-' . $i, tagIds: [20], categoryIds: [], spread: false);
        }

        $first = $this->filterByTag('crypto');

        self::assertCount(6, $first);
        self::assertSame($first, $this->filterByTag('crypto'), 'stable across requests');
        self::assertSame($first, $this->filterByTag('crypto'));
    }

    // =========================================================================
    // The reference consumer policy — TEST-ONLY, never production HSP behaviour
    // =========================================================================

    /**
     * One site's related-content rule, written against the published JSON and nothing else.
     *
     * "Lexicographically first tag slug" is an arbitrary but explicit choice this test makes. It
     * is NOT a claim that the first tag is primary — HSP publishes tags in slug order so the
     * array is stable, and stability is what lets a policy like this one be reproducible. A
     * different site may prefer a configured tag, the union of all tags, or no tag rule at all.
     *
     * @param array<string,mixed> $post  a public post payload: needs only `slug` and `tags`
     * @return list<array<string,mixed>> public post payloads, source post removed
     */
    private function relatedTo(array $post, int $take): array
    {
        if ($post['tags'] === []) {
            return [];
        }

        $slugs = array_column($post['tags'], 'slug');
        sort($slugs);

        $page = (new PostQueryProvider($this->db))
            ->list(new ContentFilterSet(tagSlug: $slugs[0], limit: $take + 1));

        $candidates = (new PostResource())->toCollection($page->rows, $page->nextCursor)['data'];

        $related = array_values(array_filter(
            $candidates,
            static fn (array $candidate): bool => $candidate['slug'] !== $post['slug'],
        ));

        return array_slice($related, 0, $take);
    }

    // =========================================================================
    // Corpus + helpers
    // =========================================================================

    /**
     * The finding's fixture, plus the collision the live site actually has.
     *
     *   post-a  tags: crypto, stocks          (the post being rendered)
     *   post-b  tags: crypto
     *   post-c  tags: stocks
     *   post-d  tags: crypto, gold
     *   post-e  no tags
     *   post-f  CATEGORY crypto, no tags      (must never be "related")
     */
    private function seedCorpus(): void
    {
        $this->projectTerm(20, 'crypto', 'crypto', 'post_tag');
        $this->projectTerm(21, 'stocks', 'stocks', 'post_tag');
        $this->projectTerm(22, 'gold', 'gold', 'post_tag');
        $this->projectTerm(10, 'crypto', 'Crypto', 'category');

        $this->projectPost(1, 'post-a', tagIds: [20, 21], categoryIds: []);
        $this->projectPost(2, 'post-b', tagIds: [20], categoryIds: []);
        $this->projectPost(3, 'post-c', tagIds: [21], categoryIds: []);
        $this->projectPost(4, 'post-d', tagIds: [20, 22], categoryIds: []);
        $this->projectPost(5, 'post-e', tagIds: [], categoryIds: []);
        $this->projectPost(6, 'post-f', tagIds: [], categoryIds: [10]);
    }

    /** @return array<string,mixed> the public payload for one post */
    private function fetchPost(string $slug): array
    {
        $row = (new PostQueryProvider($this->db))->findBySlug($slug);
        self::assertNotNull($row, "no post {$slug}");

        return (new PostResource())->toArray($row);
    }

    /** @return list<string> the public slugs a tag filter returns, in published order */
    private function filterByTag(string $tagSlug, int $limit = 50): array
    {
        $page = (new PostQueryProvider($this->db))
            ->list(new ContentFilterSet(tagSlug: $tagSlug, limit: $limit));

        return array_column(
            (new PostResource())->toCollection($page->rows, $page->nextCursor)['data'],
            'slug'
        );
    }

    /**
     * @param list<int> $tagIds
     * @param list<int> $categoryIds
     */
    private function projectPost(
        int $sourceId,
        string $slug,
        array $tagIds,
        array $categoryIds,
        int $version = 1,
        string $publishedAt = '2024-01-01T00:00:00Z',
        bool $spread = true,
    ): void {
        $at = new \DateTimeImmutable($publishedAt);

        if ($spread) {
            $at = $at->modify("+{$sourceId} minutes");
        }

        $loader = new FakeWpContentLoader();
        $loader->postResult = [
            'ID'                => $sourceId,
            'post_title'        => 'Post ' . $sourceId,
            'post_content'      => '',
            'post_excerpt'      => '',
            'post_name'         => $slug,
            'post_status'       => 'publish',
            'post_type'         => 'post',
            'post_author'       => '1',
            'post_date_gmt'     => $at->format('Y-m-d H:i:s'),
            'post_modified_gmt' => $at->format('Y-m-d H:i:s'),
        ];
        $loader->categoryIdsResult = $categoryIds;
        $loader->termIdsResult     = ['post_tag' => $tagIds];

        (new PostUpsertHandler(
            $loader,
            new PostExtractor(new PostValidator()),
            new PostTransformer(),
            new PostAdapter($this->db),
        ))->handle(new FakeRelatedEvent('content.post.updated', 'post', (string) $sourceId, $version));
    }

    private function projectTerm(int $termId, string $slug, string $name, string $taxonomyType): void
    {
        $loader = new FakeWpContentLoader();
        $loader->termResult = [
            'term_id'     => $termId,
            'name'        => $name,
            'slug'        => $slug,
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
            'taxonomy'    => $taxonomyType,
        ];

        (new CategoryUpsertHandler(
            $loader,
            new CategoryExtractor(new CategoryValidator()),
            new CategoryTransformer(),
            new CategoryAdapter($this->db),
        ))->handle(new FakeRelatedEvent('content.category.updated', 'category', (string) $termId, 1));
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
        if (! extension_loaded('pgsql')) {
            self::markTestSkipped('pgsql extension not loaded');
        }

        $host = getenv('HSP_TEST_PGSQL_HOST');
        $db   = getenv('HSP_TEST_PGSQL_DATABASE');

        if ($host === false || $db === false) {
            self::markTestSkipped('HSP_TEST_PGSQL_* not configured');
        }

        $conn = @pg_connect(sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            $host,
            getenv('HSP_TEST_PGSQL_PORT') ?: '5432',
            $db,
            getenv('HSP_TEST_PGSQL_USER') ?: 'postgres',
            getenv('HSP_TEST_PGSQL_PASSWORD') ?: ''
        ));

        if ($conn === false) {
            self::markTestSkipped('PostgreSQL not reachable');
        }

        return $conn;
    }
}

/** Minimal event envelope — the handlers reload WordPress state, so the payload is empty (ADR-044). */
final class FakeRelatedEvent implements EventInterface
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly int $aggregateVersion,
    ) {}

    /**
     * Distinct per (aggregate, version) so system.processed_events does not collide across the
     * several persists one test drives — but STABLE, so re-delivering the same version is the
     * genuine redelivery the suppress path has to cope with.
     */
    public function getId(): string
    {
        return sprintf(
            '01900000-0000-7000-8000-%012d',
            crc32($this->aggregateType . $this->aggregateId . $this->aggregateVersion) % 1000000000000
        );
    }

    public function getEventType(): string     { return $this->eventType; }
    public function getEventVersion(): int     { return 1; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string   { return $this->aggregateId; }
    public function getAggregateVersion(): int { return $this->aggregateVersion; }

    /** @return array<string,mixed> */
    public function getPayload(): array        { return []; }

    public function getChecksum(): string      { return str_repeat('e', 64); }

    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable       { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string  { return null; }
}
