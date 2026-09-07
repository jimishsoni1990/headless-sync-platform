<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
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
    // The deprecated v1 compatibility arm still behaves (ruling 2)
    // -------------------------------------------------------------------------

    /**
     * findBySlug() remains the deterministic leaf lookup the REST boundary falls back to for a
     * one-segment miss. It must stay stable while it exists — an unstable fallback would be worse
     * than none.
     */
    public function test_the_deprecated_leaf_lookup_is_still_deterministic(): void
    {
        $this->seedPage(10, 'team', parentId: 3);
        $this->seedPage(11, 'team', parentId: 4);

        $first = $this->provider->findBySlug('team');
        self::assertNotNull($first);

        for ($i = 0; $i < 5; $i++) {
            self::assertSame($first['id'], $this->provider->findBySlug('team')['id']);
        }
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

        foreach (['path', 'uri', 'permalink', 'full_path', 'ancestor_path'] as $forbidden) {
            self::assertNotContains(
                $forbidden,
                $columns,
                "content.pages must not carry a derived '{$forbidden}' column (DECISION AD ruling 3)",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedPage(
        int $sourceId,
        string $slug,
        int $parentId,
        string $status = 'publish',
        bool $deleted = false,
    ): string {
        $rows = $this->db->query(
            "INSERT INTO content.pages
                (id, source_post_id, source_entity_type, slug, title, content, status, parent_id,
                 menu_order, featured_media_id, published_at, updated_at, deleted_at, checksum,
                 meta_jsonb, created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, 'page', \$2, 'Page', '', \$3, \$4, 0, 0,
                     now(), now(), \$5::timestamptz, repeat('a', 64), '{}'::jsonb, now(), now())
             RETURNING id",
            [$sourceId, $slug, $status, $parentId, $deleted ? '2024-01-01 00:00:00+00' : null]
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
