<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Tests\Support\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * Hierarchical page addressing against live PostgreSQL (DECISION AD, resolving FLAG-PAGESLUG-1).
 *
 * REPLACES PageSlugAmbiguityIntegrationTest, which pinned the pre-ruling mitigation (a
 * deterministic but arbitrary leaf lookup). The ruling makes the FULL ANCESTOR PATH the canonical
 * page identity, so `/about/team` and `/services/team` — both legal in WordPress, both projected
 * with slug='team' — are independently addressable.
 *
 * These tests drive the real recursive-CTE resolver against the real migration schema; the SQL is
 * the whole substance of the change, so a fake connection would prove nothing.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class PagePathAddressingIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private PageQueryProvider $provider;

    protected function setUp(): void
    {
        $this->pgConn   = $this->connectPgsql();
        $this->db       = new PostgresDatabaseConnection($this->pgConn);
        $this->createSchema();
        $this->provider = new PageQueryProvider($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // -------------------------------------------------------------------------
    // The defect this ruling exists for
    // -------------------------------------------------------------------------

    /**
     * The headline: two pages sharing the leaf slug `team` are each addressable by their own
     * hierarchy. Before DECISION AD one of them was unreachable through the API at all.
     */
    public function test_duplicate_leaf_slugs_are_independently_addressable_by_path(): void
    {
        $this->seedPage(3, 'about',    parentId: 0);
        $this->seedPage(4, 'services', parentId: 0);
        $aboutTeam    = $this->seedPage(10, 'team', parentId: 3);
        $servicesTeam = $this->seedPage(11, 'team', parentId: 4);

        self::assertSame($aboutTeam, $this->provider->findByPath('about/team')['id']);
        self::assertSame($servicesTeam, $this->provider->findByPath('services/team')['id']);
        self::assertNotSame($aboutTeam, $servicesTeam);
    }

    /**
     * A wrong parent is a miss, not a near-miss. Falling back to a namesake here is exactly the
     * behaviour the ruling prohibits — it would make `/pages/wrong-parent/team` serve
     * `/about/team`.
     */
    public function test_a_wrong_parent_path_resolves_to_nothing(): void
    {
        $this->seedPage(3, 'about', parentId: 0);
        $this->seedPage(10, 'team', parentId: 3);

        self::assertNull($this->provider->findByPath('wrong-parent/team'));
        self::assertNull($this->provider->findByPath('team'), 'a nested page is not top-level');
    }

    /** Three levels resolve exactly, so the walk is genuinely recursive and not a single hop. */
    public function test_a_deep_path_resolves_exactly(): void
    {
        $this->seedPage(1, 'company', parentId: 0);
        $this->seedPage(2, 'about',   parentId: 1);
        $deep = $this->seedPage(3, 'team', parentId: 2);

        self::assertSame($deep, $this->provider->findByPath('company/about/team')['id']);
        self::assertNull($this->provider->findByPath('about/team'), 'a partial suffix is not the path');
    }

    public function test_a_one_segment_path_resolves_the_top_level_page_only(): void
    {
        $nested   = $this->seedPage(10, 'team', parentId: 4);
        $topLevel = $this->seedPage(12, 'team', parentId: 0);

        $found = $this->provider->findByPath('team');

        self::assertNotNull($found);
        self::assertSame($topLevel, $found['id']);
        self::assertNotSame($nested, $found['id']);
    }

    // -------------------------------------------------------------------------
    // Public-set predicate: the requested page only (ruling 5)
    // -------------------------------------------------------------------------

    /**
     * Ancestors are STRUCTURAL, not content being served: a published child under a soft-deleted
     * (unpublished) parent keeps its address. Applying the public predicate up the chain would
     * make a child disappear because of its parent's visibility, which is not how WordPress
     * hierarchy works.
     */
    public function test_a_published_page_under_a_non_public_ancestor_stays_addressable(): void
    {
        $this->seedPage(3, 'about', parentId: 0, deleted: true);
        $team = $this->seedPage(10, 'team', parentId: 3);

        self::assertSame($team, $this->provider->findByPath('about/team')['id']);
    }

    /** …and that ancestor is still NOT retrievable through its own address. */
    public function test_a_non_public_ancestor_is_not_itself_retrievable(): void
    {
        $this->seedPage(3, 'about', parentId: 0, deleted: true);
        $this->seedPage(10, 'team', parentId: 3);

        self::assertNull($this->provider->findByPath('about'));
    }

    /**
     * A page whose ancestor has NO projection row at all — the parent was never published, so
     * HookWiring never emitted for it (OPEN-10) — is not addressable by path (DECISION AE).
     *
     * This is WordPress parity, not a shortfall, and the reason is in WP core: `wp_insert_post()`
     * skips slug generation for draft/pending/auto-draft, so a never-published page normally has an
     * EMPTY `post_name`. Verified against live WordPress: with an empty-slug draft parent,
     * `get_page_uri()` on the published child returns the LEAF ALONE (`team`, not `about/team`) and
     * `get_page_by_path()` finds the child under neither address — WordPress advertises a permalink
     * it then cannot route. There is no address for HSP to miss.
     *
     * The one case where WordPress CAN route such a child is a never-published parent carrying an
     * explicitly-set slug, which stays a documented divergence pending an OPEN-10 ruling — see
     * FLAG-PAGEPATH-ANCESTOR-1. This test pins today's behaviour so that ruling cannot land
     * silently.
     */
    public function test_a_page_under_an_unprojected_ancestor_is_not_addressable_by_path(): void
    {
        // No row for source_post_id 3 — the parent was never published, so it was never captured.
        $this->seedPage(10, 'team', parentId: 3);

        self::assertNull($this->provider->findByPath('about/team'), 'the ancestor slug is unknown');
        self::assertNull($this->provider->findByPath('team'), 'the child is not top-level');

        // PARITY REACHED (DECISION AF): while the deprecated leaf fallback existed it DID return
        // this child — a page WordPress itself 404s, making HSP briefly more permissive than
        // WordPress. DECISION AE predicted that retiring the fallback would close exactly this
        // gap; it did.
        self::assertNull($this->provider->findBySlug('team'), 'no bare-slug back door remains');
    }

    public function test_the_requested_page_still_requires_publish_and_not_deleted(): void
    {
        $this->seedPage(3, 'about', parentId: 0);
        $this->seedPage(10, 'draft-child', parentId: 3, status: 'draft');
        $this->seedPage(11, 'dead-child',  parentId: 3, deleted: true);

        self::assertNull($this->provider->findByPath('about/draft-child'));
        self::assertNull($this->provider->findByPath('about/dead-child'));
    }

    // -------------------------------------------------------------------------
    // Read-time resolution (ruling 3) and traversal safety (ruling 4)
    // -------------------------------------------------------------------------

    /**
     * The reason no stored path column exists: renaming a parent changes every descendant's
     * address, and WordPress emits no event for the descendants. With read-time resolution the
     * new path is correct the moment the PARENT's own projection row is updated — there is no
     * derived descendant value that could be stale.
     */
    public function test_renaming_a_parent_moves_every_descendant_immediately(): void
    {
        $this->seedPage(3, 'about', parentId: 0);
        $team = $this->seedPage(10, 'team', parentId: 3);

        self::assertSame($team, $this->provider->findByPath('about/team')['id']);

        // Only the PARENT row changes — exactly what the pipeline projects for a parent edit.
        $this->db->execute("UPDATE content.pages SET slug = 'company' WHERE source_post_id = 3");

        self::assertSame($team, $this->provider->findByPath('company/team')['id']);
        self::assertNull($this->provider->findByPath('about/team'), 'the old path stops resolving');
    }

    /**
     * A corrupt hierarchy must not hang the delivery API. A parent cycle can never reach the root,
     * so the depth bound is what terminates the recursion.
     */
    public function test_a_parent_cycle_terminates_and_resolves_to_nothing(): void
    {
        $this->seedPage(1, 'alpha', parentId: 2);
        $this->seedPage(2, 'beta',  parentId: 1);

        self::assertNull($this->provider->findByPath('alpha'));
        self::assertNull($this->provider->findByPath('beta/alpha'));
        self::assertNull($this->provider->findByPath('alpha/beta/alpha'));
    }

    /** One query per lookup at any depth — the walk must not become N+1 round-trips. */
    public function test_path_resolution_costs_exactly_one_query_at_any_depth(): void
    {
        $this->seedPage(1, 'company', parentId: 0);
        $this->seedPage(2, 'about',   parentId: 1);
        $this->seedPage(3, 'team',    parentId: 2);

        $counting = new CountingPgConnection($this->pgConn);
        $provider = new PageQueryProvider($counting);

        $provider->findByPath('company/about/team');
        $deep = $counting->queries;

        $counting->queries = 0;
        $provider->findByPath('company');

        self::assertSame(1, $deep, 'a three-level path is one query');
        self::assertSame(1, $counting->queries, 'a one-level path is the same one query');
    }

    // -------------------------------------------------------------------------
    // The v1 compatibility arm is RETIRED (DECISION AF completes AD ruling 2)
    // -------------------------------------------------------------------------

    /**
     * findBySlug() now means "the top-level page of that name" — it delegates to findByPath() on a
     * one-segment path. The old bare-slug lookup, which returned an arbitrary-but-deterministic
     * nested namesake, is gone: two nested pages sharing a leaf slug resolve to NOTHING by bare
     * slug, because neither is top-level. Each is still addressable by its own path.
     */
    public function test_a_bare_slug_no_longer_reaches_a_nested_page(): void
    {
        $this->seedPage(3, 'about',    parentId: 0);
        $this->seedPage(4, 'services', parentId: 0);
        $aboutTeam = $this->seedPage(10, 'team', parentId: 3);
        $this->seedPage(11, 'team', parentId: 4);

        self::assertNull($this->provider->findBySlug('team'), 'neither namesake is top-level');
        self::assertSame($aboutTeam, $this->provider->findByPath('about/team')['id']);
    }

    /** A top-level page is still reachable by its bare slug — that IS its one-segment path. */
    public function test_a_bare_slug_still_reaches_a_top_level_page(): void
    {
        $contact = $this->seedPage(20, 'contact', parentId: 0);
        $this->seedPage(21, 'contact', parentId: 20);

        self::assertSame($contact, $this->provider->findBySlug('contact')['id']);
    }

    // -------------------------------------------------------------------------
    // No schema change (ruling 3)
    // -------------------------------------------------------------------------

    /** No derived path/uri/permalink column may appear on content.pages. */
    public function test_content_pages_has_no_derived_path_column(): void
    {
        $columns = array_column(
            $this->db->query(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'content' AND table_name = 'pages'"
            ),
            'column_name'
        );

        foreach (['path', 'uri', 'url', 'permalink', 'full_path', 'ancestor_path'] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $columns,
                "content.pages must not carry a derived '{$forbidden}' column (DECISION AD ruling 3)",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Finding 005 — the canonical path is PUBLISHED, not merely resolvable
    //
    // Addressing worked; the listing just never said what a page's address was. A consumer that
    // received `{slug: team, parent_id: 3}` had no legitimate way to reach `/pages/about/team`.
    // -------------------------------------------------------------------------

    public function test_the_listing_publishes_the_full_ancestor_path_of_every_page(): void
    {
        $this->seedPage(1, 'about',      parentId: 0);
        $this->seedPage(2, 'team',       parentId: 1);
        $this->seedPage(3, 'leadership', parentId: 2);

        self::assertEquals(
            ['about' => 'about', 'team' => 'about/team', 'leadership' => 'about/team/leadership'],
            $this->listedPaths(),
        );
    }

    /**
     * The reason DECISION AD exists, now visible in the listing: two pages whose leaf slug is
     * `team` are published with DIFFERENT canonical identities. Neither is de-duplicated away and
     * neither is arbitrarily preferred.
     */
    public function test_duplicate_leaf_slugs_are_listed_with_distinct_paths(): void
    {
        $this->seedPage(1, 'about',    parentId: 0);
        $this->seedPage(2, 'services', parentId: 0);
        $aboutTeam    = $this->seedPage(3, 'team', parentId: 1);
        $servicesTeam = $this->seedPage(4, 'team', parentId: 2);

        $byId = $this->listedRowsById();

        self::assertSame('about/team',    $byId[$aboutTeam]['path']);
        self::assertSame('services/team', $byId[$servicesTeam]['path']);
        self::assertCount(4, $byId, 'four pages listed, none collapsed by leaf slug');
    }

    /**
     * The contract that makes the field worth publishing: every path the listing hands out can be
     * passed straight back to the lookup and returns THAT page. Row identity is compared through
     * the projection id — internal, used here only as proof, never published.
     */
    public function test_every_listed_path_round_trips_through_the_detail_lookup(): void
    {
        $this->seedPage(1, 'about',      parentId: 0);
        $this->seedPage(2, 'team',       parentId: 1);
        $this->seedPage(3, 'leadership', parentId: 2);
        $this->seedPage(4, 'services',   parentId: 0);
        $this->seedPage(5, 'team',       parentId: 4);

        $listed = $this->listedRowsById();
        self::assertCount(5, $listed);

        foreach ($listed as $id => $row) {
            $fetched = $this->provider->findByPath((string) $row['path']);

            self::assertNotNull($fetched, "listed path {$row['path']} must resolve");
            self::assertSame($id, $fetched['id'], "listed path {$row['path']} must resolve the SAME row");
            self::assertSame($row['path'], $fetched['path'], 'list and detail publish one identity');
        }
    }

    /** A path is an exact hierarchy, in the listing exactly as in the lookup. */
    public function test_a_listed_path_is_never_addressable_under_a_wrong_parent(): void
    {
        $this->seedPage(1, 'about', parentId: 0);
        $this->seedPage(2, 'team',  parentId: 1);

        self::assertSame('about/team', $this->listedPaths()['team']);
        self::assertNull($this->provider->findByPath('wrong-parent/team'));
        self::assertNull($this->provider->findByPath('team'), 'the leaf alone is not an address');
    }

    /**
     * The whole reason the path is derived rather than stored: renaming a parent must move every
     * descendant immediately, although WordPress emits no event for the descendants and nothing
     * rewrites their rows.
     *
     * The child's own projection is proven untouched — same checksum, same synced_at — so the new
     * path cannot be coming from a descendant rewrite, a fan-out or an invalidation sweep.
     */
    public function test_a_parent_rename_moves_the_descendant_listing_path_without_rewriting_it(): void
    {
        $this->seedPage(1, 'about', parentId: 0);
        $child = $this->seedPage(2, 'team', parentId: 1);

        self::assertSame('about/team', $this->listedPaths()['team']);
        $before = $this->childWriteState($child);

        // Exactly what the pipeline projects for a parent edit: the PARENT row, nothing else.
        $this->db->execute("UPDATE content.pages SET slug = 'company' WHERE source_post_id = 1");

        self::assertSame('company/team', $this->listedPaths()['team']);
        self::assertSame($before, $this->childWriteState($child), 'the child projection was not rewritten');

        self::assertNotNull($this->provider->findByPath('company/team'));
        self::assertNull($this->provider->findByPath('about/team'), 'the old path stops resolving');
    }

    /**
     * Re-parenting through the normal projected relationship — the same read-time derivation, no
     * special descendant repair path.
     */
    public function test_moving_a_page_to_a_new_parent_moves_its_published_path(): void
    {
        $this->seedPage(1, 'about',   parentId: 0);
        $this->seedPage(2, 'company', parentId: 0);
        $child = $this->seedPage(3, 'team', parentId: 1);

        self::assertSame('about/team', $this->listedPaths()['team']);

        // The ordinary projected relationship change — no descendant repair path, no fan-out.
        $this->db->execute('UPDATE content.pages SET parent_id = 2 WHERE source_post_id = 3');

        self::assertSame('company/team', $this->listedPaths()['team']);
        self::assertSame($child, $this->provider->findByPath('company/team')['id']);
        self::assertNull($this->provider->findByPath('about/team'), 'the old address is gone');
    }

    /**
     * DECISION AE, in the listing. A page whose ancestor was never published has no reconstructable
     * path — and the frozen decisions leave exactly one representation open.
     *
     * Omitting the page would change listing ELIGIBILITY: the row is public by OPEN-10's predicate
     * and was listed before this field existed. Publishing the leaf slug would advertise an address
     * that 404s — the one-segment fallback DECISION AF removed, re-entering through the listing and
     * making HSP more permissive than WordPress again (DECISION AE, evidence table row 1).
     *
     * So: the page is still listed, with every other field intact, and `path` is null — "no
     * canonical address", which is precisely what the lookup says about it too.
     */
    public function test_a_page_under_an_unprojected_ancestor_is_listed_with_a_null_path(): void
    {
        // No row for source_post_id 1 — that parent was never published, so it was never captured.
        $this->seedPage(2, 'team', parentId: 1);

        $rows = $this->provider->list(new ContentFilterSet())->rows;

        self::assertCount(1, $rows, 'the page is still listed — eligibility is unchanged');
        self::assertSame('team', $rows[0]['slug']);
        self::assertNull($rows[0]['path'], 'no invented address');
        self::assertNull($this->provider->findByPath('team'), 'and the lookup agrees it has none');
    }

    /** A corrupt hierarchy terminates in the listing exactly as it does in the lookup. */
    public function test_a_parent_cycle_is_listed_with_a_null_path_and_terminates(): void
    {
        $this->seedPage(1, 'alpha', parentId: 2);
        $this->seedPage(2, 'beta',  parentId: 1);
        $this->seedPage(3, 'sane',  parentId: 0);

        $paths = $this->listedPaths();

        self::assertNull($paths['alpha'], 'a cycle never reaches the root');
        self::assertNull($paths['beta']);
        self::assertSame('sane', $paths['sane'], 'and a healthy row beside it is unaffected');
    }

    /** A legitimately deep tree resolves: MAX_ANCESTOR_DEPTH guards corruption, not editors. */
    public function test_a_deep_hierarchy_publishes_its_whole_path(): void
    {
        $expected = [];
        for ($level = 1; $level <= 12; $level++) {
            $this->seedPage($level, 'level-' . $level, parentId: $level - 1);
            $expected[] = 'level-' . $level;
        }

        $deepest = implode('/', $expected);

        self::assertSame($deepest, $this->listedPaths()['level-12']);
        self::assertNotNull($this->provider->findByPath($deepest));
    }

    // -------------------------------------------------------------------------
    // Finding 005 — cost and pagination
    // -------------------------------------------------------------------------

    /**
     * The prohibited implementation is one findByPath() per listed row. This is the assertion that
     * makes it impossible: the query count is the same for a one-row page and a full one.
     */
    public function test_publishing_paths_costs_one_query_whatever_the_page_size(): void
    {
        $this->seedPage(1, 'about', parentId: 0);
        for ($i = 2; $i <= 25; $i++) {
            $this->seedPage($i, 'child-' . $i, parentId: 1);
        }

        $counting = new CountingPgConnection($this->pgConn);
        $provider = new PageQueryProvider($counting);

        $one = $provider->list(new ContentFilterSet(limit: 1));
        self::assertCount(1, $one->rows);
        self::assertSame(1, $counting->queries, 'a one-row page is one query');

        $counting->queries = 0;
        $full = $provider->list(new ContentFilterSet(limit: 20));

        self::assertCount(20, $full->rows);
        self::assertSame(1, $counting->queries, '20 rows is the SAME one query — never 1 + N');
        foreach ($full->rows as $row) {
            self::assertNotNull($row['path']);
        }
    }

    /**
     * Path resolution must not touch the cursor contract. Seeded with a SHARED published_at so the
     * id tiebreaker is the thing under test, and with a hierarchy deep enough that a join-shaped
     * implementation would multiply rows.
     */
    public function test_cursor_pagination_is_unchanged_by_path_resolution(): void
    {
        $this->seedPage(1, 'root', parentId: 0, publishedAt: '2026-01-01 00:00:00+00');
        $this->seedPage(2, 'mid',  parentId: 1, publishedAt: '2026-01-01 00:00:00+00');
        for ($i = 3; $i <= 14; $i++) {
            $this->seedPage($i, 'leaf-' . $i, parentId: 2, publishedAt: '2026-01-01 00:00:00+00');
        }

        $seen   = [];
        $cursor = null;
        $pages  = 0;

        do {
            $page = $this->provider->list(new ContentFilterSet(cursor: $cursor, limit: 5));
            self::assertLessThanOrEqual(5, count($page->rows), 'a deep page still counts as one row');
            foreach ($page->rows as $row) {
                $seen[$row['id']] = $row['path'];
            }
            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        self::assertCount(14, $seen, 'every page returned exactly once — none skipped, none doubled');
        self::assertSame(3, $pages, '14 rows at 5 per page — cardinality unchanged by the hierarchy');
        self::assertNull($cursor, 'the terminal page still ends the walk');
        self::assertContains('root/mid/leaf-7', $seen, 'deep rows carry their full path');
    }

    /**
     * The ancestor hop is the half that scales with SITE SIZE, so it must ride an index: the walk
     * runs once per listed row, and a sequential scan of content.pages per hop would cost page size
     * × depth × every page on the site.
     *
     * Asserted on a corpus large enough for the choice to be real. Measured here: at 1,000 pages
     * PostgreSQL hashes the whole table for the recursive hop and that is the CHEAPER plan — an
     * assertion at that size would have been demanding the planner make the worse decision. At
     * 50,000 pages it switches to the unique index on its own. What must be true is that the
     * access path EXISTS and is taken once it earns its keep, which needs no new index and no
     * migration.
     */
    public function test_the_ancestor_hop_rides_the_source_post_id_index(): void
    {
        $this->seedRealisticTree(roots: 10_000, depth: 5);

        $plan = $this->explainListing();

        self::assertStringContainsString('uq_content_pages_source_post_id', $plan, "Plan:\n{$plan}");
        self::assertStringNotContainsString('Seq Scan on pages anc', $plan, "Plan:\n{$plan}");
    }

    /** The recursion is anchored on the cursor window, so the plan walks `paged`, not the table. */
    public function test_only_the_requested_page_is_walked(): void
    {
        $this->seedRealisticTree(roots: 200, depth: 5);

        $plan = $this->explainListing();

        self::assertMatchesRegularExpression('/CTE paged/', $plan, "Plan:\n{$plan}");
        self::assertStringContainsString('CTE Scan on paged', $plan, "Plan:\n{$plan}");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Every listed page's published path, keyed by slug. Reads through the REAL listing query, so
     * what is asserted is what a consumer receives.
     *
     * @return array<string,string|null>
     */
    private function listedPaths(): array
    {
        $paths = [];
        foreach ($this->provider->list(new ContentFilterSet(limit: 100))->rows as $row) {
            $paths[$row['slug']] = $row['path'];
        }

        return $paths;
    }

    /** @return array<string,array<string,mixed>> listed rows keyed by projection id */
    private function listedRowsById(): array
    {
        $rows = [];
        foreach ($this->provider->list(new ContentFilterSet(limit: 100))->rows as $row) {
            $rows[$row['id']] = $row;
        }

        return $rows;
    }

    /**
     * The child row's own write state. If a descendant path changed because something REWROTE the
     * descendant, this pair moves; read-time derivation leaves it identical.
     *
     * @return array<string,mixed>
     */
    private function childWriteState(string $id): array
    {
        return $this->db->query(
            'SELECT checksum, synced_at, updated_at FROM content.pages WHERE id = $1::uuid',
            [$id]
        )[0];
    }

    /** `$roots` chains of `$depth` pages — enough rows that an unindexed ancestor hop is costly. */
    private function seedRealisticTree(int $roots, int $depth): void
    {
        $this->db->execute(
            "INSERT INTO content.pages
                (id, source_post_id, source_entity_type, slug, title, content, status, parent_id,
                 menu_order, featured_media_id, published_at, updated_at, checksum, meta_jsonb,
                 created_at, synced_at)
             SELECT gen_random_uuid(),
                    (level - 1) * \$1 + root,
                    'page',
                    'p-' || level || '-' || root,
                    'Page', '', 'publish',
                    CASE WHEN level = 1 THEN 0 ELSE (level - 2) * \$1 + root END,
                    0, 0, now() - (root || ' minutes')::interval, now(), repeat('a', 64),
                    '{}'::jsonb, now(), now()
             FROM generate_series(1, \$2) level, generate_series(1, \$1) root",
            [$roots, $depth]
        );

        $this->db->execute('ANALYZE content.pages');
    }

    /** EXPLAIN of the ACTUAL listing SQL, captured from the provider rather than hand-copied. */
    private function explainListing(): string
    {
        $recording = new CountingPgConnection($this->pgConn);
        (new PageQueryProvider($recording))->list(new ContentFilterSet(limit: 20));

        return implode("\n", array_map(
            static fn (array $row): string => (string) reset($row),
            $this->db->query('EXPLAIN ' . $recording->lastSql, $recording->lastParams)
        ));
    }

    private function seedPage(
        int $sourceId,
        string $slug,
        int $parentId,
        string $status = 'publish',
        bool $deleted = false,
        ?string $publishedAt = null,
    ): string {
        $rows = $this->db->query(
            "INSERT INTO content.pages
                (id, source_post_id, source_entity_type, slug, title, content, status, parent_id,
                 menu_order, featured_media_id, published_at, updated_at, deleted_at, checksum,
                 meta_jsonb, created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, 'page', \$2, 'Page', '', \$3, \$4, 0, 0,
                     COALESCE(\$6::timestamptz, now()), now(), \$5::timestamptz, repeat('a', 64),
                     '{}'::jsonb, now(), now())
             RETURNING id",
            [$sourceId, $slug, $status, $parentId, $deleted ? '2024-01-01 00:00:00+00' : null,
             $publishedAt]
        );

        return (string) $rows[0]['id'];
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');

        $sql = file_get_contents(__DIR__ . '/../../../modules/Content/Migrations/0002_create_content_pages.sql');
        self::assertIsString($sql);
        self::assertNotFalse(pg_query($this->pgConn, $sql), pg_last_error($this->pgConn));

        // The page query provider selects featured_media_id and joins content.media.
        ContentSchema::ensureFeaturedMediaSupport($this->pgConn);
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
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping page path tests.");
        }

        return $conn;
    }
}
