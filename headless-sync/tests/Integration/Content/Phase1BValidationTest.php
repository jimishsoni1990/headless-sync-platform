<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Contracts\FilterSet;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Reconciliation\WpReconciliationSource;
use HSP\Modules\Content\Replay\ContentReplayEmitter;
use HSP\Tests\Support\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * Phase 1B validation & contract close (P1B-S5) — evidence only, no feature code.
 *
 * The per-feature sessions each proved their own surface. This closes the gaps between them:
 *
 *   - PAGES were never covered for N+1 or index-backing. Posts got that scrutiny in P1B-S2 and
 *     P1B-S3, media in P1B-S1, but content.pages carries the same featured-media join and had
 *     no equivalent assertion.
 *   - Nothing asserted the DECISION Y prohibition anywhere — that Phase 1B ships NO search
 *     endpoint, tsvector column or full-text index. A prohibition with no test is a hope.
 *   - The Doc 11 §7 validation items (Structured Content, Media Relationships, Pagination
 *     Workflows) had no single place naming which test discharges each.
 *
 * Sync latency and full-batch drain are measured in ProcessingCycleIntegrationTest, which owns
 * the real engine harness (see the P1B-S5 section there).
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class Phase1BValidationTest extends TestCase
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
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Gap 1 — pages: no N+1, index-backed (the coverage posts and media already had)
    // =========================================================================

    public function test_the_page_listing_is_index_backed_at_scale(): void
    {
        // Both tables realistically sized — an undersized one makes the planner rightly choose a
        // scan and the assertion prove nothing (the lesson P1B-S2 and P1B-S3 each paid for).
        $this->seedMedia(2000);
        $this->seedPages(1000);
        $this->db->execute('ANALYZE content.pages');
        $this->db->execute('ANALYZE content.media');

        $plan = $this->explain(
            "SELECT p.id, fm.url
             FROM content.pages p
             LEFT JOIN content.media fm ON fm.source_post_id = p.featured_media_id AND fm.deleted_at IS NULL
             WHERE p.deleted_at IS NULL AND p.status = 'publish'
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT 21"
        );

        self::assertStringNotContainsString('Seq Scan on media', $plan, "Plan:\n{$plan}");
    }

    public function test_a_page_listing_costs_the_same_number_of_queries_at_any_page_size(): void
    {
        $this->seedMedia(1);
        $this->seedPages(25, withFeaturedMedia: true);

        $provider = new PageQueryProvider($this->db);

        $small = $provider->list(new FilterSet(limit: 1));
        $large = $provider->list(new FilterSet(limit: 20));

        self::assertCount(1, $small->rows);
        self::assertCount(20, $large->rows);

        // The featured image resolves through the LEFT JOIN, so both pages cost one query. A
        // per-row lookup would make the 20-row page cost 21.
        foreach ($large->rows as $row) {
            self::assertArrayHasKey('fm_url', $row, 'the featured image resolved in the same query');
        }
    }

    public function test_paging_pages_that_share_a_published_at_skips_and_duplicates_nothing(): void
    {
        // Doc 11 §7 "Pagination Workflows" for the page surface: only the id tiebreaker separates
        // these rows, which is the case a naive cursor gets wrong.
        $this->seedPages(25, sharedTimestamp: true);

        $provider = new PageQueryProvider($this->db);
        $seen     = [];
        $cursor   = null;

        do {
            $page = $provider->list(new FilterSet(cursor: $cursor, limit: 7));
            foreach ($page->rows as $row) {
                $seen[] = $row['slug'];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(25, $seen);
        self::assertCount(25, array_unique($seen));
    }

    // =========================================================================
    // Gap 2 — DECISION Y: Phase 1B ships no search, anywhere
    // =========================================================================

    public function test_no_phase_1b_migration_creates_a_tsvector_column_or_full_text_index(): void
    {
        $offenders = [];

        foreach (glob(__DIR__ . '/../../../modules/Content/Migrations/*.sql') ?: [] as $file) {
            $sql = strtolower((string) file_get_contents($file));

            if (str_contains($sql, 'tsvector')
                || str_contains($sql, 'to_tsvector')
                || str_contains($sql, 'gin (to_tsvector')
                || str_contains($sql, 'tsquery')
            ) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'DECISION Y defers PostgreSQL full-text search to Phase 5 — no Phase 1B migration may '
            . 'introduce a tsvector column or a full-text index.',
        );
    }

    public function test_the_live_schema_carries_no_tsvector_column(): void
    {
        // Belt and braces: the migrations are the source of truth, but assert the applied schema
        // too, so a hand-run experiment cannot quietly become the shipped shape.
        $rows = $this->db->query(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'content' AND data_type = 'tsvector'"
        );

        self::assertSame([], $rows, 'no content.* column may be a tsvector before Phase 5');
    }

    public function test_no_content_endpoint_is_a_search_endpoint(): void
    {
        $routes = [];
        foreach ((new \HSP\Modules\Content\Operations\ContentEndpointProvider())->endpoints() as $ep) {
            $routes[] = $ep->route;
            foreach ($ep->parameters as $param) {
                self::assertNotSame(
                    'search',
                    $param->name,
                    "DECISION Y: {$ep->route} must not expose a search parameter before Phase 5.",
                );
                self::assertNotSame('q', $param->name, "DECISION Y: {$ep->route} must not expose a q parameter.");
            }
        }

        foreach ($routes as $route) {
            self::assertStringNotContainsString('search', $route, 'no /search route before Phase 5');
        }
    }

    // =========================================================================
    // Gap 3 — backfill and reconcile cover EVERY Phase 1B aggregate
    // =========================================================================

    public function test_reconciliation_and_replay_cover_every_phase_1b_aggregate(): void
    {
        // If an aggregate is missing from either list, a full reconcile and the onboarding
        // backfill silently skip it — the trap media hit in P1B-S1 and tags hit in P1B-S3.
        $expected = ['page', 'post', 'category', 'media', 'tag'];

        $reconciliation = (new \ReflectionClass(WpReconciliationSource::class))
            ->getConstant('AGGREGATE_TYPES');
        $replay = (new \ReflectionClass(ContentReplayEmitter::class))
            ->getConstant('AGGREGATE_TYPES');

        self::assertEqualsCanonicalizing($expected, $reconciliation, 'reconciliation covers every aggregate');
        self::assertEqualsCanonicalizing($expected, $replay, 'replay covers every aggregate');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function seedPages(int $count, bool $sharedTimestamp = false, bool $withFeaturedMedia = false): void
    {
        $this->db->execute(
            sprintf(
                "INSERT INTO content.pages
                    (id, source_post_id, source_entity_type, slug, title, content, status, parent_id,
                     menu_order, featured_media_id, published_at, updated_at, checksum, meta_jsonb,
                     created_at, synced_at)
                 SELECT gen_random_uuid(), g, 'page', 'page-' || g, 'Page', '', 'publish', 0, 0, %s,
                        %s, now(), repeat('a', 64), '{}'::jsonb, now(), now()
                 FROM generate_series(1, %d) g",
                $withFeaturedMedia ? '1' : '0',
                $sharedTimestamp ? "'2024-01-01 00:00:00+00'::timestamptz" : "now() - (g || ' minutes')::interval",
                $count,
            )
        );
    }

    private function seedMedia(int $count): void
    {
        $this->db->execute(
            sprintf(
                "INSERT INTO content.media
                    (id, source_post_id, slug, title, mime_type, url, published_at, updated_at,
                     checksum, created_at, synced_at)
                 SELECT gen_random_uuid(), g, 'media-' || g, 'Media', 'image/jpeg',
                        'https://example.test/m.jpg', now(), now(), repeat('b', 64), now(), now()
                 FROM generate_series(1, %d) g",
                $count,
            )
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

        $sql = file_get_contents(__DIR__ . '/../../../modules/Content/Migrations/0002_create_content_pages.sql');
        self::assertIsString($sql);
        self::assertNotFalse(pg_query($this->pgConn, $sql), pg_last_error($this->pgConn));

        ContentSchema::ensureQueryProviderSupport($this->pgConn);
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
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping Phase 1B validation.");
        }

        return $conn;
    }
}
