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
 *   2. Whether the variation's stored REFERENCE can change because its PARENT changed. Under
 *      WooCommerce's `view` context it could: `get_image_id()` falls back to the parent's image
 *      when the variation has none of its own, so a parent edit silently moved a value stored on
 *      the variation aggregate — and a parent image edit emits no variation event, so the
 *      projection went stale (verified live: `commerce.product.updated` +
 *      `commerce.inventory.updated`, zero variation events).
 *
 * FLAG-COMMVARIMG-1 resolved that by removing the dependency rather than compensating for it:
 * the loader captures `get_image_id('edit')`, the variation's own explicit assignment, so there
 * is no inherited value left to go stale. The tests below are the invariant, not a workaround —
 * no fan-out, no cross-aggregate write, no reconciliation dependency appears anywhere.
 *
 * WooCommerce's display fallback remains valid and reproducible by a consumer, from the public
 * contract alone: `variation.media.featured ?? product.media.featured`.
 *
 * Self-skips without PostgreSQL, per the suite convention.
 */
final class VariationMediaConvergenceIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private ExplicitImageCommerceLoader $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new ExplicitImageCommerceLoader();

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
            // The variation's OWN explicit image; 0 means it has none. Kept separate from the
            // parent's so a test can move either one independently.
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
     * A variation with no image of its own publishes NOTHING, even when its parent has one.
     *
     * This is the ruling (FLAG-COMMVARIMG-1, Option 1). WooCommerce's `view` context would
     * answer 122 here — the parent's featured image — and that answer is correct for DISPLAY and
     * wrong to persist: it is derived from two aggregates. The loader passes `'edit'`, so what
     * reaches this projection is the variation's own explicit assignment and nothing else.
     */
    public function test_a_variation_without_its_own_image_does_not_inherit_the_parents(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 0);
        $this->seedMedia(122);

        $this->handler()->handle($this->event(118, 1));

        $media = $this->publishedVariation(95, 118)['media'];

        self::assertSame(0, $media['featured_id'], "the parent's image is not variation state");
        self::assertNull($media['featured']);
    }

    /**
     * THE INVARIANT the ruling buys. The parent's featured image changes; the variation is never
     * edited; the variation's state does not move at all.
     *
     * This replaces the regression that used to prove the opposite. Under the old `view`
     * semantics the variation stored the parent's image, so a parent edit silently changed a
     * value on this aggregate — and, verified live, a parent image change emits
     * `commerce.product.updated` + `commerce.inventory.updated` and ZERO variation events, so
     * the projection simply went stale. Storing only the variation's own assignment removes the
     * dependency rather than compensating for it: there is no inherited value left to go stale,
     * and so no fan-out, no cross-aggregate write and no reconciliation dependency is needed.
     *
     * `checksum`, `synced_at` and `updated_at` are asserted byte-identical: the variation is not
     * merely serving the same image, it was not touched.
     */
    public function test_a_parent_image_change_leaves_the_variation_untouched(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 0);
        $this->seedMedia(122);
        $this->seedMedia(125);

        $this->handler()->handle($this->event(118, 1));

        $rowBefore = $this->variationRow(118);
        self::assertSame(0, (int) $rowBefore['featured_media_id']);

        // Only the parent's image changes; the variation is NOT edited.
        $this->loader->products[95]['featured_media_id'] = 125;

        // The variation's own source fact has not moved, because it never depended on the parent.
        self::assertSame(
            0,
            (int) $this->loader->loadVariation(118)['featured_media_id'],
            'the variation aggregate owns its image outright',
        );

        // Even re-emitting the variation changes nothing — there is no inherited value to drift.
        $this->handler()->handle($this->event(118, 2));

        $rowAfter = $this->variationRow(118);
        self::assertSame($rowBefore, $rowAfter, 'no parent dependency, so nothing to converge');

        $media = $this->publishedVariation(95, 118)['media'];
        self::assertSame(0, $media['featured_id']);
        self::assertNull($media['featured']);
    }

    /** An explicit variation image is unaffected by the parent's image changing. */
    public function test_an_explicit_variation_image_is_unaffected_by_parent_image_changes(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 125);
        $this->seedMedia(122);
        $this->seedMedia(125);
        $this->seedMedia(126);

        $this->handler()->handle($this->event(118, 1));
        $rowBefore = $this->variationRow(118);

        $this->loader->products[95]['featured_media_id'] = 126;
        $this->handler()->handle($this->event(118, 2));

        self::assertSame($rowBefore, $this->variationRow(118));
        self::assertSame(125, $this->publishedVariation(95, 118)['media']['featured_id']);
    }

    // =========================================================================
    // 3. Checksum — the variation's own image still drives its state
    // =========================================================================

    /** Swapping the variation's explicit image moves its checksum and its projection. */
    public function test_changing_the_explicit_variation_image_converges_normally(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 125);
        $this->seedMedia(125);
        $this->seedMedia(126);

        $this->handler()->handle($this->event(118, 1));
        $before = $this->variationRow(118);

        $this->loader->variations[118]['own_image_id'] = 126;
        $this->handler()->handle($this->event(118, 2));

        $after = $this->variationRow(118);

        self::assertNotSame($before['checksum'], $after['checksum'], '125 -> 126 must move the checksum');
        self::assertSame(126, (int) $after['featured_media_id']);
        self::assertSame(126, $this->publishedVariation(95, 118)['media']['featured_id']);
    }

    /** Clearing the variation's explicit image converges to 0 / null, not to the parent's. */
    public function test_clearing_the_explicit_variation_image_converges_to_zero(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 125);
        $this->seedMedia(122);
        $this->seedMedia(125);

        $this->handler()->handle($this->event(118, 1));
        $before = $this->variationRow(118);

        $this->loader->variations[118]['own_image_id'] = 0;
        $this->handler()->handle($this->event(118, 2));

        $after = $this->variationRow(118);

        self::assertNotSame($before['checksum'], $after['checksum']);
        self::assertSame(0, (int) $after['featured_media_id']);

        $media = $this->publishedVariation(95, 118)['media'];
        self::assertSame(0, $media['featured_id']);
        self::assertNull($media['featured'], 'cleared means none — not the parent image');
    }

    // =========================================================================
    // 4. Historical rows repair through the ORDINARY pipeline
    // =========================================================================

    /**
     * A row projected under the OLD `view` semantics carries the parent's inherited image. It
     * repairs to 0 through ordinary re-emission — the DECISION T/U path reconciliation already
     * drives — with no repair SQL, no migration UPDATE and no repair worker.
     *
     * The historical row is produced by RUNNING the old semantic, not by patching the column:
     * the fixture first reports the inherited parent image as the variation's image — which is
     * exactly what the view-context loader used to return — so the row lands with 122 AND a
     * checksum computed over 122, the way a real pre-ruling projection did.
     *
     * That distinction is load-bearing. Patching only the column leaves a row whose checksum
     * says 0 while its column says 122; re-emission then recomputes 0, matches the stored
     * checksum, and DECISION 3 correctly suppresses the write — a state no pipeline ever
     * produces, failing for a reason that has nothing to do with the repair.
     */
    public function test_a_historical_inherited_row_repairs_to_zero_through_ordinary_re_emission(): void
    {
        $this->givenCatalog(parentId: 95, parentImage: 122, variationId: 118, ownImage: 0);
        $this->seedMedia(122);

        // The OLD view-context loader: no explicit image, so it returned the parent's.
        $this->loader->variations[118]['own_image_id'] = 122;
        $this->handler()->handle($this->event(118, 1));

        self::assertSame(122, (int) $this->variationRow(118)['featured_media_id']);

        // The corrected loader reads the variation's own assignment — which is none.
        $this->loader->variations[118]['own_image_id'] = 0;

        self::assertSame(
            122,
            $this->publishedVariation(95, 118)['media']['featured_id'],
            'precondition: the historical row serves the inherited parent image',
        );

        // Ordinary re-emission — exactly what replay (DECISION T) and reconciliation
        // (DECISION U) put through the queue. Nothing bespoke.
        $this->handler()->handle($this->event(118, 2));

        self::assertSame(0, (int) $this->variationRow(118)['featured_media_id']);

        $media = $this->publishedVariation(95, 118)['media'];
        self::assertSame(0, $media['featured_id']);
        self::assertNull($media['featured']);
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
 * A loader that reproduces `WC_Product_Variation::get_image_id('edit')` — the variation's OWN
 * explicit image, 0 when it has none.
 *
 * The fixture holds the variation's own assignment as `own_image_id` and the parent's featured
 * image separately, so a test can move either one independently. That separation is the point:
 * a fixture that stored a single flat id could not express "the parent changed and the variation
 * did not", which is the case this file exists to pin.
 *
 * It deliberately does NOT reproduce the view-context fallback. Modelling a source the loader no
 * longer reads would let a test pass against semantics production abandoned.
 */
final class ExplicitImageCommerceLoader extends InMemoryCommerceLoader
{
    /** @return array<string,mixed>|null */
    public function loadVariation(int $variationId): ?array
    {
        $variation = parent::loadVariation($variationId);

        if ($variation === null) {
            return null;
        }

        $variation['featured_media_id'] = (int) ($variation['own_image_id'] ?? 0);

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
