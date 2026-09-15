<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\VariationAdapter;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;
use HSP\Modules\Commerce\Queries\VariationFilterSet;
use HSP\Modules\Commerce\Queries\VariationQueryProvider;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\VariationValidator;
use HSP\Modules\Content\Queries\MediaReferenceProvider;
use HSP\Tests\Support\CommerceSchema;
use HSP\Tests\Support\ContentSchema;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\TestCase;

/**
 * Variation media: metadata independence, and the CONVERGENCE of WooCommerce's inherited image.
 *
 * Two different questions live here, and conflating them is how the second one stays invisible:
 *
 *   1. The attachment's METADATA changes (alt text, URL, dimensions) while the reference stays
 *      the same id. This must reach the variation's response with no variation rewrite — the
 *      Product equivalent is already proven, and this is its variation twin.
 *
 *   2. The variation's effective REFERENCE changes because its PARENT's image changed, while
 *      the variation itself was never edited. That is only possible because
 *      `WC_Product_Variation::get_image_id()` resolves to the parent's image in `view` context
 *      when the variation has none of its own — so a parent edit silently moves a value stored
 *      on the variation aggregate.
 *
 * The second is the reason this file exists. It is a CAPTURE question, not a delivery one, and
 * the test below records the answer rather than asserting a preferred one: see FLAG-COMMVARIMG-1.
 *
 * Self-skips without PostgreSQL, per the suite convention.
 */
final class VariationMediaConvergenceIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private ViewResolvingCommerceLoader $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new ViewResolvingCommerceLoader();

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

    // -------------------------------------------------------------------------
    // Harness
    // -------------------------------------------------------------------------

    private function handler(): VariationUpsertHandler
    {
        return new VariationUpsertHandler(
            $this->loader,
            new VariationExtractor(new VariationValidator()),
            new VariationTransformer(),
            new VariationAdapter($this->db),
        );
    }

    private function event(int $id, int $version): EventInterface
    {
        return new VariationMediaEvent(
            'commerce.product_variation.updated',
            'product_variation',
            (string) $id,
            $version,
            "v{$id}-{$version}",
        );
    }

    /** A variable parent and one variation, the variation's own image optional. */
    private function givenCatalog(int $parentId, int $parentImage, int $variationId, int $ownImage): void
    {
        $this->loader->products[$parentId] = [
            'id'                 => $parentId,
            'sku'                => 'PARENT',
            'slug'               => 'parent-' . $parentId,
            'name'               => 'Parent',
            'description'        => '',
            'short_description'  => '',
            'status'             => 'publish',
            'product_type'       => 'variable',
            'catalog_visibility' => 'visible',
            'featured'           => false,
            'price'              => '20.00',
            'regular_price'      => '20.00',
            'sale_price'         => null,
            'featured_media_id'  => $parentImage,
            'gallery_media_ids'  => [],
            'published_at'       => '2026-01-01 00:00:00',
            'modified_at'        => '2026-01-01 00:00:00',
            'meta'               => [],
            'category_ids'       => [],
            'attribute_term_ids' => [],
            'variation_selection_supported' => true,
        ];

        $this->loader->variations[$variationId] = [
            'id'                 => $variationId,
            'parent_id'          => $parentId,
            'sku'                => 'VAR',
            'name'               => 'Parent - Blue',
            'description'        => '',
            'status'             => 'publish',
            'price'              => '20.00',
            'regular_price'      => '20.00',
            'sale_price'         => null,
            // The variation's OWN image; 0 means it has none and Woo falls back in view context.
            'own_image_id'       => $ownImage,
            'menu_order'         => 0,
            'attributes'         => ['pa_color' => 'blue'],
            'attribute_term_ids' => [],
            'modified_at'        => '2026-01-01 00:00:00',
        ];
    }

    private function seedMedia(int $sourceId, string $altText = 'original alt', int $width = 800): void
    {
        $this->db->execute(
            'INSERT INTO content.media
                (id, source_post_id, slug, title, mime_type, url, alt_text, caption, description,
                 width, height, sizes_jsonb, attached_to_id, published_at, updated_at, checksum,
                 meta_jsonb, created_at, synced_at)
             VALUES (gen_random_uuid(), $1, $2, $3, $4, $5, $6, \'\', \'\', $7, 600,
                     \'{}\'::jsonb, 0, now(), now(), $8, \'{}\'::jsonb, now(), now())',
            [
                $sourceId,
                'image-' . $sourceId,
                'Image ' . $sourceId,
                'image/jpeg',
                'https://example.test/uploads/' . $sourceId . '.jpg',
                $altText,
                $width,
                str_repeat('b', 64),
            ],
        );
    }

    /** @return array<string,mixed> */
    private function publishedVariation(int $parentId, int $variationId): array
    {
        $provider = new VariationQueryProvider($this->db, new MediaReferenceProvider($this->db));
        $rows     = $provider->list(new VariationFilterSet(parentSourceId: $parentId))->rows;

        foreach ($rows as $row) {
            if ((int) $row['source_variation_id'] === $variationId) {
                return (new VariationResource())->toArray($row);
            }
        }

        self::fail("variation {$variationId} was not served");
    }

    /** @return array<string,mixed> */
    private function variationRow(int $variationId): array
    {
        $rows = $this->db->query(
            'SELECT checksum, synced_at, updated_at, featured_media_id
               FROM commerce.product_variations WHERE source_variation_id = $1',
            [$variationId],
        );

        self::assertNotSame([], $rows, "variation {$variationId} is not projected");

        return $rows[0];
    }

    // =========================================================================
    // 1. Metadata independence — the Product property, proven for variations
    // =========================================================================

    /**
     * Editing the ATTACHMENT changes the variation's response and leaves the variation row alone.
     *
     * Same ownership rule the product already proves: `content.media` owns attachment metadata,
     * Commerce owns only the reference, and the two converge independently. A variation image
     * re-cropped in WordPress must not require every variation referencing it to be re-saved.
     */
    public function test_editing_the_attachment_changes_the_variation_response_but_not_its_row(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 125);
        $this->seedMedia(125, 'original alt');

        $this->handler()->handle($this->event(118, 1));

        $before    = $this->publishedVariation(95, 118)['media'];
        $rowBefore = $this->variationRow(118);

        self::assertSame(125, $before['featured_id']);
        self::assertSame('original alt', $before['featured']['alt_text']);

        // A WordPress image edit, as the Content pipeline would project it. The REFERENCE is
        // untouched — only the attachment behind it changes.
        $this->db->execute(
            "UPDATE content.media
                SET alt_text = 'a better description',
                    url = 'https://example.test/uploads/125-scaled.jpg',
                    width = 1600,
                    updated_at = now(),
                    synced_at = now()
              WHERE source_post_id = 125"
        );

        $after    = $this->publishedVariation(95, 118)['media'];
        $rowAfter = $this->variationRow(118);

        self::assertSame('a better description', $after['featured']['alt_text']);
        self::assertSame('https://example.test/uploads/125-scaled.jpg', $after['featured']['url']);
        self::assertSame(1600, $after['featured']['width']);

        self::assertSame($rowBefore, $rowAfter, 'attachment metadata is not variation state');
    }

    /** No image anywhere — neither the variation's nor the parent's — publishes null. */
    public function test_a_variation_with_no_image_and_no_parent_image_publishes_null(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 0, variationId: 118, ownImage: 0);

        $this->handler()->handle($this->event(118, 1));

        $media = $this->publishedVariation(95, 118)['media'];

        self::assertSame(0, $media['featured_id']);
        self::assertNull($media['featured']);
    }

    /** An explicit variation image is never displaced by the parent's. */
    public function test_an_explicit_variation_image_is_not_displaced_by_the_parent(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 125);
        $this->seedMedia(122);
        $this->seedMedia(125);

        $this->handler()->handle($this->event(118, 1));

        $media = $this->publishedVariation(95, 118)['media'];

        self::assertSame(125, $media['featured_id']);
        self::assertSame('https://example.test/uploads/125.jpg', $media['featured']['url']);
    }

    // =========================================================================
    // 2. THE CONVERGENCE QUESTION — WooCommerce's inherited variation image
    // =========================================================================

    /**
     * A variation with no image of its own inherits the parent's, exactly as
     * `WC_Product_Variation::get_image_id()` does in `view` context.
     *
     * Verified against WooCommerce 11.1.0 on the live store (read-only, in-memory): clearing a
     * variation's image gives `get_image_id('edit') === 0` and `get_image_id('view') === 122`,
     * the parent's featured image. `WpCommerceLoaderImpl` calls `get_image_id()` with the
     * default context, so it is the VIEW value that HSP captures and stores.
     */
    public function test_a_variation_without_its_own_image_captures_the_parents(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 0);
        $this->seedMedia(122);

        $this->handler()->handle($this->event(118, 1));

        $media = $this->publishedVariation(95, 118)['media'];

        self::assertSame(122, $media['featured_id'], "Woo's view-context fallback is what HSP stores");
        self::assertSame('https://example.test/uploads/122.jpg', $media['featured']['url']);
    }

    /**
     * THE DECIDING TEST. The parent's featured image changes; the variation is never edited.
     *
     * Its effective source value has moved — `get_image_id('view')` now answers 125 where it
     * answered 122 — but the value lives on the VARIATION aggregate, and nothing emits a
     * variation event. Verified live against WooCommerce 11.1.0: changing product 95's featured
     * image emitted `commerce.product.updated` + `commerce.inventory.updated` and **zero**
     * `commerce.product_variation.*` events.
     *
     * So the variation projection keeps the old inherited image. This test PINS that fact rather
     * than endorsing it — it is the evidence behind FLAG-COMMVARIMG-1, and it will need updating
     * if the architecture ruling changes the capture or the stored semantic.
     */
    public function test_a_parent_image_change_does_not_converge_an_inherited_variation_image(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 0);
        $this->seedMedia(122);
        $this->seedMedia(125);

        $this->handler()->handle($this->event(118, 1));
        self::assertSame(122, (int) $this->variationRow(118)['featured_media_id']);

        // Only the parent's image changes. The variation is NOT edited, so WooCommerce emits no
        // variation hook and HSP writes no variation outbox row.
        $this->loader->products[95]['featured_media_id'] = 125;

        // Woo's effective answer for the variation has moved with it.
        self::assertSame(
            125,
            (int) $this->loader->loadVariation(118)['featured_media_id'],
            'the source value this aggregate stores has changed without the aggregate being edited',
        );

        // But no variation event exists to carry it, so the projection is unchanged...
        $stale = $this->publishedVariation(95, 118)['media'];
        self::assertSame(122, $stale['featured_id'], 'STALE: the projection still serves the old image');
        self::assertSame('https://example.test/uploads/122.jpg', $stale['featured']['url']);

        // ...and it stays that way until something re-emits the variation. Reconciliation in a
        // checksum mode is what eventually does: recomputing the canonical checksum from live
        // WordPress state sees the drift, which is precisely why this is a CAPTURE gap and not a
        // projection bug — the repair path already works, nothing tells it to run in time.
        $this->handler()->handle($this->event(118, 2));

        $repaired = $this->publishedVariation(95, 118)['media'];
        self::assertSame(125, $repaired['featured_id'], 're-emission converges it');
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

/**
 * A loader that reproduces `WC_Product_Variation::get_image_id()` in VIEW context.
 *
 * The fixture stores a variation's own image as `own_image_id` and resolves `featured_media_id`
 * the way WooCommerce does — own image when set, otherwise the parent product's featured image.
 * Without this, a test loader returning a flat stored id would model a source HSP does not read
 * and the convergence question could never surface.
 */
final class ViewResolvingCommerceLoader extends InMemoryCommerceLoader
{
    /** @return array<string,mixed>|null */
    public function loadVariation(int $variationId): ?array
    {
        $variation = parent::loadVariation($variationId);

        if ($variation === null) {
            return null;
        }

        $own = (int) ($variation['own_image_id'] ?? 0);

        $variation['featured_media_id'] = $own > 0
            ? $own
            : (int) ($this->products[(int) $variation['parent_id']]['featured_media_id'] ?? 0);

        return $variation;
    }
}

/** Event double for the variation handler — same shape VariationIntegrationTest uses. */
final class VariationMediaEvent implements EventInterface
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
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000004'; }
    public function getCausationId(): ?string { return null; }
}
