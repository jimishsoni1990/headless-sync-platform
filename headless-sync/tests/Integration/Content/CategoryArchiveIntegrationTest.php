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
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Support\ContentSchema;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * The category archive, end to end through the REAL pipeline (Finding 004).
 *
 * WHY THIS FILE EXISTS. A populated category served an empty archive on a live site:
 * /categories/etf reported post_count 6 while /posts?category=etf returned []. The category
 * filter already had coverage — and it passed, because every one of those tests hand-seeded
 * content.entity_taxonomies with exactly the rows the query wanted. The pipeline never produced
 * them. So the rule here is: NOTHING seeds a link row. Every link in this class is produced by
 * the real handler -> transformer -> adapter path, and every assertion reads back through the
 * real query provider.
 *
 * The failing ARRIVAL ORDER is the point of the first test: under at-least-once, non-FIFO
 * delivery a post routinely projects before its terms, and on the site that surfaced this every
 * post projected before every category. Storing the link by the term's PROJECTION UUID made that
 * order permanently lossy; storing the SOURCE term id makes it a non-event (DECISION AJ, which
 * amends the frozen FLAG-P1AS4-1 shape; migration 0009).
 *
 * The relationship-witness cases below exist because the first fix used CARDINALITY equality, and
 * cardinality does not prove set equality: [10, 20] and [10, 30] have the same count and are not
 * the same projection (AJ-1).
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class CategoryArchiveIntegrationTest extends TestCase
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
    // The reported failure
    // =========================================================================

    public function test_a_populated_category_returns_its_posts_when_the_posts_projected_first(): void
    {
        // THE EXACT PRODUCTION ORDER. Every post projects before any category exists — which is
        // what the live backfill did, and what left the archive empty forever: the link write
        // found no taxonomy row to point at, and every later replay recomputed the same checksum
        // and was suppressed, so the links could never appear.
        $this->projectPost(39, 'first', categoryIds: [10]);
        $this->projectPost(42, 'second', categoryIds: [10]);
        $this->projectPost(45, 'third', categoryIds: [10]);

        self::assertSame(3, $this->linkCount(), 'links are written from the post state alone');

        // The categories arrive afterwards. Nothing back-links; nothing needs to.
        $this->projectCategory(10, 'etf', 'ETF', count: 3);

        $rows = $this->listByCategory('etf');

        self::assertSame(['third', 'second', 'first'], array_column($rows, 'slug'));
    }

    public function test_a_post_that_arrives_after_its_category_is_linked_too(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectPost(39, 'after', categoryIds: [10]);

        self::assertSame(['after'], array_column($this->listByCategory('etf'), 'slug'));
    }

    public function test_both_event_orderings_converge_to_identical_delivery_state(): void
    {
        // THE INVARIANT DECISION AJ EXISTS TO ESTABLISH. Not "both orderings eventually work" —
        // both orderings produce the SAME projection, compared field by field, so arrival order
        // leaves no trace in delivery state at all.
        $this->projectPost(39, 'converging', categoryIds: [10, 11]);
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');

        $postFirst = $this->deliveryState('etf');

        // Same inputs, opposite order, from a clean slate.
        $this->db->execute('DELETE FROM content.entity_taxonomies');
        $this->db->execute('DELETE FROM content.posts');
        $this->db->execute('DELETE FROM content.taxonomies');
        $this->db->execute('DELETE FROM system.aggregate_versions');
        $this->db->execute('DELETE FROM system.processed_events');

        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'converging', categoryIds: [10, 11]);

        $taxonomyFirst = $this->deliveryState('etf');

        self::assertSame($postFirst, $taxonomyFirst, 'arrival order leaves no trace in delivery state');
        self::assertStringContainsString('"slug":"converging"', $postFirst);
        self::assertSame([10, 11], $this->storedTermIds(39), 'and the relationship set is the same');
    }

    // =========================================================================
    // Selection correctness
    // =========================================================================

    public function test_an_existing_category_with_no_posts_returns_an_empty_page(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'elsewhere', categoryIds: [11]);

        $page = (new PostQueryProvider($this->db))->list(new ContentFilterSet(categorySlug: 'etf'));

        self::assertSame([], $page->rows);
        self::assertNull($page->nextCursor);
    }

    public function test_a_post_in_category_a_never_appears_under_category_b(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'etf-post', categoryIds: [10]);
        $this->projectPost(40, 'sip-post', categoryIds: [11]);

        self::assertSame(['etf-post'], array_column($this->listByCategory('etf'), 'slug'));
        self::assertSame(['sip-post'], array_column($this->listByCategory('sip'), 'slug'));
    }

    public function test_a_category_filter_never_matches_a_tag_of_the_same_slug(): void
    {
        // content.taxonomies is shared, and WordPress guarantees slug uniqueness only WITHIN a
        // taxonomy — so ?category=news must not pick up the TAG news. Keying links by source term
        // id does not weaken this: term ids are globally unique across taxonomies, and
        // taxonomy_type is still the predicate that decides which term a slug means.
        $this->projectCategory(10, 'news', 'News Category');
        $this->projectCategory(11, 'news', 'News Tag', taxonomyType: 'post_tag');
        $this->projectPost(39, 'only-tagged', categoryIds: [], tagIds: [11]);

        self::assertSame([], $this->listByCategory('news'));

        $tagged = (new PostQueryProvider($this->db))
            ->list(new ContentFilterSet(tagSlug: 'news'))->rows;
        self::assertSame(['only-tagged'], array_column($tagged, 'slug'));
    }

    public function test_a_soft_deleted_category_matches_nothing(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectPost(39, 'orphaned', categoryIds: [10]);
        self::assertCount(1, $this->listByCategory('etf'));

        $this->db->execute(
            'UPDATE content.taxonomies SET deleted_at = now() WHERE source_term_id = $1',
            [10]
        );

        self::assertSame([], $this->listByCategory('etf'), 'a tombstoned category serves no archive');
    }

    public function test_non_public_posts_never_leak_into_the_filtered_listing(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectPost(39, 'published', categoryIds: [10]);
        $this->projectPost(40, 'drafted', categoryIds: [10], status: 'draft');
        $this->projectPost(41, 'privated', categoryIds: [10], status: 'private');
        $this->projectPost(42, 'removed', categoryIds: [10]);
        $this->db->execute(
            'UPDATE content.posts SET deleted_at = now() WHERE source_post_id = $1',
            [42]
        );

        self::assertSame(['published'], array_column($this->listByCategory('etf'), 'slug'));
    }

    // =========================================================================
    // Membership converges through the normal pipeline
    // =========================================================================

    public function test_removing_a_category_assignment_removes_the_post_from_the_archive(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'moving', categoryIds: [10, 11]);
        self::assertCount(1, $this->listByCategory('etf'));

        // The editor drops ETF in WordPress; the post re-syncs through the same handler.
        $this->projectPost(39, 'moving', categoryIds: [11], version: 2);

        self::assertSame([], $this->listByCategory('etf'), 'the stale link is gone');
        self::assertCount(1, $this->listByCategory('sip'), 'the surviving link is untouched');
    }

    public function test_adding_a_category_assignment_adds_the_post_to_the_archive(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'growing', categoryIds: [11]);
        self::assertSame([], $this->listByCategory('etf'));

        $this->projectPost(39, 'growing', categoryIds: [10, 11], version: 2);

        self::assertSame(['growing'], array_column($this->listByCategory('etf'), 'slug'));
    }

    public function test_a_re_emission_repairs_links_a_pre_0009_projection_lost(): void
    {
        // The half of the defect that made it PERMANENT rather than merely late. An existing site
        // sits on a post row whose checksum is already correct and whose links are missing; the
        // post's own WordPress state has not changed, so replay and reconciliation both recompute
        // that same checksum. Unless the suppress decision also looks at the link rows, the
        // archive stays empty forever and nothing ever reports a problem.
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectPost(39, 'stranded', categoryIds: [10]);

        $this->db->execute('DELETE FROM content.entity_taxonomies');
        self::assertSame([], $this->listByCategory('etf'), 'precondition: the damaged state');

        // Identical state, identical checksum, identical version — a plain re-emission.
        $this->projectPost(39, 'stranded', categoryIds: [10]);

        self::assertSame(['stranded'], array_column($this->listByCategory('etf'), 'slug'));
    }

    public function test_a_same_count_wrong_set_relationship_state_is_repaired(): void
    {
        // THE CASE CARDINALITY EQUALITY CANNOT SEE. The stored relationship count is right and the
        // stored SET is wrong — canonical {10, 11}, persisted {10, 12} — with the post's checksum
        // unchanged. A count-only witness suppresses this forever and the post stays filed under
        // the wrong category; only exact set comparison catches it (AJ-1).
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectCategory(12, 'swp', 'SWP');
        $this->projectPost(39, 'miskeyed', categoryIds: [10, 11]);

        // Corrupt one link in place: same row count, different set.
        $this->db->execute(
            'UPDATE content.entity_taxonomies SET source_term_id = 12 WHERE source_term_id = 11'
        );
        self::assertSame([10, 12], $this->storedTermIds(39), 'precondition: same count, wrong set');

        // A plain re-emission — identical WordPress state, identical checksum, identical version.
        $this->projectPost(39, 'miskeyed', categoryIds: [10, 11]);

        self::assertSame([10, 11], $this->storedTermIds(39), 'the wrong set was rewritten');
        self::assertSame(['miskeyed'], array_column($this->listByCategory('sip'), 'slug'));
        self::assertSame([], $this->listByCategory('swp'), 'and no longer appears under the wrong one');
    }

    public function test_a_missing_relationship_is_repaired(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'half-linked', categoryIds: [10, 11]);

        $this->db->execute('DELETE FROM content.entity_taxonomies WHERE source_term_id = 11');
        self::assertSame([10], $this->storedTermIds(39));

        $this->projectPost(39, 'half-linked', categoryIds: [10, 11]);

        self::assertSame([10, 11], $this->storedTermIds(39));
    }

    public function test_an_extra_relationship_is_repaired(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'over-linked', categoryIds: [10]);

        $this->db->execute(
            "INSERT INTO content.entity_taxonomies (entity_id, source_term_id)
             SELECT id, 11 FROM content.posts WHERE source_post_id = 39"
        );
        self::assertSame([10, 11], $this->storedTermIds(39));

        $this->projectPost(39, 'over-linked', categoryIds: [10]);

        self::assertSame([10], $this->storedTermIds(39), 'the surplus link is gone');
        self::assertSame([], $this->listByCategory('sip'));
    }

    public function test_an_empty_canonical_set_clears_a_stale_relationship(): void
    {
        // The boundary the string_agg witness has to get right: no link rows at all is a
        // legitimate state, and must compare equal to an empty canonical set rather than read as
        // "unknown" and suppress.
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectPost(39, 'untaxed', categoryIds: []);

        $this->db->execute(
            "INSERT INTO content.entity_taxonomies (entity_id, source_term_id)
             SELECT id, 10 FROM content.posts WHERE source_post_id = 39"
        );
        self::assertSame([10], $this->storedTermIds(39));

        $this->projectPost(39, 'untaxed', categoryIds: []);

        self::assertSame([], $this->storedTermIds(39), 'the stale link is cleared');
        self::assertSame([], $this->listByCategory('etf'));
    }

    public function test_relationship_comparison_ignores_ordering(): void
    {
        // Canonical {11, 10} against persisted {10, 11} is the SAME set. A comparison sensitive to
        // source ordering would rewrite on every single event for no reason.
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');
        $this->projectPost(39, 'ordered', categoryIds: [10, 11]);

        $before = $this->syncedAt(39);
        $this->projectPost(39, 'ordered', categoryIds: [11, 10]);

        self::assertSame($before, $this->syncedAt(39), 'reversed input order is not a difference');
        self::assertSame([10, 11], $this->storedTermIds(39));
    }

    public function test_an_unchanged_post_with_intact_links_is_still_write_suppressed(): void
    {
        // That repair must not cost the write-suppression it sits next to: a genuinely unchanged
        // post still writes nothing (OPEN-11 / DECISION 3).
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectPost(39, 'settled', categoryIds: [10]);

        $before = $this->syncedAt(39);

        $this->projectPost(39, 'settled', categoryIds: [10]);

        self::assertSame($before, $this->syncedAt(39), 'no re-write when row and links both already match');
    }

    // =========================================================================
    // post_count semantics
    // =========================================================================

    public function test_post_count_equals_the_size_of_the_public_filtered_result(): void
    {
        // post_count is WordPress's own wp_term_taxonomy.count, projected verbatim — it counts
        // published posts, which is exactly the population the archive serves. This pins the two
        // together so they cannot silently drift apart again.
        $this->projectCategory(10, 'etf', 'ETF', count: 3);
        $this->projectPost(39, 'a', categoryIds: [10]);
        $this->projectPost(40, 'b', categoryIds: [10]);
        $this->projectPost(41, 'c', categoryIds: [10]);
        $this->projectPost(42, 'unpublished', categoryIds: [10], status: 'draft');

        $postCount = (int) $this->db->query(
            'SELECT post_count FROM content.taxonomies WHERE source_term_id = 10'
        )[0]['post_count'];

        self::assertSame($postCount, count($this->listByCategory('etf', limit: 100)));
    }

    // =========================================================================
    // Cursor pagination survives the filter
    // =========================================================================

    public function test_paging_a_filtered_archive_skips_nothing_and_duplicates_nothing(): void
    {
        $this->projectCategory(10, 'etf', 'ETF');
        $this->projectCategory(11, 'sip', 'SIP');

        // Every third post lands in SIP instead, so a leak across a page boundary is visible.
        // Posts 1-6 share ONE published_at: duplicate primary sort values are exactly where a
        // cursor that seeks on the sort key alone loses or repeats rows.
        $expected = [];
        for ($i = 1; $i <= 12; $i++) {
            $inEtf = $i % 3 !== 0;
            $this->projectPost(
                $i,
                'post-' . $i,
                categoryIds: [$inEtf ? 10 : 11],
                publishedAt: $i <= 6 ? '2024-01-01T00:00:00Z' : '2024-02-01T00:00:00Z',
                spreadPublishedAt: false,
            );
            if ($inEtf) {
                $expected[] = 'post-' . $i;
            }
        }

        $provider = new PostQueryProvider($this->db);
        $seen     = [];
        $cursor   = null;
        $pages    = 0;

        do {
            $page = $provider->list(new ContentFilterSet(categorySlug: 'etf', cursor: $cursor, limit: 3));
            foreach ($page->rows as $row) {
                $seen[] = (string) $row['slug'];
            }
            $cursor = $page->nextCursor;
            $pages++;
            self::assertLessThan(10, $pages, 'pagination must terminate');
        } while ($cursor !== null);

        self::assertGreaterThan(2, $pages, 'the fixture must genuinely span several pages');
        self::assertSame(count($seen), count(array_unique($seen)), 'no post is served twice');

        sort($expected);
        sort($seen);
        self::assertSame($expected, $seen, 'every ETF post, and only ETF posts, across all pages');
    }

    // =========================================================================
    // Performance
    // =========================================================================

    public function test_the_category_filter_is_index_backed_at_a_realistic_size(): void
    {
        $this->seedBulkRelationships();

        $rows = $this->db->query(
            "EXPLAIN SELECT p.id
             FROM content.posts p
             WHERE p.deleted_at IS NULL AND p.status = 'publish'
               AND EXISTS (
                   SELECT 1 FROM content.entity_taxonomies et
                   JOIN content.taxonomies t ON t.source_term_id = et.source_term_id
                   WHERE et.entity_id = p.id AND t.slug = 'etf'
                     AND t.taxonomy_type = 'category' AND t.deleted_at IS NULL
               )
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT 21"
        );
        $plan = implode("\n", array_map(static fn (array $r): string => (string) reset($r), $rows));

        // entity -> terms rides the PK; term -> entities rides
        // idx_content_entity_taxonomies_term_entity; the hop to the term itself rides
        // uq_content_taxonomies_source_term_id. None may degrade to a full scan.
        self::assertStringNotContainsString('Seq Scan on entity_taxonomies', $plan, "Plan:\n{$plan}");
        self::assertStringNotContainsString('Seq Scan on taxonomies', $plan, "Plan:\n{$plan}");
    }

    public function test_the_relationship_witness_read_is_bounded_and_index_backed(): void
    {
        // The suppress predicate reads every persisted term for ONE aggregate on every persist, so
        // if that read ever degrades to a scan of the whole relationship table it becomes a cost
        // paid per event at 100,000-record scale. The PK leads with entity_id precisely so it
        // cannot. Asserted at a size where a sequential scan would otherwise be the cheap plan.
        $this->seedBulkRelationships();

        $entityId = (string) $this->db->query(
            'SELECT id FROM content.posts WHERE source_post_id = 1010'
        )[0]['id'];

        $rows = $this->db->query(
            "EXPLAIN SELECT string_agg(et.source_term_id::text, ',' ORDER BY et.source_term_id)
             FROM content.entity_taxonomies et
             WHERE et.entity_id = '{$entityId}'::uuid"
        );
        $plan = implode("\n", array_map(static fn (array $r): string => (string) reset($r), $rows));

        self::assertStringNotContainsString('Seq Scan on entity_taxonomies', $plan, "Plan:\n{$plan}");
        self::assertMatchesRegularExpression(
            '/Index (Only )?Scan|Bitmap Index Scan/',
            $plan,
            "the witness must ride an index on entity_id. Plan:\n{$plan}"
        );
    }

    // =========================================================================
    // Pipeline drivers — the real handlers, never a hand-seeded link
    // =========================================================================

    /**
     * @param list<int> $categoryIds
     * @param list<int> $tagIds
     */
    private function projectPost(
        int $sourceId,
        string $slug,
        array $categoryIds,
        array $tagIds = [],
        string $status = 'publish',
        string $publishedAt = '2024-01-01T00:00:00Z',
        int $version = 1,
        bool $spreadPublishedAt = true,
    ): void {
        $at = new \DateTimeImmutable($publishedAt);

        if ($spreadPublishedAt) {
            $at = $at->modify("+{$sourceId} minutes");
        }

        $loader = new FakeWpContentLoader();
        $loader->postResult = [
            'ID'                => $sourceId,
            'post_title'        => 'Post ' . $sourceId,
            'post_content'      => '',
            'post_excerpt'      => '',
            'post_name'         => $slug,
            'post_status'       => $status,
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
        ))->handle(new FakePipelineEvent('content.post.updated', 'post', (string) $sourceId, $version));
    }

    private function projectCategory(
        int $termId,
        string $slug,
        string $name,
        string $taxonomyType = 'category',
        int $count = 0,
    ): void {
        $loader = new FakeWpContentLoader();
        $loader->termResult = [
            'term_id'     => $termId,
            'name'        => $name,
            'slug'        => $slug,
            'description' => '',
            'parent'      => 0,
            'count'       => $count,
            'taxonomy'    => $taxonomyType,
        ];

        (new CategoryUpsertHandler(
            $loader,
            new CategoryExtractor(new CategoryValidator()),
            new CategoryTransformer(),
            new CategoryAdapter($this->db),
        ))->handle(new FakePipelineEvent('content.category.updated', 'category', (string) $termId, 1));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return list<array<string,mixed>> */
    private function listByCategory(string $slug, int $limit = 50): array
    {
        return (new PostQueryProvider($this->db))
            ->list(new ContentFilterSet(categorySlug: $slug, limit: $limit))
            ->rows;
    }

    private function linkCount(): int
    {
        return (int) $this->db->query('SELECT COUNT(*) AS c FROM content.entity_taxonomies')[0]['c'];
    }

    /**
     * A realistically-sized corpus for the plan assertions.
     *
     * Sized so a sequential scan genuinely IS the expensive plan — at a hundred link rows the
     * planner is right to scan and an index assertion would prove nothing. Seeded set-based;
     * 10,000 inserts through a PHP loop would dominate the suite runtime for no extra coverage.
     */
    private function seedBulkRelationships(): void
    {
        $this->db->execute(
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                 checksum, created_at, updated_at, synced_at)
             SELECT gen_random_uuid(), 1000 + g, 'category', 'filler-' || g, 'Filler', '', 0, 0,
                    repeat('c', 64), now(), now(), now()
             FROM generate_series(1, 1000) g"
        );
        $this->db->execute(
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                 checksum, created_at, updated_at, synced_at)
             VALUES (gen_random_uuid(), 10, 'category', 'etf', 'ETF', '', 0, 0,
                     repeat('c', 64), now(), now(), now())"
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
             JOIN content.taxonomies t ON t.slug LIKE 'filler-%'
             WHERE (p.source_post_id + t.source_term_id) % 100 = 0
             ON CONFLICT DO NOTHING"
        );
        $this->db->execute(
            "INSERT INTO content.entity_taxonomies (entity_id, source_term_id)
             SELECT p.id, 10 FROM content.posts p WHERE p.source_post_id % 10 = 0
             ON CONFLICT DO NOTHING"
        );

        $this->db->execute('ANALYZE content.posts');
        $this->db->execute('ANALYZE content.taxonomies');
        $this->db->execute('ANALYZE content.entity_taxonomies');

        $links = (int) $this->db->query('SELECT COUNT(*) AS c FROM content.entity_taxonomies')[0]['c'];
        self::assertGreaterThan(5000, $links, 'the join table must be big enough for a scan to hurt');
    }

    /** @return list<int> the term ids linked to one post, ascending */
    private function storedTermIds(int $sourcePostId): array
    {
        $rows = $this->db->query(
            'SELECT et.source_term_id
             FROM content.entity_taxonomies et
             JOIN content.posts p ON p.id = et.entity_id
             WHERE p.source_post_id = $1
             ORDER BY et.source_term_id',
            [$sourcePostId]
        );

        return array_map(static fn (array $r): int => (int) $r['source_term_id'], $rows);
    }

    private function syncedAt(int $sourcePostId): string
    {
        return (string) $this->db->query(
            'SELECT synced_at FROM content.posts WHERE source_post_id = $1',
            [$sourcePostId]
        )[0]['synced_at'];
    }

    /**
     * The served archive as a consumer actually receives it: the Resource output, serialized.
     *
     * Compared as JSON rather than as PHP values, deliberately — the payload is the contract, and
     * two structurally identical responses must compare equal even though each run builds its own
     * `meta` object (an empty stdClass is never identical to another empty stdClass).
     */
    private function deliveryState(string $categorySlug): string
    {
        $bodies = array_map(
            static fn (array $row): array => (new \HSP\Modules\Content\Resources\PostResource())->toArray($row),
            $this->listByCategory($categorySlug, limit: 100)
        );

        return (string) json_encode($bodies, JSON_UNESCAPED_UNICODE);
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

        $dsn = sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            $host,
            getenv('HSP_TEST_PGSQL_PORT') ?: '5432',
            $db,
            getenv('HSP_TEST_PGSQL_USER') ?: 'postgres',
            getenv('HSP_TEST_PGSQL_PASSWORD') ?: ''
        );

        $conn = @pg_connect($dsn);

        if ($conn === false) {
            self::markTestSkipped('PostgreSQL not reachable');
        }

        return $conn;
    }
}

/** Minimal event envelope — the handlers reload WordPress state, so the payload is empty (ADR-044). */
final class FakePipelineEvent implements EventInterface
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
