<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\TermValidator;
use PHPUnit\Framework\TestCase;

/**
 * P2-S3 — Product ↔ Product Category convergence, driven through the REAL handlers.
 *
 * THIS TEST EXISTS BECAUSE OF A SHIPPED BUG. P1B-S3 delivered tags for Content with every test
 * seeding `content.entity_taxonomies` BY HAND, so the assertions passed against fixtures the
 * pipeline never produced. Nothing wrote post→tag links at all: `PostAdapter` full-replaced the
 * join from the category ids alone, so every post's tags array was permanently empty and the
 * `?tag=` filter matched nothing. It shipped, and was found hours later while costing an
 * unrelated flag.
 *
 * So nothing here seeds a join row. Every relationship below is produced by driving
 * `ProductUpsertHandler` and `TermUpsertHandler` — the actual classes the worker runs.
 */
final class ProductCategoryLinkIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private FakeCommerceSource $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new FakeCommerceSource();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS commerce CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    private function productHandler(): ProductUpsertHandler
    {
        return new ProductUpsertHandler(
            $this->loader,
            new ProductExtractor(new ProductValidator()),
            new ProductTransformer(),
            new ProductAdapter($this->db),
        );
    }

    private function termHandler(): TermUpsertHandler
    {
        return new TermUpsertHandler(
            $this->loader,
            new TermExtractor(new TermValidator()),
            new TermTransformer(),
            new TermAdapter($this->db),
        );
    }

    /** Project a category, then a product carrying it — the ordinary order. */
    private function givenCategory(int $termId, string $slug): void
    {
        $this->loader->terms[$termId] = [
            'term_id' => $termId, 'taxonomy' => 'product_cat', 'slug' => $slug,
            'name' => ucfirst($slug), 'description' => '', 'parent' => 0, 'count' => 0,
        ];

        $this->termHandler()->handle(
            new IntegrationTermEvent('commerce.product_category.created', (string) $termId, 1, "t{$termId}")
        );
    }

    /** @param list<int> $categoryIds */
    private function givenProduct(int $id, string $slug, array $categoryIds, int $version = 1, string $key = ''): void
    {
        $this->loader->products[$id] = [
            'id' => $id, 'sku' => "SKU-{$id}", 'slug' => $slug, 'name' => "Product {$id}",
            'description' => '', 'short_description' => '', 'status' => 'publish',
            'product_type' => 'simple', 'catalog_visibility' => 'visible', 'featured' => false,
            'price' => '10.00', 'regular_price' => '10.00', 'sale_price' => null,
            'featured_media_id' => 0, 'gallery_media_ids' => [],
            'published_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
            'meta' => [], 'category_ids' => $categoryIds,
        ];

        $this->productHandler()->handle(
            new IntegrationCommerceEvent(
                'commerce.product.updated',
                (string) $id,
                $version,
                $key !== '' ? $key : "p{$id}-{$version}",
            )
        );
    }

    // =========================================================================
    // The link the real handler must produce
    // =========================================================================

    public function test_the_real_handler_links_a_product_to_its_category(): void
    {
        $this->givenCategory(10, 'clothing');
        $this->givenProduct(1, 'shirt', [10]);

        self::assertSame(1, $this->linkCount(1), 'the pipeline itself must write the join row');
    }

    public function test_a_product_in_several_categories_gets_a_row_for_each(): void
    {
        $this->givenCategory(10, 'clothing');
        $this->givenCategory(11, 'sale');
        $this->givenProduct(1, 'shirt', [10, 11]);

        self::assertSame(2, $this->linkCount(1));
    }

    /**
     * The write-suppress trap, third time it has appeared in this platform: a category-only
     * change moves nothing else on the product, so unless `categoryIds` is in the canonical
     * checksum, DECISION 3 suppresses the write and the join rewrite never runs.
     */
    public function test_changing_only_the_categories_still_rewrites_the_links(): void
    {
        $this->givenCategory(10, 'clothing');
        $this->givenCategory(11, 'sale');

        $this->givenProduct(1, 'shirt', [10], version: 1);
        self::assertSame([10], $this->linkedTermIds(1));

        // Nothing but the category set changes.
        $this->givenProduct(1, 'shirt', [11], version: 2);

        self::assertSame(
            [11],
            $this->linkedTermIds(1),
            'a category-only change must not be write-suppressed',
        );
    }

    public function test_removing_a_category_removes_the_relationship(): void
    {
        $this->givenCategory(10, 'clothing');
        $this->givenCategory(11, 'sale');

        $this->givenProduct(1, 'shirt', [10, 11], version: 1);
        self::assertSame(2, $this->linkCount(1));

        $this->givenProduct(1, 'shirt', [10], version: 2);

        self::assertSame([10], $this->linkedTermIds(1));
    }

    /**
     * AG-7 in the join, and the reason the link is keyed by SOURCE term id.
     *
     * A product routinely projects before its categories. The link row is written immediately
     * regardless, because it is a pure function of the product's own state — and it becomes
     * resolvable the moment the term lands, with no second write to the product.
     *
     * The first implementation stored the term's PROJECTION uuid, which made this case a real
     * defect rather than a delay: the insert matched nothing, and re-projecting the product
     * later could not repair it, because none of the product's own state had changed, so the
     * checksum did not move and DECISION 3 correctly suppressed the write. Reconciliation
     * compares those same checksums, so it would never have seen the gap either — the link
     * would simply never have appeared.
     */
    public function test_a_product_may_reference_a_category_that_has_not_projected_yet(): void
    {
        $this->givenProduct(1, 'shirt', [10], version: 1);

        // The relationship is recorded straight away…
        self::assertSame(1, $this->rawLinkCount(1), 'the link must not depend on term arrival order');
        // …but has nothing to resolve against yet, which is a soft reference behaving correctly.
        self::assertSame(0, $this->linkCount(1));

        // The term lands. NO further product write happens — and none is needed.
        $this->givenCategory(10, 'clothing');

        self::assertSame(1, $this->linkCount(1), 'the link resolves with no second product write');
    }

    /** The filter finds the product too, once the term exists — still with no product rewrite. */
    public function test_an_out_of_order_category_becomes_filterable_without_reprojecting_the_product(): void
    {
        $this->givenProduct(1, 'shirt', [10], version: 1);
        $this->givenCategory(10, 'clothing');

        $slugs = array_column(
            (new ProductQueryProvider($this->db))->list(new ProductFilterSet(categorySlug: 'clothing'))->rows,
            'slug',
        );

        self::assertSame(['shirt'], $slugs);
    }

    // =========================================================================
    // The delivery filter over that relationship
    // =========================================================================

    public function test_the_category_filter_matches_products_in_that_category(): void
    {
        $this->givenCategory(10, 'clothing');
        $this->givenCategory(11, 'sale');

        $this->givenProduct(1, 'shirt', [10]);
        $this->givenProduct(2, 'mug', [11]);

        $slugs = array_column(
            (new ProductQueryProvider($this->db))->list(new ProductFilterSet(categorySlug: 'clothing'))->rows,
            'slug',
        );

        self::assertSame(['shirt'], $slugs);
    }

    /**
     * commerce.taxonomies is SHARED, so the filter must constrain taxonomy_type. Without it an
     * attribute term with the same slug would match — the DECISION AA defect class, which in
     * Content shipped three times.
     */
    public function test_the_category_filter_does_not_match_a_same_slug_attribute_term(): void
    {
        $this->givenCategory(10, 'blue');

        // A pa_colour term sharing the slug, linked to a different product.
        $this->loader->terms[20] = [
            'term_id' => 20, 'taxonomy' => 'product_cat', 'slug' => 'unused',
            'name' => 'Unused', 'description' => '', 'parent' => 0, 'count' => 0,
        ];
        $this->termHandler()->handle(
            new IntegrationTermEvent('commerce.product_category.created', '20', 1, 't20')
        );
        pg_query($this->pgConn, "UPDATE commerce.taxonomies SET taxonomy_type = 'pa_colour', slug = 'blue' WHERE source_term_id = 20");

        $this->givenProduct(1, 'shirt', [10]);
        $this->givenProduct(2, 'mug', [20]);

        $slugs = array_column(
            (new ProductQueryProvider($this->db))->list(new ProductFilterSet(categorySlug: 'blue'))->rows,
            'slug',
        );

        self::assertSame(['shirt'], $slugs, 'only the product_cat term may match');
    }

    /**
     * A product in two matching categories must appear ONCE. This is why the filter is an
     * EXISTS rather than a JOIN: a join would duplicate the row, and the DISTINCT that fixes
     * that would then break the cursor.
     */
    public function test_a_product_in_two_matching_categories_appears_once(): void
    {
        $this->givenCategory(10, 'clothing');
        $this->givenCategory(11, 'clothing-2');
        $this->givenProduct(1, 'shirt', [10, 11]);

        $rows = (new ProductQueryProvider($this->db))
            ->list(new ProductFilterSet(categorySlug: 'clothing'))
            ->rows;

        self::assertCount(1, $rows);
    }

    /** PERFORMANCE DoD: the filtered listing must be index-backed in both directions. */
    public function test_the_category_filtered_listing_is_index_backed(): void
    {
        $this->seedAtScale(1000, 500);
        pg_query($this->pgConn, 'ANALYZE commerce.products');
        pg_query($this->pgConn, 'ANALYZE commerce.taxonomies');
        pg_query($this->pgConn, 'ANALYZE commerce.entity_taxonomies');

        $plan = $this->explain(
            "SELECT p.id FROM commerce.products p
             WHERE p.deleted_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM commerce.entity_taxonomies et
                   JOIN commerce.taxonomies t ON t.source_term_id = et.source_term_id
                   WHERE et.entity_id = p.id AND t.slug = 'term-250'
                     AND t.taxonomy_type = 'product_cat' AND t.deleted_at IS NULL
               )
             ORDER BY p.published_at DESC, p.id DESC LIMIT 20"
        );

        self::assertStringNotContainsString('Seq Scan on taxonomies', $plan, $plan);
        self::assertStringNotContainsString('Seq Scan on entity_taxonomies', $plan, $plan);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** Link rows that exist, whether or not the term has projected. */
    private function rawLinkCount(int $sourceProductId): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS c
             FROM commerce.entity_taxonomies et
             JOIN commerce.products p ON p.id = et.entity_id
             WHERE p.source_product_id = $1',
            [$sourceProductId],
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /** Link rows that RESOLVE to a projected term. */
    private function linkCount(int $sourceProductId): int
    {
        $rows = $this->db->query(
            'SELECT COUNT(*) AS c
             FROM commerce.entity_taxonomies et
             JOIN commerce.products p   ON p.id = et.entity_id
             JOIN commerce.taxonomies t ON t.source_term_id = et.source_term_id
             WHERE p.source_product_id = $1 AND t.deleted_at IS NULL',
            [$sourceProductId],
        );

        return (int) ($rows[0]['c'] ?? 0);
    }

    /** @return list<int> */
    private function linkedTermIds(int $sourceProductId): array
    {
        $rows = $this->db->query(
            'SELECT t.source_term_id
             FROM commerce.entity_taxonomies et
             JOIN commerce.products p   ON p.id = et.entity_id
             JOIN commerce.taxonomies t ON t.source_term_id = et.source_term_id
             WHERE p.source_product_id = $1
             ORDER BY t.source_term_id',
            [$sourceProductId],
        );

        return array_map(static fn (array $r): int => (int) $r['source_term_id'], $rows);
    }

    private function explain(string $sql): string
    {
        $result = pg_query($this->pgConn, 'EXPLAIN ' . $sql);
        $plan   = '';

        while ($row = pg_fetch_row($result)) {
            $plan .= $row[0] . "\n";
        }

        return $plan;
    }

    private function seedAtScale(int $products, int $terms): void
    {
        $termRows = [];
        for ($i = 1; $i <= $terms; $i++) {
            $termRows[] = sprintf(
                "(gen_random_uuid(), %d, 'product_cat', 'term-%d', 'Term %d', '', 0, 0, '%s')",
                $i,
                $i,
                $i,
                str_repeat('b', 64),
            );
        }
        pg_query($this->pgConn, '
            INSERT INTO commerce.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, term_count, checksum)
            VALUES ' . implode(',', $termRows));

        $productRows = [];
        for ($i = 1; $i <= $products; $i++) {
            $productRows[] = sprintf(
                "(gen_random_uuid(), %d, 'SKU-%d', 'p-%d', 'P %d', 'publish', 'simple', 'visible',
                  false, 10, NOW() - (%d * INTERVAL '1 minute'), NOW(), NOW(), NOW(), '%s', '{}')",
                1000 + $i,
                1000 + $i,
                1000 + $i,
                $i,
                $i,
                str_repeat('a', 64),
            );
        }
        pg_query($this->pgConn, '
            INSERT INTO commerce.products
                (id, source_product_id, sku, slug, name, status, product_type, catalog_visibility,
                 featured, price, published_at, updated_at, created_at, synced_at, checksum, meta_jsonb)
            VALUES ' . implode(',', $productRows));

        // Several thousand join rows — at a smaller size a sequential scan IS the cheap plan
        // and the assertion would prove nothing, the mistake P1B-S3 made and had to correct.
        pg_query($this->pgConn, '
            INSERT INTO commerce.entity_taxonomies (entity_id, source_term_id)
            SELECT p.id, t.source_term_id
            FROM commerce.products p
            JOIN commerce.taxonomies t
              ON (t.source_term_id % 500) = (p.source_product_id % 500)
            ON CONFLICT DO NOTHING');
    }

    private function createSchema(): void
    {
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

        $dir = \dirname(__DIR__, 3) . '/modules/Commerce/Migrations';

        foreach ([
            '0001_create_commerce_schema.sql',
            '0002_create_commerce_products.sql',
            '0003_create_commerce_taxonomies.sql',
            '0004_create_commerce_entity_taxonomies.sql',
        ] as $file) {
            $sql = file_get_contents($dir . '/' . $file);
            self::assertIsString($sql, "missing migration {$file}");
            pg_query($this->pgConn, $sql);
        }
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
