<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Tests\Support\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * Nested pages sharing a leaf slug (FLAG-PAGESLUG-1) — the case that had NO coverage at all.
 *
 * WordPress enforces page slug uniqueness WITHIN a parent, not globally, so `/about/team` and
 * `/services/team` are both legal and both reach content.pages as slug='team'.
 *
 * This test pins the MITIGATION shipped ahead of the ruling: the lookup is now deterministic and
 * prefers the top-level page. It deliberately does NOT assert that a specific nested page can be
 * addressed — that requires the published-contract change still under FLAG-PAGESLUG-1, and this
 * test should be REPLACED (not merely extended) when that lands.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class PageSlugAmbiguityIntegrationTest extends TestCase
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

    public function test_two_nested_pages_sharing_a_leaf_slug_resolve_deterministically(): void
    {
        // Both legal in WordPress: /about/team and /services/team.
        $this->seedPage(10, 'team', parentId: 3);
        $this->seedPage(11, 'team', parentId: 4);

        $provider = new PageQueryProvider($this->db);

        $first = $provider->findBySlug('team');
        self::assertNotNull($first);

        // The real defect was instability: without an ORDER BY the answer could differ between
        // identical requests, which is unreportable and unreproducible.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(
                $first['id'],
                $provider->findBySlug('team')['id'],
                'repeated identical requests must return the same page',
            );
        }
    }

    public function test_a_top_level_page_wins_over_a_nested_namesake(): void
    {
        // parent_id 0 sorts first — the least surprising answer for a bare slug lookup.
        $this->seedPage(11, 'team', parentId: 4);
        $topLevel = $this->seedPage(12, 'team', parentId: 0);

        self::assertSame(
            $topLevel,
            (new PageQueryProvider($this->db))->findBySlug('team')['id'],
        );
    }

    public function test_an_unambiguous_slug_is_unaffected(): void
    {
        $id = $this->seedPage(20, 'contact', parentId: 0);

        self::assertSame($id, (new PageQueryProvider($this->db))->findBySlug('contact')['id']);
    }

    private function seedPage(int $sourceId, string $slug, int $parentId): string
    {
        $rows = $this->db->query(
            "INSERT INTO content.pages
                (id, source_post_id, source_entity_type, slug, title, content, status, parent_id,
                 menu_order, featured_media_id, published_at, updated_at, checksum, meta_jsonb,
                 created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, 'page', \$2, 'Team', '', 'publish', \$3, 0, 0,
                     now(), now(), repeat('a', 64), '{}'::jsonb, now(), now())
             RETURNING id",
            [$sourceId, $slug, $parentId]
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
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping page slug tests.");
        }

        return $conn;
    }
}
