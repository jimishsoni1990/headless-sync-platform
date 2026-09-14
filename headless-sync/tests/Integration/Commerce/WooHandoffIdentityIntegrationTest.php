<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Adapters\VariationAdapter;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Queries\VariationFilterSet;
use HSP\Modules\Commerce\Queries\VariationQueryProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\VariationValidator;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\TestCase;

/**
 * Finding 009 — the Woo handoff identity survives the WHOLE pipeline, not just a Resource.
 *
 * A unit test that hands `['source_product_id' => 123]` straight to a Resource proves the last
 * hop and nothing before it. What has to be true for a cart handoff is stronger: the integer a
 * consumer receives must be the one WooCommerce reported, unchanged by extraction, transformation,
 * canonical modelling, projection, checksum construction or the delivery query. So this runs the
 * REAL spine — extractor → transformer → canonical model → adapter → live PostgreSQL (schema from
 * the real migration files) → query provider → resource — and compares the published value with
 * the source fixture's id.
 *
 * ENVIRONMENT LIMIT, stated rather than glossed: the first hop uses `InMemoryCommerceLoader` in
 * place of `WpCommerceLoaderImpl`, because the loader needs a booted WordPress with WooCommerce
 * and the integration suite has neither. That single hop — `$product->get_id()` and
 * `$variation->get_parent_id()` becoming the loader's `id` / `parent_id` — is proven instead
 * against the live site, by comparing published values with real `wc_get_product()` objects.
 *
 * Ids are chosen far apart (123 / 200 / 245 / 246 / 311 / 999) so no assertion can pass on a
 * numeric coincidence, and the cross-parent case exists because "both columns hold an integer"
 * is exactly what a projection bug looks like.
 */
final class WooHandoffIdentityIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private InMemoryCommerceLoader $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new InMemoryCommerceLoader();

        \HSP\Tests\Support\CommerceSchema::applySystemTables($this->pgConn);
        \HSP\Tests\Support\CommerceSchema::applyAll($this->pgConn);
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

    // =========================================================================
    // Product identity
    // =========================================================================

    /**
     * A simple product's Woo id reaches the consumer intact.
     *
     * The lookup goes through `findBySlug()`, i.e. the addressing HSP actually publishes — the Woo
     * id is what comes OUT of that request, never what goes into it.
     */
    public function test_a_simple_products_woo_id_survives_the_whole_pipeline(): void
    {
        $this->projectProduct(123, 'hat', 'simple');

        $published = $this->publishedProduct('hat');

        self::assertSame(123, $published['woo_product_id']);
        self::assertSame(123, $published['source_id'], 'the explicit field aliases, never replaces');
    }

    /** A variable parent publishes the parent id — the first half of a variable handoff. */
    public function test_a_variable_parents_woo_id_survives_the_whole_pipeline(): void
    {
        $this->projectProduct(200, 'hoodie', 'variable');

        self::assertSame(200, $this->publishedProduct('hoodie')['woo_product_id']);
    }

    /**
     * The projection row's own uuid is generated per row and shares nothing with the source id, so
     * this also proves the published value is not the row identity under another name.
     */
    public function test_the_published_woo_id_is_the_source_id_and_not_the_projection_uuid(): void
    {
        $this->projectProduct(123, 'hat', 'simple');

        $row       = $this->db->query('SELECT id, source_product_id FROM commerce.products')[0];
        $published = $this->publishedProduct('hat');

        self::assertSame(123, (int) $row['source_product_id']);
        self::assertSame((int) $row['source_product_id'], $published['woo_product_id']);
        self::assertNotSame((string) $row['id'], (string) $published['woo_product_id']);
    }

    /** The listing surface agrees with the single-item surface. One identity, two routes. */
    public function test_the_listing_publishes_the_same_woo_id_as_the_lookup(): void
    {
        $this->projectProduct(123, 'hat', 'simple');

        $page   = (new ProductQueryProvider($this->db))->list(new ProductFilterSet());
        $listed = (new ProductResource())->toCollection($page->rows, $page->nextCursor);

        self::assertSame(123, $listed['data'][0]['woo_product_id']);
        self::assertSame($this->publishedProduct('hat')['woo_product_id'], $listed['data'][0]['woo_product_id']);
    }

    // =========================================================================
    // Variation identity
    // =========================================================================

    /**
     * The two halves of a variable-product handoff, from the source fixture to the response.
     */
    public function test_a_variation_publishes_its_own_and_its_parents_woo_id(): void
    {
        $this->projectProduct(200, 'hoodie', 'variable');
        $this->projectVariation(245, 200);

        $published = $this->publishedVariations(200);

        self::assertCount(1, $published);
        self::assertSame(200, $published[0]['woo_product_id']);
        self::assertSame(245, $published[0]['woo_variation_id']);
    }

    /** Siblings: one shared parent id, two distinct variation ids, in the store's own order. */
    public function test_siblings_share_the_parent_woo_id_and_keep_their_own(): void
    {
        $this->projectProduct(200, 'hoodie', 'variable');
        $this->projectVariation(245, 200, menuOrder: 0);
        $this->projectVariation(246, 200, menuOrder: 1);

        $published = $this->publishedVariations(200);

        self::assertSame([245, 246], array_column($published, 'woo_variation_id'));
        self::assertSame([200, 200], array_column($published, 'woo_product_id'));
    }

    /**
     * PARENT INTEGRITY (§26). Two variable products with their own variations, projected together:
     * the contract must never be able to pair a variation id from one product with a parent id
     * from the other. Both columns holding an integer is precisely what that bug looks like, so
     * the assertion is on the exact pairing, not on types or counts.
     */
    public function test_a_variations_parent_id_is_its_own_parent_and_not_another_products(): void
    {
        $this->projectProduct(200, 'hoodie', 'variable');
        $this->projectProduct(311, 'cap', 'variable');
        $this->projectVariation(245, 200);
        $this->projectVariation(246, 200);
        $this->projectVariation(999, 311);

        $hoodie = $this->publishedVariations(200);
        $cap    = $this->publishedVariations(311);

        self::assertSame([245, 246], array_column($hoodie, 'woo_variation_id'));
        self::assertSame([200, 200], array_column($hoodie, 'woo_product_id'));

        self::assertSame([999], array_column($cap, 'woo_variation_id'));
        self::assertSame([311], array_column($cap, 'woo_product_id'));

        // And the parent id a variation publishes is the id its own parent product publishes —
        // the join a storefront would actually make.
        self::assertSame($this->publishedProduct('hoodie')['woo_product_id'], $hoodie[0]['woo_product_id']);
        self::assertSame($this->publishedProduct('cap')['woo_product_id'], $cap[0]['woo_product_id']);
    }

    /**
     * The parent reference is carried, not reconstructed. Re-parenting the variation in the source
     * moves the published parent id and leaves the variation id alone, which is only possible if
     * the value tracks `get_parent_id()` rather than being derived from anything on the variation.
     */
    public function test_re_parenting_moves_the_parent_id_and_nothing_else(): void
    {
        $this->projectProduct(200, 'hoodie', 'variable');
        $this->projectProduct(311, 'cap', 'variable');
        $this->projectVariation(245, 200);

        self::assertSame(200, $this->publishedVariations(200)[0]['woo_product_id']);

        $this->projectVariation(245, 311, version: 2);

        self::assertSame([], $this->publishedVariations(200));

        $moved = $this->publishedVariations(311);
        self::assertSame(311, $moved[0]['woo_product_id']);
        self::assertSame(245, $moved[0]['woo_variation_id']);
    }

    /**
     * A variation projecting BEFORE its parent still publishes the correct parent id — the whole
     * reason AG-7 keys the reference on the source id rather than the parent's projection uuid.
     * A handoff identity that only became correct once the parent happened to arrive would be
     * useless under at-least-once, non-FIFO delivery.
     */
    public function test_the_parent_woo_id_is_correct_even_when_the_variation_projects_first(): void
    {
        // The parent EXISTS in WooCommerce — it simply has not projected yet, which is the real
        // out-of-order case. (A variation whose parent does not exist at all is a different
        // scenario, owned by the AG-13 scope tests.)
        $this->givenProduct(200, 'hoodie', 'variable');
        $this->projectVariation(245, 200);

        $published = $this->publishedVariations(200);

        self::assertSame(200, $published[0]['woo_product_id']);
        self::assertSame(245, $published[0]['woo_variation_id']);
        self::assertSame([], $this->db->query('SELECT id FROM commerce.products'), 'parent not yet projected');
    }

    // -------------------------------------------------------------------------
    // Spine
    // -------------------------------------------------------------------------

    private function projectProduct(int $id, string $slug, string $type): void
    {
        $this->givenProduct($id, $slug, $type);

        (new ProductUpsertHandler(
            $this->loader,
            new ProductExtractor(new ProductValidator()),
            new ProductTransformer(),
            new ProductAdapter($this->db),
        ))->handle($this->event('commerce.product.updated', 'product', $id, 1));
    }

    /** Present in the source, not yet projected — the state every out-of-order case starts from. */
    private function givenProduct(int $id, string $slug, string $type): void
    {
        $this->loader->products[$id] = [
            'id' => $id, 'sku' => "P-{$id}", 'slug' => $slug, 'name' => "Product {$id}",
            'description' => '', 'short_description' => '', 'status' => 'publish',
            'product_type' => $type, 'catalog_visibility' => 'visible', 'featured' => false,
            'price' => '19.99', 'regular_price' => '19.99', 'sale_price' => null,
            'featured_media_id' => 0, 'gallery_media_ids' => [],
            'published_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
            'meta' => [], 'category_ids' => [], 'attribute_term_ids' => [],
        ];
    }

    private function projectVariation(int $id, int $parentId, int $menuOrder = 0, int $version = 1): void
    {
        $this->loader->variations[$id] = [
            'id'                 => $id,
            'parent_id'          => $parentId,
            'sku'                => "SKU-{$id}",
            'name'               => "Variation {$id}",
            'description'        => '',
            'status'             => 'publish',
            'price'              => '19.99',
            'regular_price'      => '19.99',
            'sale_price'         => null,
            'featured_media_id'  => 0,
            'menu_order'         => $menuOrder,
            'attributes'         => ['pa_colour' => 'blue'],
            'attribute_term_ids' => [],
            'modified_at'        => '2026-01-01 00:00:00',
        ];

        (new VariationUpsertHandler(
            $this->loader,
            new VariationExtractor(new VariationValidator()),
            new VariationTransformer(),
            new VariationAdapter($this->db),
        ))->handle($this->event('commerce.product_variation.updated', 'product_variation', $id, $version));
    }

    /** @return array<string,mixed> */
    private function publishedProduct(string $slug): array
    {
        $row = (new ProductQueryProvider($this->db))->findBySlug($slug);

        self::assertNotNull($row, "no product published at /products/{$slug}");

        return (new ProductResource())->toArray($row);
    }

    /** @return list<array<string,mixed>> */
    private function publishedVariations(int $parentSourceId): array
    {
        $page = (new VariationQueryProvider($this->db))->list(new VariationFilterSet($parentSourceId));

        return (new VariationResource())->toCollection($page->rows, $page->nextCursor)['data'];
    }

    private function event(string $type, string $aggregateType, int $id, int $version): EventInterface
    {
        return new WooHandoffIdentityEvent($type, $aggregateType, (string) $id, $version, "{$type}-{$id}-{$version}");
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

/** Minimal event double — the adapters only read identity, version and timestamps. */
final class WooHandoffIdentityEvent implements EventInterface
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly int $version,
        private readonly string $eventKey,
    ) {
    }

    public function getId(): string
    {
        return sprintf(
            '%s-0000-7000-8000-%012d',
            substr(md5($this->eventKey), 0, 8),
            crc32($this->eventKey) % 999999,
        );
    }

    public function getEventType(): string { return $this->eventType; }
    public function getEventVersion(): int { return 1; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getAggregateVersion(): int { return $this->version; }
    /** @return array<string,mixed> */
    public function getPayload(): array { return []; }
    public function getChecksum(): string { return str_repeat('f', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000009'; }
    public function getCausationId(): ?string { return null; }
}
