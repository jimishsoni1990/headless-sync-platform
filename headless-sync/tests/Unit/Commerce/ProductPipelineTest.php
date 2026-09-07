<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Modules\Commerce\CanonicalModels\CanonicalProduct;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — the product spine: extract → validate → transform → canonical.
 *
 * The centre of gravity here is CHECKSUM DISCIPLINE. DECISION 3 uses the canonical checksum
 * to decide whether a projection write happens at all, so any projected value the digest
 * ignores is a field whose changes are silently write-suppressed and never reach consumers.
 * Phase 1B shipped that bug three separate times — featured media (P1B-S2), taxonomy type
 * (P1B-S3) and tag ids (P1B-S3) — each time discovered only after the fact.
 *
 * So rather than test the checksum once, every projected field is asserted to move it.
 */
final class ProductPipelineTest extends TestCase
{
    private ProductExtractor $extractor;
    private ProductTransformer $transformer;

    protected function setUp(): void
    {
        $this->extractor   = new ProductExtractor(new ProductValidator());
        $this->transformer = new ProductTransformer();
    }

    /** @return array<string,mixed> */
    private function raw(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 42,
            'sku'                => 'WIDGET-1',
            'slug'               => 'blue-widget',
            'name'               => 'Blue Widget',
            'description'        => 'A widget, in blue.',
            'short_description'  => 'Blue.',
            'status'             => 'publish',
            'product_type'       => 'simple',
            'catalog_visibility' => 'visible',
            'featured'           => false,
            'price'              => '19.99',
            'regular_price'      => '24.99',
            'sale_price'         => '19.99',
            'featured_media_id'  => 7,
            'gallery_media_ids'  => [8, 9],
            'published_at'       => '2026-01-02 03:04:05',
            'modified_at'        => '2026-02-03 04:05:06',
            'meta'               => ['colour' => 'blue'],
        ], $overrides);
    }

    private function canonical(array $overrides = []): CanonicalProduct
    {
        $model = $this->transformer->transform($this->extractor->extract($this->raw($overrides)));

        self::assertInstanceOf(CanonicalProduct::class, $model);

        return $model;
    }

    // -------------------------------------------------------------------------
    // Extraction and transformation
    // -------------------------------------------------------------------------

    public function testExtractsAndTransformsToTheCanonicalShape(): void
    {
        $model = $this->canonical();

        self::assertSame(42, $model->getSourceId());
        self::assertSame('WIDGET-1', $model->sku);
        self::assertSame('blue-widget', $model->slug);
        self::assertSame('simple', $model->productType);
        self::assertSame('visible', $model->catalogVisibility);
        self::assertSame([8, 9], $model->galleryMediaIds);
        self::assertSame('2026-01-02T03:04:05+00:00', $model->publishedAt->format(\DateTimeInterface::ATOM));
    }

    /** Money reaches the canonical model already normalised (Requirement C). */
    public function testPricesAreCanonicalisedDuringTransformation(): void
    {
        $model = $this->canonical(['price' => '19.9900', 'regular_price' => '25.00', 'sale_price' => '']);

        self::assertSame('19.99', $model->price);
        self::assertSame('25', $model->regularPrice);
        self::assertNull($model->salePrice, '"no sale price" must stay distinct from 0');
    }

    public function testTransformerRejectsAForeignSourceModel(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->transformer->transform(new \stdClass());
    }

    // -------------------------------------------------------------------------
    // Validation — structural only
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: array<string,mixed>}> */
    public static function invalidProducts(): array
    {
        return [
            'no id'    => [['id' => 0]],
            'no slug'  => [['slug' => '   ']],
            'no name'  => [['name' => '']],
        ];
    }

    #[DataProvider('invalidProducts')]
    public function testStructurallyUnusableProductsAreRejected(array $overrides): void
    {
        $this->expectException(ValidationException::class);

        $this->extractor->extract($this->raw($overrides));
    }

    /**
     * An unsupported product TYPE is not a validation failure — it is normal out-of-scope
     * source, filtered at capture (AG-13). Rejecting it here would make it retry and
     * eventually dead-letter, which is exactly what AG-13 forbids.
     */
    public function testAnUnsupportedProductTypeIsNotAValidationFailure(): void
    {
        $model = $this->canonical(['product_type' => 'grouped']);

        self::assertSame('grouped', $model->productType);
    }

    // -------------------------------------------------------------------------
    // Checksum discipline — every projected field must move the digest
    // -------------------------------------------------------------------------

    /** @return array<string, array{0: array<string,mixed>}> */
    public static function projectedFields(): array
    {
        return [
            'sku'                => [['sku' => 'WIDGET-2']],
            'slug'               => [['slug' => 'red-widget']],
            'name'               => [['name' => 'Red Widget']],
            'description'        => [['description' => 'Changed.']],
            'short_description'  => [['short_description' => 'Red.']],
            'status'             => [['status' => 'draft']],
            'product_type'       => [['product_type' => 'variable']],
            'catalog_visibility' => [['catalog_visibility' => 'hidden']],
            'featured'           => [['featured' => true]],
            'price'              => [['price' => '29.99']],
            'regular_price'      => [['regular_price' => '39.99']],
            'sale_price'         => [['sale_price' => '9.99']],
            'featured_media_id'  => [['featured_media_id' => 99]],
            'gallery_media_ids'  => [['gallery_media_ids' => [8]]],
            'published_at'       => [['published_at' => '2027-01-01 00:00:00']],
            'meta'               => [['meta' => ['colour' => 'red']]],
        ];
    }

    /**
     * The guard for the DECISION 3 write-suppress trap: if a projected field does not move
     * the checksum, changing it produces no write and the change never reaches consumers.
     */
    #[DataProvider('projectedFields')]
    public function testEveryProjectedFieldMovesTheChecksum(array $change): void
    {
        self::assertNotSame(
            $this->canonical()->getChecksum(),
            $this->canonical($change)->getChecksum(),
            'a projected value that does not move the checksum is silently write-suppressed',
        );
    }

    public function testTheChecksumIsStableForIdenticalInput(): void
    {
        self::assertSame($this->canonical()->getChecksum(), $this->canonical()->getChecksum());
    }

    /**
     * updated_at moves on every save even when nothing a consumer can see changed. Including
     * it would defeat write suppression entirely — every touch would re-project.
     */
    public function testModifiedAtAloneDoesNotMoveTheChecksum(): void
    {
        self::assertSame(
            $this->canonical()->getChecksum(),
            $this->canonical(['modified_at' => '2030-12-31 23:59:59'])->getChecksum(),
        );
    }

    /**
     * Money normalisation must reach the checksum, or a store that reformats prices churns
     * every product forever.
     */
    public function testSemanticallyEqualPricesProduceOneChecksum(): void
    {
        $base = $this->canonical(['price' => '19.99'])->getChecksum();

        self::assertSame($base, $this->canonical(['price' => '19.990'])->getChecksum());
        self::assertSame($base, $this->canonical(['price' => '019.99'])->getChecksum());
    }

    /**
     * WordPress does not guarantee nested meta key order, so without RECURSIVE sorting two
     * identical structured values hash differently and the projection churns or reads as
     * permanently drifted. P1B-S4 shipped a docblock claiming recursion the code did not do.
     */
    public function testNestedMetaKeyOrderDoesNotAffectTheChecksum(): void
    {
        $a = $this->canonical(['meta' => ['spec' => ['w' => 1, 'h' => 2], 'colour' => 'blue']]);
        $b = $this->canonical(['meta' => ['colour' => 'blue', 'spec' => ['h' => 2, 'w' => 1]]]);

        self::assertSame($a->getChecksum(), $b->getChecksum());
    }

    public function testNestedMetaVALUESStillMoveTheChecksum(): void
    {
        $a = $this->canonical(['meta' => ['spec' => ['w' => 1]]]);
        $b = $this->canonical(['meta' => ['spec' => ['w' => 2]]]);

        self::assertNotSame(
            $a->getChecksum(),
            $b->getChecksum(),
            'recursive sorting must not flatten away real differences',
        );
    }
}
