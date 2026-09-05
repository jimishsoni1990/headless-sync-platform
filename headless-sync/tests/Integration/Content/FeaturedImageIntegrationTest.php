<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Contracts\FilterSet;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Tests\Support\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * Featured images against live PostgreSQL (P1B-S2 DoD).
 *
 * Covers what a fake connection cannot: that the LEFT JOIN actually resolves, that a
 * soft-deleted or never-projected attachment degrades to null instead of dropping the post or
 * erroring, and that a media edit is visible to consumers WITHOUT re-saving the post — the
 * property that made read-time resolution the right choice over write-time denormalisation.
 *
 * Schema is built from the REAL migration files (0003 posts, 0006 media, 0007 featured media),
 * so the ALTER TABLE is proven to apply as well.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class FeaturedImageIntegrationTest extends TestCase
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

    public function test_a_post_with_a_featured_image_serves_the_resolved_media(): void
    {
        $this->seedMedia(42, 'sunset');
        $this->seedPost(7, 'a-post', featuredMediaId: 42);

        $body = (new PostResource())->toArray(
            (new PostQueryProvider($this->db))->findBySlug('a-post')
        );

        self::assertIsArray($body['featured_media']);
        self::assertSame('sunset', $body['featured_media']['slug']);
        self::assertSame('https://example.test/uploads/sunset.jpg', $body['featured_media']['url']);
        self::assertSame('A sunset', $body['featured_media']['alt_text']);
        self::assertSame(1600, $body['featured_media']['width']);
    }

    public function test_a_post_without_a_featured_image_is_still_served(): void
    {
        $this->seedPost(7, 'a-post', featuredMediaId: 0);

        $body = (new PostResource())->toArray(
            (new PostQueryProvider($this->db))->findBySlug('a-post')
        );

        self::assertSame('a-post', $body['slug']);
        self::assertNull($body['featured_media']);
    }

    public function test_editing_the_attachment_is_visible_without_re_saving_the_post(): void
    {
        $this->seedMedia(42, 'sunset');
        $this->seedPost(7, 'a-post', featuredMediaId: 42);

        // The media row changes; the POST row is not touched at all.
        $this->db->execute(
            "UPDATE content.media SET alt_text = 'Updated alt', url = 'https://example.test/uploads/sunset-v2.jpg'
             WHERE source_post_id = 42"
        );

        $body = (new PostResource())->toArray(
            (new PostQueryProvider($this->db))->findBySlug('a-post')
        );

        // This is the whole argument for read-time resolution: no re-emission of every
        // referencing post, and no window where consumers serve a stale copy.
        self::assertSame('Updated alt', $body['featured_media']['alt_text']);
        self::assertSame('https://example.test/uploads/sunset-v2.jpg', $body['featured_media']['url']);
    }

    public function test_a_soft_deleted_attachment_leaves_the_post_valid_and_served(): void
    {
        $this->seedMedia(42, 'sunset');
        $this->seedPost(7, 'a-post', featuredMediaId: 42);

        $this->db->execute("UPDATE content.media SET deleted_at = now() WHERE source_post_id = 42");

        $row = (new PostQueryProvider($this->db))->findBySlug('a-post');

        self::assertNotNull($row, 'the post must still be served — the reference is soft (ADR-013)');
        self::assertNull(
            (new PostResource())->toArray($row)['featured_media'],
            'a deleted attachment resolves to null, never a dangling reference',
        );
    }

    public function test_a_reference_to_an_attachment_that_was_never_projected_resolves_to_null(): void
    {
        // Replay ordering: the post can land before its attachment. That must not 500 or drop it.
        $this->seedPost(7, 'a-post', featuredMediaId: 999);

        $row = (new PostQueryProvider($this->db))->findBySlug('a-post');

        self::assertNotNull($row);
        self::assertNull((new PostResource())->toArray($row)['featured_media']);
    }

    public function test_a_listing_mixes_resolved_and_null_images_in_one_query(): void
    {
        $this->seedMedia(42, 'sunset');
        $this->seedPost(1, 'with-image', featuredMediaId: 42);
        $this->seedPost(2, 'without-image', featuredMediaId: 0);
        $this->seedPost(3, 'dangling', featuredMediaId: 999);

        $page = (new PostQueryProvider($this->db))->list(new FilterSet(limit: 10));
        $body = (new PostResource())->toCollection($page->rows, $page->nextCursor);

        self::assertCount(3, $body['data'], 'every post is listed regardless of image state');

        $bySlug = [];
        foreach ($body['data'] as $item) {
            $bySlug[$item['slug']] = $item['featured_media'];
        }

        self::assertIsArray($bySlug['with-image']);
        self::assertNull($bySlug['without-image']);
        self::assertNull($bySlug['dangling']);
    }

    public function test_the_join_is_index_backed_at_scale(): void
    {
        // BOTH tables must be realistically sized or this assertion is meaningless: against a
        // one-row content.media the planner correctly prefers a seq scan + materialize over
        // hundreds of index lookups, and would "fail" a test that proves nothing. Seed a media
        // library large enough that the index is genuinely the cheaper path, with each post
        // pointing at a DISTINCT attachment so the join cannot be collapsed.
        for ($i = 1; $i <= 2000; $i++) {
            $this->seedMedia(1000 + $i, 'media-' . $i);
        }
        for ($i = 1; $i <= 500; $i++) {
            $this->seedPost($i + 100, 'seed-' . $i, featuredMediaId: 1000 + $i);
        }
        $this->db->execute('ANALYZE content.posts');
        $this->db->execute('ANALYZE content.media');

        $rows = $this->db->query(
            "EXPLAIN SELECT p.id, fm.url
             FROM content.posts p
             LEFT JOIN content.media fm ON fm.source_post_id = p.featured_media_id AND fm.deleted_at IS NULL
             WHERE p.deleted_at IS NULL AND p.status = 'publish'
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT 21"
        );
        $plan = implode("\n", array_map(static fn (array $r): string => (string) reset($r), $rows));

        // The join rides uq_content_media_source_post_id; a sequential scan of content.media per
        // page would be the N+1 problem wearing a different hat.
        self::assertStringNotContainsString(
            'Seq Scan on media',
            $plan,
            "The featured-media join must use the source_post_id unique index.\nPlan:\n{$plan}",
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function seedPost(int $sourceId, string $slug, int $featuredMediaId): void
    {
        $this->db->execute(
            "INSERT INTO content.posts
                (id, source_post_id, source_entity_type, slug, title, content, excerpt, status,
                 author, featured_media_id, published_at, updated_at, checksum, meta_jsonb,
                 created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, 'post', \$2, 'Post', '', '', 'publish',
                     'editor', \$3, now(), now(), repeat('a', 64), '{}'::jsonb, now(), now())",
            [$sourceId, $slug, $featuredMediaId]
        );
    }

    private function seedMedia(int $sourceId, string $slug): void
    {
        $this->db->execute(
            "INSERT INTO content.media
                (id, source_post_id, slug, title, mime_type, url, alt_text, width, height,
                 published_at, updated_at, checksum, created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, \$2, 'Sunset', 'image/jpeg',
                     'https://example.test/uploads/sunset.jpg', 'A sunset', 1600, 900,
                     now(), now(), repeat('b', 64), now(), now())",
            [$sourceId, $slug]
        );
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');

        // Real migration files, in order — this also proves the 0007 ALTER applies.
        foreach ([
            '0003_create_content_posts.sql',
            '0006_create_content_media.sql',
            '0007_add_featured_media_to_content_entities.sql',
        ] as $file) {
            $path = __DIR__ . '/../../../modules/Content/Migrations/' . $file;
            $sql  = file_get_contents($path);
            self::assertIsString($sql, "migration {$file} must be readable");

            // 0007 alters content.pages too, which this test does not create; create a minimal
            // stand-in so the real file can run unmodified.
            if ($file === '0007_add_featured_media_to_content_entities.sql') {
                pg_query($this->pgConn, 'CREATE TABLE IF NOT EXISTS content.pages (id UUID PRIMARY KEY)');
            }

            $result = pg_query($this->pgConn, $sql);
            self::assertNotFalse($result, "migration {$file} must apply: " . pg_last_error($this->pgConn));
        }

        // P1B-S3: the post query provider now always aggregates the post's tags, so the taxonomy
        // tables must exist even though this test is only about featured images.
        ContentSchema::ensureTaxonomySupport($this->pgConn);
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
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping featured-image integration tests.");
        }

        return $conn;
    }
}
