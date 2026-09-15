<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\MediaReferenceProviderInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalProduct;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Queries\VariationFilterSet;
use HSP\Modules\Commerce\Queries\VariationQueryProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use PHPUnit\Framework\TestCase;

/**
 * Finding 011 — product media resolved through the AG-10 Core capability.
 *
 * The defect these tests close: a Product response published `media.featured_id: 123` and
 * nothing a contract-only frontend could turn into an image. Resolving it correctly means four
 * things at once, and each is easy to get wrong on its own — the gallery keeps the store's
 * ORDER, the whole page resolves in ONE call, the Commerce side never names Content or its
 * table, and an absent capability degrades instead of failing.
 *
 * The capability is faked here rather than stubbed through the container: what matters on the
 * Commerce side is the CONTRACT (bulk in, map out) and how many times it is called. Content's
 * own implementation is proven in MediaReferenceProviderTest.
 */
final class ProductMediaResolutionTest extends TestCase
{
    /** @return array<string,mixed> */
    private function media(int $id): array
    {
        return [
            'slug'      => 'image-' . $id,
            'url'       => 'https://example.test/' . $id . '.jpg',
            'alt_text'  => 'alt ' . $id,
            'mime_type' => 'image/jpeg',
            'width'     => 800,
            'height'    => 600,
            'sizes'     => [],
        ];
    }

    /**
     * A capability that resolves the ids it is told about and counts how often it was asked.
     *
     * @param list<int> $available
     */
    private function capability(array $available): MediaReferenceProviderInterface
    {
        return new class ($available, $this->media(...)) implements MediaReferenceProviderInterface {
            public int $calls = 0;

            /** @var list<int> */
            public array $askedFor = [];

            /** @param list<int> $available */
            public function __construct(
                private readonly array $available,
                private readonly \Closure $shape,
            ) {
            }

            /**
             * @param  list<int> $sourceAttachmentIds
             * @return array<int, array<string,mixed>>
             */
            public function resolveMany(array $sourceAttachmentIds): array
            {
                $this->calls++;
                $this->askedFor = array_merge($this->askedFor, $sourceAttachmentIds);

                $out = [];
                foreach ($sourceAttachmentIds as $id) {
                    if (in_array($id, $this->available, true)) {
                        $out[$id] = ($this->shape)($id);
                    }
                }

                return $out;
            }
        };
    }

    /**
     * @param  list<int> $gallery
     * @return array<string,mixed>
     */
    private function productRow(int $sourceId, int $featured, array $gallery, string $slug = 'p'): array
    {
        return [
            'id'                => 'uuid-' . $sourceId,
            'source_product_id' => $sourceId,
            'slug'              => $slug,
            'name'              => 'Product ' . $sourceId,
            'product_type'      => 'simple',
            'featured_media_id' => $featured,
            'gallery_media_ids' => json_encode($gallery),
        ];
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    public function testAFeaturedImageResolvesToADirectlyUsableObject(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 101, [])]);

        $provider = new ProductQueryProvider($db, $this->capability([101]));
        $row      = $provider->list(new ProductFilterSet())->rows[0];

        $media = (new ProductResource())->toArray($row)['media'];

        self::assertSame('https://example.test/101.jpg', $media['featured']['url']);
        self::assertSame('alt 101', $media['featured']['alt_text']);
        self::assertSame(800, $media['featured']['width']);
        self::assertSame(600, $media['featured']['height']);
        self::assertSame([], $media['gallery']);
    }

    /**
     * ORDER IS THE STORE'S. The database is free to return [124, 125, 126] for a gallery stored
     * as [124, 126, 125]; publishing the rows in database order would silently reorder every
     * product carousel on the site. The capability is keyed by attachment id precisely so the
     * caller can walk its own sequence.
     */
    public function testTheGalleryPublishesInStoredOrderNotDatabaseOrder(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 0, [124, 126, 125])]);

        $provider = new ProductQueryProvider($db, $this->capability([124, 125, 126]));
        $row      = $provider->list(new ProductFilterSet())->rows[0];

        $gallery = (new ProductResource())->toArray($row)['media']['gallery'];

        self::assertSame(
            ['https://example.test/124.jpg', 'https://example.test/126.jpg', 'https://example.test/125.jpg'],
            array_column($gallery, 'url'),
        );
    }

    /** No media at all: null and an empty list, never null-vs-missing ambiguity. */
    public function testAProductWithNoMediaPublishesNullAndAnEmptyGallery(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 0, [])]);

        $provider = new ProductQueryProvider($db, $this->capability([]));
        $media    = (new ProductResource())->toArray($provider->list(new ProductFilterSet())->rows[0])['media'];

        self::assertNull($media['featured']);
        self::assertSame([], $media['gallery']);
        self::assertSame(0, $media['featured_id']);
        self::assertSame([], $media['gallery_ids']);
    }

    /**
     * Gallery references only, with no featured image. WooCommerce permits it, and the two
     * fields resolve independently.
     */
    public function testAGalleryResolvesWithNoFeaturedImage(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 0, [201, 202])]);

        $provider = new ProductQueryProvider($db, $this->capability([201, 202]));
        $media    = (new ProductResource())->toArray($provider->list(new ProductFilterSet())->rows[0])['media'];

        self::assertNull($media['featured']);
        self::assertCount(2, $media['gallery']);
    }

    /**
     * A featured reference that resolves to nothing — never projected, or tombstoned — publishes
     * null. The product stays valid, the stored reference is untouched, and nothing 500s.
     */
    public function testAnUnresolvableFeaturedReferencePublishesNullAndKeepsTheReference(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 999, [])]);

        $provider = new ProductQueryProvider($db, $this->capability([]));
        $media    = (new ProductResource())->toArray($provider->list(new ProductFilterSet())->rows[0])['media'];

        self::assertNull($media['featured']);
        self::assertSame(999, $media['featured_id'], 'the stored reference must survive');
    }

    /**
     * A partly-available gallery: the resolvable images are published in their relative order
     * and the missing one is OMITTED — not a null hole a consumer has to skip, and not a
     * fabricated placeholder object.
     */
    public function testAPartiallyAvailableGalleryOmitsWhatCannotResolveAndKeepsOrder(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 0, [101, 102, 103])]);

        $provider = new ProductQueryProvider($db, $this->capability([101, 103]));
        $media    = (new ProductResource())->toArray($provider->list(new ProductFilterSet())->rows[0])['media'];

        self::assertSame(
            ['https://example.test/101.jpg', 'https://example.test/103.jpg'],
            array_column($media['gallery'], 'url'),
        );
        self::assertSame([101, 102, 103], $media['gallery_ids'], 'references are not rewritten');
    }

    /**
     * WooCommerce does not forbid the featured image also appearing in the gallery, so HSP does
     * not remove it. Reproducing the source relationship is the rule; deciding not to show the
     * same image twice is a presentation choice.
     */
    public function testAFeaturedImageThatAlsoAppearsInTheGalleryIsNotRemoved(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 101, [101, 102])]);

        $provider = new ProductQueryProvider($db, $this->capability([101, 102]));
        $media    = (new ProductResource())->toArray($provider->list(new ProductFilterSet())->rows[0])['media'];

        self::assertSame('https://example.test/101.jpg', $media['featured']['url']);
        self::assertCount(2, $media['gallery']);
        self::assertSame('https://example.test/101.jpg', $media['gallery'][0]['url']);
    }

    // -------------------------------------------------------------------------
    // Capability absence (AG-10 independence clause)
    // -------------------------------------------------------------------------

    /**
     * No capability registered — the Content module is not active. Products still serve, the
     * references are still published, and the resolved fields carry the safe answer. Nothing
     * probes for a Content class and nothing fatals.
     */
    public function testWithNoMediaCapabilityProductsStillServeWithSafeMediaValues(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 101, [102, 103])]);

        $provider = new ProductQueryProvider($db);
        $out      = (new ProductResource())->toArray($provider->list(new ProductFilterSet())->rows[0]);

        self::assertNull($out['media']['featured']);
        self::assertSame([], $out['media']['gallery']);
        self::assertSame(101, $out['media']['featured_id']);
        self::assertSame([102, 103], $out['media']['gallery_ids']);
        self::assertSame('Product 1', $out['name'], 'the rest of the product is unaffected');
    }

    /** Absence must not add a query either — no fallback SQL path exists. */
    public function testWithNoMediaCapabilityNoExtraQueryIsIssued(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 101, [102])]);

        (new ProductQueryProvider($db))->list(new ProductFilterSet());

        self::assertCount(1, array_filter(
            $db->log,
            static fn (array $e): bool => $e['method'] === 'query',
        ));
    }

    /**
     * The capability appearing later needs NO product re-emission: the same stored references
     * resolve the moment something implements the contract. This is why references are stored
     * and metadata is not.
     */
    public function testTheSameStoredReferencesResolveOnceACapabilityIsAvailable(): void
    {
        $row = $this->productRow(1, 101, [102]);

        $without = new FakeDbConnection();
        $without->willReturnRows([$row]);
        $before = (new ProductResource())->toArray(
            (new ProductQueryProvider($without))->list(new ProductFilterSet())->rows[0]
        );

        $with = new FakeDbConnection();
        $with->willReturnRows([$row]);
        $after = (new ProductResource())->toArray(
            (new ProductQueryProvider($with, $this->capability([101, 102])))->list(new ProductFilterSet())->rows[0]
        );

        self::assertNull($before['media']['featured']);
        self::assertNotNull($after['media']['featured']);
        self::assertSame($before['media']['featured_id'], $after['media']['featured_id']);
        self::assertSame($before['media']['gallery_ids'], $after['media']['gallery_ids']);
    }

    // -------------------------------------------------------------------------
    // No N+1 (AG-10, and the Phase 2 performance DoD)
    // -------------------------------------------------------------------------

    /**
     * ONE capability call for the whole page, and one extra database query — for a one-row page
     * and for a full page alike.
     *
     * One call PER PRODUCT would also be wrong even though each such call batches that product's
     * own gallery: twenty products would be twenty calls and twenty queries. The references are
     * collected across the response first.
     */
    public function testResolutionCostIsIndependentOfProductAndGalleryCount(): void
    {
        $measure = function (int $products, int $gallerySize): array {
            $rows = [];
            for ($i = 1; $i <= $products; $i++) {
                $base    = $i * 100;
                $gallery = range($base + 1, $base + $gallerySize);
                $rows[]  = $this->productRow($i, $base, $gallery, 'p' . $i);
            }

            $db = new FakeDbConnection();
            $db->willReturnRows($rows);

            $capability = $this->capability([]);
            (new ProductQueryProvider($db, $capability))->list(new ProductFilterSet());

            return [
                'calls'   => $capability->calls,
                'queries' => count(array_filter(
                    $db->log,
                    static fn (array $e): bool => $e['method'] === 'query',
                )),
            ];
        };

        $one   = $measure(1, 1);
        $page  = $measure(20, 1);
        $large = $measure(20, 10);

        self::assertSame(1, $one['calls']);
        self::assertSame($one['calls'], $page['calls'], 'one provider call per PAGE, not per product');
        self::assertSame($one['calls'], $large['calls'], 'gallery size must not add calls');

        // The Commerce side issues exactly one query whatever the page holds; the capability's
        // own cost is one query per call, proven in MediaReferenceProviderTest. One call and one
        // query each side is therefore the whole bill for a page of any size.
        self::assertSame(1, $one['queries']);
        self::assertSame($one['queries'], $page['queries']);
        self::assertSame($one['queries'], $large['queries']);
    }

    /** Every reference on the page reaches the single call — nothing is quietly left unresolved. */
    public function testEveryReferenceOnThePageIsIncludedInTheSingleCall(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([
            $this->productRow(1, 101, [102, 103], 'a'),
            $this->productRow(2, 201, [202], 'b'),
        ]);

        $capability = $this->capability([]);
        (new ProductQueryProvider($db, $capability))->list(new ProductFilterSet());

        self::assertSame([101, 102, 103, 201, 202], $capability->askedFor);
    }

    // -------------------------------------------------------------------------
    // List / detail parity
    // -------------------------------------------------------------------------

    public function testTheSingleProductLookupResolvesMediaTheSameWayTheListingDoes(): void
    {
        $row = $this->productRow(1, 101, [102, 103]);

        $listDb = new FakeDbConnection();
        $listDb->willReturnRows([$row]);
        $list = (new ProductResource())->toArray(
            (new ProductQueryProvider($listDb, $this->capability([101, 102, 103])))
                ->list(new ProductFilterSet())->rows[0]
        );

        $detailDb = new FakeDbConnection();
        $detailDb->willReturnRows([$row]);
        $detail = (new ProductResource())->toArray(
            (new ProductQueryProvider($detailDb, $this->capability([101, 102, 103])))->findBySlug('p') ?? []
        );

        self::assertSame($list['media'], $detail['media']);
    }

    /** A missing product is still null — the hydration step must not turn it into a row. */
    public function testAMissingProductStillReturnsNull(): void
    {
        $db = new FakeDbConnection();
        $db->willReturnRows([]);

        self::assertNull(
            (new ProductQueryProvider($db, $this->capability([])))->findBySlug('nope')
        );
    }

    // -------------------------------------------------------------------------
    // Variations
    // -------------------------------------------------------------------------

    public function testAVariationImageResolvesAndFallsBackToNullWhenUnavailable(): void
    {
        $row = [
            'id'                  => 'v-uuid',
            'source_variation_id' => 118,
            'source_parent_id'    => 95,
            'name'                => 'Hoodie - Blue',
            'featured_media_id'   => 125,
            'attributes'          => '{"pa_color":"blue"}',
            'menu_order'          => 0,
        ];

        $db = new FakeDbConnection();
        $db->willReturnRows([$row]);
        $resolved = (new VariationResource())->toArray(
            (new VariationQueryProvider($db, $this->capability([125])))
                ->list(new VariationFilterSet(parentSourceId: 95))->rows[0]
        );

        self::assertSame('https://example.test/125.jpg', $resolved['media']['featured']['url']);
        self::assertSame(125, $resolved['media']['featured_id']);

        $bare = new FakeDbConnection();
        $bare->willReturnRows([$row]);
        $unresolved = (new VariationResource())->toArray(
            (new VariationQueryProvider($bare))->list(new VariationFilterSet(parentSourceId: 95))->rows[0]
        );

        self::assertNull($unresolved['media']['featured']);
        self::assertSame(125, $unresolved['media']['featured_id']);
    }

    public function testVariationResolutionIsAlsoOneCallForThePage(): void
    {
        $rows = [];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = [
                'id'                  => 'v' . $i,
                'source_variation_id' => 100 + $i,
                'source_parent_id'    => 95,
                'featured_media_id'   => 200 + $i,
                'menu_order'          => $i,
            ];
        }

        $db = new FakeDbConnection();
        $db->willReturnRows($rows);

        $capability = $this->capability([]);
        (new VariationQueryProvider($db, $capability))->list(new VariationFilterSet(parentSourceId: 95));

        self::assertSame(1, $capability->calls);
    }

    // -------------------------------------------------------------------------
    // Product state: relationship changes DO move the checksum
    // -------------------------------------------------------------------------

    /**
     * @param list<int> $gallery
     */
    private function canonical(int $featured, array $gallery): CanonicalProduct
    {
        return new CanonicalProduct(
            sourceProductId: 1,
            sku: 'SKU',
            slug: 'p',
            name: 'Product',
            description: '',
            shortDescription: '',
            status: 'publish',
            productType: 'simple',
            catalogVisibility: 'visible',
            featured: false,
            price: '10.00',
            regularPrice: '10.00',
            salePrice: null,
            featuredMediaId: $featured,
            galleryMediaIds: $gallery,
            publishedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            meta: [],
            categoryIds: [],
            attributeTermIds: [],
            variationSelectionSupported: null,
        );
    }

    /**
     * Changing ONLY the featured image must move the checksum. If it did not, write suppression
     * (DECISION 3) would compare identical checksums and silently discard a real change — the
     * product would keep showing its old image for ever.
     */
    public function testChangingOnlyTheFeaturedImageChangesTheProductChecksum(): void
    {
        self::assertNotSame(
            $this->canonical(101, [])->getChecksum(),
            $this->canonical(102, [])->getChecksum(),
        );
    }

    public function testAddingAndRemovingGalleryImagesChangesTheChecksum(): void
    {
        $base = $this->canonical(101, [103, 104])->getChecksum();

        self::assertNotSame($base, $this->canonical(101, [103, 104, 105])->getChecksum(), 'add');
        self::assertNotSame($base, $this->canonical(101, [103])->getChecksum(), 'remove');
        self::assertNotSame($base, $this->canonical(101, [104, 105])->getChecksum(), 'membership swap');
    }

    /**
     * REORDERING ALONE must move the checksum. The gallery is a sequence, not a set: a store
     * owner who drags the third image to the front changed the product, and a set-normalising
     * digest would suppress the write and leave the old order published.
     */
    public function testReorderingTheGalleryAloneChangesTheChecksum(): void
    {
        self::assertNotSame(
            $this->canonical(101, [101, 102, 103])->getChecksum(),
            $this->canonical(101, [103, 102, 101])->getChecksum(),
        );
    }

    public function testClearingTheFeaturedImageOrTheGalleryChangesTheChecksum(): void
    {
        $base = $this->canonical(101, [103, 104])->getChecksum();

        self::assertNotSame($base, $this->canonical(0, [103, 104])->getChecksum(), 'featured cleared');
        self::assertNotSame($base, $this->canonical(101, [])->getChecksum(), 'gallery cleared');
    }

    /**
     * The mirror image of the rule above: attachment METADATA is not part of the product's
     * state, so an alt-text or URL edit cannot move the product checksum. It reaches the API
     * through resolution instead — which is the whole reason AG-10 composes rather than copies.
     */
    public function testAttachmentMetadataIsNotPartOfTheProductChecksum(): void
    {
        $unchanged = $this->canonical(101, [102])->getChecksum();

        $db = new FakeDbConnection();
        $db->willReturnRows([$this->productRow(1, 101, [102])]);
        $before = (new ProductResource())->toArray(
            (new ProductQueryProvider($db, $this->capability([101, 102])))
                ->list(new ProductFilterSet())->rows[0]
        );

        // The same product row, with the media capability now answering different metadata for
        // the SAME attachment ids — exactly what a WordPress image edit produces.
        $edited = new class implements MediaReferenceProviderInterface {
            /**
             * @param  list<int> $sourceAttachmentIds
             * @return array<int, array<string,mixed>>
             */
            public function resolveMany(array $sourceAttachmentIds): array
            {
                $out = [];
                foreach ($sourceAttachmentIds as $id) {
                    if ($id <= 0) {
                        continue;
                    }
                    $out[$id] = [
                        'slug'      => 'image-' . $id,
                        'url'       => 'https://example.test/' . $id . '-EDITED.jpg',
                        'alt_text'  => 'a new description',
                        'mime_type' => 'image/jpeg',
                        'width'     => 1600,
                        'height'    => 1200,
                        'sizes'     => [],
                    ];
                }

                return $out;
            }
        };

        $db2 = new FakeDbConnection();
        $db2->willReturnRows([$this->productRow(1, 101, [102])]);
        $after = (new ProductResource())->toArray(
            (new ProductQueryProvider($db2, $edited))->list(new ProductFilterSet())->rows[0]
        );

        self::assertNotSame($before['media']['featured'], $after['media']['featured']);
        self::assertSame('a new description', $after['media']['featured']['alt_text']);
        self::assertSame($unchanged, $this->canonical(101, [102])->getChecksum());
    }

    // -------------------------------------------------------------------------
    // Architecture (AG-10 boundaries)
    // -------------------------------------------------------------------------

    /**
     * Core declares the capability and knows no implementation of it.
     *
     * The two Commerce-side halves of this boundary — no Content import, no string naming a
     * `content.*` table — are already asserted in ModuleIndependenceTest and stay there; this is
     * the direction nothing covered: Core must not learn about a module in order to declare a
     * contract modules implement.
     */
    public function testCoreDoesNotImportTheContentImplementation(): void
    {
        $offenders = [];
        $root      = \dirname(__DIR__, 3) . '/core';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/^\s*use\s+HSP\\\\Modules\\\\/m', (string) file_get_contents($file->getPathname())) === 1) {
                $offenders[] = $file->getPathname();
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * No duplicate Commerce attachment projection: no migration creates a media table, and no
     * Commerce table stores resolved URLs, alt text or dimensions. References only (AG-10).
     */
    public function testCommerceCreatesNoAttachmentProjectionOfItsOwn(): void
    {
        $offenders = [];
        $root      = \dirname(__DIR__, 3) . '/modules/Commerce/Migrations';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $sql = (string) file_get_contents($file->getPathname());

            if (preg_match('/commerce\.(media|product_media|gallery_media)/i', $sql) === 1) {
                $offenders[] = $file->getPathname();
            }

            if (preg_match('/\b(alt_text|sizes_jsonb)\b/i', $sql) === 1) {
                $offenders[] = $file->getPathname() . ' (attachment metadata column)';
            }
        }

        self::assertSame([], $offenders);
    }

    /** @return list<string> */
    private function commercePhpFiles(): array
    {
        $files = [];
        $root  = \dirname(__DIR__, 3) . '/modules/Commerce';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
