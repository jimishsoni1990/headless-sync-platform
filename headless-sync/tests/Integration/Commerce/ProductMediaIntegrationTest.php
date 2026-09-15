<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Content\Queries\MediaReferenceProvider;
use HSP\Tests\Support\CommerceSchema;
use HSP\Tests\Support\ContentSchema;
use PHPUnit\Framework\TestCase;

/**
 * Finding 011 against live PostgreSQL — Commerce product references resolved through Content's
 * implementation of the AG-10 capability, across two real schemas.
 *
 * The unit tests prove the rules with a faked capability. These prove the part a fake cannot:
 * that `commerce.products` and `content.media` are genuinely separate projections, that the
 * composition happens at READ time across them, and therefore that editing an attachment shows
 * up in a product response while the product row itself never moves. Both schemas are built by
 * executing the real migration files, so the index the lookup rides is the shipped one.
 *
 * Self-skips without PostgreSQL, per the suite convention.
 */
final class ProductMediaIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);

        CommerceSchema::applySystemTables($this->pgConn);
        CommerceSchema::applyAll($this->pgConn);
        ContentSchema::ensureFeaturedMediaSupport($this->pgConn);
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS commerce CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /** @param list<int> $gallery */
    private function seedProduct(int $sourceId, string $slug, int $featured, array $gallery): void
    {
        $this->db->execute(
            'INSERT INTO commerce.products
                (id, source_product_id, sku, slug, name, description, short_description, status,
                 product_type, catalog_visibility, featured, price, regular_price, sale_price,
                 featured_media_id, gallery_media_ids, published_at, updated_at, synced_at,
                 checksum, meta_jsonb)
             VALUES (gen_random_uuid(), $1, $2, $3, $4, $5, $6, $7, $8, $9, false,
                     $10, $10, NULL, $11, $12::jsonb, now(), now(), now(), $13, $14::jsonb)',
            [
                $sourceId,
                'SKU-' . $sourceId,
                $slug,
                'Product ' . $sourceId,
                'Long description',
                'Short',
                'publish',
                'simple',
                'visible',
                '19.99',
                $featured,
                json_encode($gallery),
                str_repeat('a', 64),
                '{}',
            ],
        );
    }

    private function seedMedia(int $sourceId, string $altText = 'original alt', int $width = 800): void
    {
        $this->db->execute(
            'INSERT INTO content.media
                (id, source_post_id, slug, title, mime_type, url, alt_text, caption, description,
                 width, height, sizes_jsonb, attached_to_id, published_at, updated_at, checksum,
                 meta_jsonb, created_at, synced_at)
             VALUES (gen_random_uuid(), $1, $2, $3, $4, $5, $6, \'\', \'\', $7, 600,
                     $8::jsonb, 0, now(), now(), $9, \'{}\'::jsonb, now(), now())',
            [
                $sourceId,
                'image-' . $sourceId,
                'Image ' . $sourceId,
                'image/jpeg',
                'https://example.test/uploads/' . $sourceId . '.jpg',
                $altText,
                $width,
                '{"thumbnail":{"url":"https://example.test/t.jpg","width":150,"height":150,"mime_type":"image/jpeg"}}',
                str_repeat('b', 64),
            ],
        );
    }

    private function provider(?DatabaseConnectionInterface $db = null): ProductQueryProvider
    {
        $connection = $db ?? $this->db;

        return new ProductQueryProvider($connection, new MediaReferenceProvider($connection));
    }

    /** @return array<string,mixed> */
    private function published(string $slug): array
    {
        $row = $this->provider()->findBySlug($slug);

        self::assertNotNull($row, "expected product {$slug} to be readable");

        return (new ProductResource())->toArray($row);
    }

    // =========================================================================
    // Resolution across the two schemas
    // =========================================================================

    public function test_a_products_featured_image_and_gallery_resolve_to_usable_objects(): void
    {
        $this->seedProduct(42, 'blue-widget', 101, [102, 103]);
        foreach ([101, 102, 103] as $id) {
            $this->seedMedia($id);
        }

        $media = $this->published('blue-widget')['media'];

        self::assertSame('https://example.test/uploads/101.jpg', $media['featured']['url']);
        self::assertSame('original alt', $media['featured']['alt_text']);
        self::assertSame(800, $media['featured']['width']);
        self::assertSame(600, $media['featured']['height']);
        self::assertSame(['thumbnail'], array_keys($media['featured']['sizes']));

        self::assertSame(
            ['https://example.test/uploads/102.jpg', 'https://example.test/uploads/103.jpg'],
            array_column($media['gallery'], 'url'),
        );
    }

    /**
     * The gallery keeps the STORE's order even when the media rows were inserted — and are
     * therefore naturally returned — in a different one.
     */
    public function test_the_gallery_keeps_store_order_against_a_real_row_order(): void
    {
        // Inserted ascending; referenced descending. A provider that published database order
        // would pass a same-order test and fail here.
        foreach ([201, 202, 203] as $id) {
            $this->seedMedia($id);
        }
        $this->seedProduct(43, 'ordered', 0, [203, 201, 202]);

        self::assertSame(
            [
                'https://example.test/uploads/203.jpg',
                'https://example.test/uploads/201.jpg',
                'https://example.test/uploads/202.jpg',
            ],
            array_column($this->published('ordered')['media']['gallery'], 'url'),
        );
    }

    /**
     * OUT-OF-ORDER PROJECTION (AG-7). A product may legitimately project before its attachments:
     * independent aggregates, non-FIFO delivery. The product must be readable, with no image and
     * no error — and must start resolving the moment the media lands, with no re-emission.
     */
    public function test_a_product_projected_before_its_media_resolves_later_with_no_re_emission(): void
    {
        $this->seedProduct(44, 'early-bird', 301, [302]);

        $before = $this->published('early-bird');
        self::assertNull($before['media']['featured']);
        self::assertSame([], $before['media']['gallery']);
        self::assertSame(301, $before['media']['featured_id'], 'the reference is retained');

        $rowBefore = $this->db->query(
            'SELECT checksum, synced_at, updated_at FROM commerce.products WHERE source_product_id = 44'
        )[0];

        $this->seedMedia(301);
        $this->seedMedia(302);

        $after = $this->published('early-bird');
        self::assertSame('https://example.test/uploads/301.jpg', $after['media']['featured']['url']);
        self::assertCount(1, $after['media']['gallery']);

        $rowAfter = $this->db->query(
            'SELECT checksum, synced_at, updated_at FROM commerce.products WHERE source_product_id = 44'
        )[0];

        self::assertSame($rowBefore, $rowAfter, 'no product row was rewritten to make media appear');
    }

    /**
     * A TOMBSTONED attachment resolves to null. The product stays valid and addressable, its
     * stored reference is untouched, and restoring the attachment brings the image back — again
     * with no product rewrite.
     */
    public function test_a_tombstoned_attachment_resolves_to_null_and_returns_when_restored(): void
    {
        $this->seedProduct(45, 'doomed-image', 401, []);
        $this->seedMedia(401);

        self::assertNotNull($this->published('doomed-image')['media']['featured']);

        $this->db->execute('UPDATE content.media SET deleted_at = now() WHERE source_post_id = 401');

        $tombstoned = $this->published('doomed-image');
        self::assertNull($tombstoned['media']['featured']);
        self::assertSame(401, $tombstoned['media']['featured_id']);

        $this->db->execute('UPDATE content.media SET deleted_at = NULL WHERE source_post_id = 401');

        self::assertNotNull($this->published('doomed-image')['media']['featured']);
    }

    /**
     * A partly-projected gallery publishes what exists, in order, and omits the rest. Nothing is
     * fabricated for the missing entry, and `gallery_ids` still names it.
     */
    public function test_a_partially_projected_gallery_publishes_what_exists_in_order(): void
    {
        $this->seedProduct(46, 'half-gallery', 0, [501, 502, 503]);
        $this->seedMedia(501);
        $this->seedMedia(503);

        $media = $this->published('half-gallery')['media'];

        self::assertSame(
            ['https://example.test/uploads/501.jpg', 'https://example.test/uploads/503.jpg'],
            array_column($media['gallery'], 'url'),
        );
        self::assertSame([501, 502, 503], $media['gallery_ids']);
    }

    // =========================================================================
    // Ownership: media metadata belongs to content.media
    // =========================================================================

    /**
     * THE PROPERTY AG-10 CHOSE COMPOSITION FOR. An image's alt text, URL and dimensions change
     * through the Content pipeline; the next product response reflects them, and the product row
     * — checksum, synced_at, updated_at — does not move at all.
     *
     * Had the media been denormalised onto `commerce.products`, the same edit would have needed
     * every referencing product re-saved and re-projected to converge.
     */
    public function test_editing_an_attachment_changes_the_product_response_but_not_the_product_row(): void
    {
        $this->seedProduct(47, 'stable-product', 601, [602]);
        $this->seedMedia(601, 'original alt');
        $this->seedMedia(602, 'gallery alt');

        $before    = $this->published('stable-product')['media'];
        $rowBefore = $this->db->query(
            'SELECT checksum, synced_at, updated_at FROM commerce.products WHERE source_product_id = 47'
        )[0];

        self::assertSame('original alt', $before['featured']['alt_text']);

        // A WordPress image edit, as the Content pipeline would project it.
        $this->db->execute(
            "UPDATE content.media
                SET alt_text = 'a better description',
                    url = 'https://example.test/uploads/601-scaled.jpg',
                    width = 1600,
                    updated_at = now(),
                    synced_at = now()
              WHERE source_post_id = 601"
        );

        $after    = $this->published('stable-product')['media'];
        $rowAfter = $this->db->query(
            'SELECT checksum, synced_at, updated_at FROM commerce.products WHERE source_product_id = 47'
        )[0];

        self::assertSame('a better description', $after['featured']['alt_text']);
        self::assertSame('https://example.test/uploads/601-scaled.jpg', $after['featured']['url']);
        self::assertSame(1600, $after['featured']['width']);

        self::assertSame($rowBefore, $rowAfter, 'media metadata is not product state');
    }

    /** No attachment metadata is stored on the Commerce side — references only (AG-10). */
    public function test_commerce_products_stores_no_attachment_metadata_columns(): void
    {
        $columns = array_column(
            $this->db->query(
                "SELECT column_name FROM information_schema.columns
                  WHERE table_schema = 'commerce' AND table_name = 'products'"
            ),
            'column_name',
        );

        foreach (['alt_text', 'media_url', 'image_url', 'sizes_jsonb', 'mime_type'] as $forbidden) {
            self::assertNotContains($forbidden, $columns);
        }

        self::assertContains('featured_media_id', $columns);
        self::assertContains('gallery_media_ids', $columns);
    }

    /** And no Commerce media table exists at all. */
    public function test_no_commerce_attachment_projection_table_exists(): void
    {
        $tables = array_column(
            $this->db->query(
                "SELECT table_name FROM information_schema.tables WHERE table_schema = 'commerce'"
            ),
            'table_name',
        );

        foreach (['media', 'product_media', 'gallery_media', 'attachments'] as $forbidden) {
            self::assertNotContains($forbidden, $tables);
        }
    }

    // =========================================================================
    // Performance
    // =========================================================================

    /**
     * A one-product page and a full page with galleries cost the SAME number of queries: one for
     * the products, one for every attachment on the page.
     *
     * Measured against the real connection, so this counts what PostgreSQL was actually asked —
     * the assertion an N+1 cannot survive.
     */
    public function test_media_resolution_costs_two_queries_for_any_page_size(): void
    {
        $mediaId = 700;
        for ($i = 1; $i <= 20; $i++) {
            $featured = $mediaId++;
            $gallery  = [];
            for ($g = 0; $g < 5; $g++) {
                $gallery[] = $mediaId++;
            }

            $this->seedProduct(1000 + $i, 'perf-' . $i, $featured, $gallery);
            $this->seedMedia($featured);
            foreach ($gallery as $id) {
                $this->seedMedia($id);
            }
        }

        $counter = new CountingMediaConnection($this->db);

        $this->provider($counter)->list(new ProductFilterSet(limit: 1));
        $one = $counter->queries;

        $counter->queries = 0;
        $this->provider($counter)->list(new ProductFilterSet(limit: 20));
        $full = $counter->queries;

        self::assertSame(2, $one, 'products + one bulk media lookup');
        self::assertSame(
            $one,
            $full,
            '20 products with 5 gallery images each (120 references) must cost the same two queries',
        );
    }

    /**
     * The bulk lookup is INDEX-BACKED. It rides `uq_content_media_source_post_id`, which P1B-S2
     * already created for the featured-image join — no new index was added for Finding 011.
     *
     * Seeded well above the planner's seq-scan-is-cheaper threshold, because an under-seeded
     * table makes a sequential scan the correct plan and the assertion meaningless.
     */
    public function test_the_bulk_media_lookup_is_index_backed_at_scale(): void
    {
        $this->db->execute(
            "INSERT INTO content.media
                (id, source_post_id, slug, title, mime_type, url, alt_text, caption, description,
                 width, height, sizes_jsonb, attached_to_id, published_at, updated_at, checksum,
                 meta_jsonb, created_at, synced_at)
             SELECT gen_random_uuid(), g, 'seed-' || g, 'Seed ' || g, 'image/jpeg',
                    'https://example.test/' || g || '.jpg', '', '', '', 800, 600,
                    '{}'::jsonb, 0, now(), now(), repeat('c', 64), '{}'::jsonb, now(), now()
               FROM generate_series(50000, 75000) AS g"
        );
        $this->db->execute('ANALYZE content.media');

        $plan = implode(' ', array_column(
            $this->db->query(
                'EXPLAIN SELECT source_post_id, slug, url, alt_text, mime_type, width, height, sizes_jsonb
                   FROM content.media
                  WHERE source_post_id IN ($1, $2, $3, $4, $5)
                    AND deleted_at IS NULL',
                [50001, 50002, 60000, 70000, 75000],
            ),
            'QUERY PLAN',
        ));

        self::assertStringNotContainsString('Seq Scan', $plan, "plan was: {$plan}");
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
            PGSQL_CONNECT_FORCE_NEW,
        );

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port}.");
        }

        return $conn;
    }
}

/** Counts real queries so the no-N+1 assertion measures rather than assumes. */
final class CountingMediaConnection implements DatabaseConnectionInterface
{
    public int $queries = 0;

    public function __construct(private readonly DatabaseConnectionInterface $inner)
    {
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->inner->execute($sql, $params);
    }

    /**
     * @param  list<mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $this->queries++;

        return $this->inner->query($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }
}
