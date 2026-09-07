<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalVariation;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\ValidationException;
use HSP\Modules\Commerce\Validation\VariationValidator;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P2-S5 — the variation spine.
 *
 * The subject that makes variations different from every aggregate before them is the SELECTED
 * ATTRIBUTE MAP, and specifically its third state. WooCommerce distinguishes:
 *
 *   pa_colour => 'blue'   this variation is the blue one
 *   pa_size   => ''       this variation answers for ANY size
 *   (absent)              this variation does not vary by that attribute at all
 *
 * Verified against WooCommerce 11.1.0 (wc-product-functions.php:1208, which comments the empty
 * value as "'any' will be assumed"). Collapsing the middle case into the third is the obvious
 * mistake, and it would silently make `Blue / Any size` unmatchable by a storefront.
 */
final class VariationPipelineTest extends TestCase
{
    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function raw(array $overrides = []): array
    {
        return $overrides + [
            'id'                 => 77,
            'parent_id'          => 42,
            'sku'                => 'SHIRT-BLUE-L',
            'name'               => 'Shirt - Blue, Large',
            'description'        => '',
            'status'             => 'publish',
            'price'              => '19.99',
            'regular_price'      => '19.99',
            'sale_price'         => null,
            'featured_media_id'  => 0,
            'menu_order'         => 0,
            'attributes'         => ['pa_colour' => 'blue', 'pa_size' => 'large'],
            'attribute_term_ids' => [40, 41],
            'modified_at'        => '2026-01-01 00:00:00',
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function canonical(array $overrides = []): CanonicalVariation
    {
        $extractor = new VariationExtractor(new VariationValidator());

        $model = (new VariationTransformer())->transform($extractor->extract($this->raw($overrides)));
        self::assertInstanceOf(CanonicalVariation::class, $model);

        return $model;
    }

    // -------------------------------------------------------------------------
    // Extract → transform
    // -------------------------------------------------------------------------

    public function testAVariationSurvivesTheSpineIntact(): void
    {
        $model = $this->canonical();

        self::assertSame(77, $model->sourceVariationId);
        self::assertSame(42, $model->sourceParentId);
        self::assertSame('SHIRT-BLUE-L', $model->sku);
        self::assertSame('publish', $model->status);
        self::assertSame(['pa_colour' => 'blue', 'pa_size' => 'large'], $model->attributes);
        self::assertSame([40, 41], $model->attributeTermIds);
    }

    /** Money is normalised before the checksum can depend on it (Requirement C). */
    public function testPricesAreNormalisedToTheCanonicalDecimalForm(): void
    {
        $model = $this->canonical(['price' => '19.9900', 'regular_price' => '20.0']);

        self::assertSame('19.99', $model->price);
        self::assertSame('20', $model->regularPrice);
        self::assertNull($model->salePrice);
    }

    // -------------------------------------------------------------------------
    // The "any" semantic
    // -------------------------------------------------------------------------

    public function testAnEmptyAttributeValueIsPreservedAsAny(): void
    {
        $model = $this->canonical([
            'attributes'         => ['pa_colour' => 'blue', 'pa_size' => ''],
            'attribute_term_ids' => [40],
        ]);

        self::assertArrayHasKey('pa_size', $model->attributes);
        self::assertSame('', $model->attributes['pa_size']);
    }

    /**
     * "Any size" and "no size dimension" must not hash the same.
     *
     * If they did, a variation losing its size dimension entirely would be write-suppressed by
     * DECISION 3 and keep publishing a size axis it no longer has.
     */
    public function testAnyDiffersFromAbsentInTheChecksum(): void
    {
        $any = $this->canonical([
            'attributes'         => ['pa_colour' => 'blue', 'pa_size' => ''],
            'attribute_term_ids' => [40],
        ]);

        $absent = $this->canonical([
            'attributes'         => ['pa_colour' => 'blue'],
            'attribute_term_ids' => [40],
        ]);

        self::assertNotSame($any->getChecksum(), $absent->getChecksum());
    }

    /**
     * The resolved ids are a SUBSET of the map, never a replacement for it.
     *
     * This is why the delivery contract reads the map: an "any" entry has no term, so a
     * consumer reconstructing the selection from the join rows alone would lose it.
     */
    public function testResolvedTermIdsAreASubsetOfTheSelection(): void
    {
        $model = $this->canonical([
            'attributes'         => ['pa_colour' => 'blue', 'pa_size' => ''],
            'attribute_term_ids' => [40],
        ]);

        self::assertCount(2, $model->attributes);
        self::assertCount(1, $model->attributeTermIds);
    }

    /**
     * Non-`pa_` attributes are dropped — Phase 2 covers GLOBAL attributes only (AG-9).
     *
     * A store using a local/custom attribute is normal source, not an error, so the entry is
     * ignored silently rather than failing the variation.
     */
    public function testLocalAttributesAreDroppedRatherThanTreatedAsGlobal(): void
    {
        $model = $this->canonical([
            'attributes' => ['pa_colour' => 'blue', 'fabric' => 'cotton', 'Custom Thing' => 'x'],
        ]);

        self::assertSame(['pa_colour' => 'blue'], $model->attributes);
    }

    /** Stored key-sorted so a consumer diffing two projections does not see a reordering. */
    public function testTheSelectionIsKeySorted(): void
    {
        $model = $this->canonical([
            'attributes' => ['pa_size' => 'large', 'pa_colour' => 'blue'],
        ]);

        self::assertSame(['pa_colour', 'pa_size'], array_keys($model->attributes));
    }

    // -------------------------------------------------------------------------
    // Checksum (DECISION 3)
    // -------------------------------------------------------------------------

    public function testTheChecksumIsDeterministic(): void
    {
        self::assertSame($this->canonical()->getChecksum(), $this->canonical()->getChecksum());
    }

    /** Attribute order is not part of the identity, so it must not move the digest. */
    public function testTheChecksumIsOrderInsensitiveAcrossAttributes(): void
    {
        $a = $this->canonical(['attributes' => ['pa_colour' => 'blue', 'pa_size' => 'large']]);
        $b = $this->canonical(['attributes' => ['pa_size' => 'large', 'pa_colour' => 'blue']]);

        self::assertSame($a->getChecksum(), $b->getChecksum());
    }

    /**
     * Every projected field moves the checksum, or a change to it is write-suppressed and never
     * reaches consumers — invisible to reconciliation too, because it compares the same digest.
     *
     * @param array<string,mixed> $change
     */
    #[DataProvider('projectedFields')]
    public function testEveryProjectedFieldMovesTheChecksum(array $change): void
    {
        self::assertNotSame(
            $this->canonical()->getChecksum(),
            $this->canonical($change)->getChecksum(),
        );
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public static function projectedFields(): array
    {
        return [
            're-parented'        => [['parent_id' => 43]],
            'changed sku'        => [['sku' => 'OTHER']],
            'changed name'       => [['name' => 'Shirt - Red, Large']],
            'changed description' => [['description' => 'now with detail']],
            'changed status'     => [['status' => 'private']],
            'changed price'      => [['price' => '24.99']],
            'changed regular'    => [['regular_price' => '29.99']],
            'went on sale'       => [['sale_price' => '14.99']],
            'changed image'      => [['featured_media_id' => 9]],
            'reordered'          => [['menu_order' => 3]],
            'changed selection'  => [['attributes' => ['pa_colour' => 'red', 'pa_size' => 'large']]],
            'term re-created'    => [['attribute_term_ids' => [40, 99]]],
        ];
    }

    /**
     * A price that only differs in trailing zeros must NOT move the checksum.
     *
     * Otherwise a variation whose price has not changed churns its digest on every pass and
     * re-projects forever — the Requirement C trap.
     */
    public function testEquivalentDecimalFormsDoNotChurnTheChecksum(): void
    {
        self::assertSame(
            $this->canonical(['price' => '19.99'])->getChecksum(),
            $this->canonical(['price' => '19.9900'])->getChecksum(),
        );
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testAVariationWithNoParentIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        (new VariationExtractor(new VariationValidator()))->extract($this->raw(['parent_id' => 0]));
    }

    public function testAVariationThatIsItsOwnParentIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        (new VariationExtractor(new VariationValidator()))->extract($this->raw(['parent_id' => 77]));
    }

    /** A variation with every attribute set to "any" is legal, not a validation failure. */
    public function testAnEmptySelectionIsAccepted(): void
    {
        $model = $this->canonical(['attributes' => [], 'attribute_term_ids' => []]);

        self::assertSame([], $model->attributes);
    }

    // -------------------------------------------------------------------------
    // Handler: scope and lifecycle
    // -------------------------------------------------------------------------

    public function testAVariationOfASupportedParentProjects(): void
    {
        $adapter = new SpyVariationAdapter();

        $this->handler($adapter, parentType: 'variable')->handle($this->event());

        self::assertSame(1, $adapter->persistCalls);
        self::assertSame(0, $adapter->tombstoneCalls);
    }

    /**
     * A variation whose PARENT leaves supported scope is tombstoned (AG-13 + DECISION I).
     *
     * A variable product retyped to `grouped` takes its variations out of Phase 2 with it, and
     * leaving them publicly visible is exactly what DECISION I exists to prevent. Note that the
     * decision is reached through the LOADER — it declines to return the variation — so there is
     * no product-type branch in the handler and no variation-specific repair path.
     */
    public function testAVariationOfAnOutOfScopeParentIsTombstoned(): void
    {
        $adapter = new SpyVariationAdapter();

        $this->handler($adapter, parentType: 'grouped')->handle($this->event());

        self::assertSame(0, $adapter->persistCalls);
        self::assertSame(1, $adapter->tombstoneCalls);
        self::assertSame(['product_variation', '77'], $adapter->lastTombstone);
    }

    public function testAVariationDeletedSinceCaptureIsTombstoned(): void
    {
        $adapter = new SpyVariationAdapter();
        $loader  = new InMemoryCommerceLoader();

        // Parent present and supported, variation gone.
        $loader->products[42] = ['product_type' => 'variable'];

        $handler = new VariationUpsertHandler(
            $loader,
            new VariationExtractor(new VariationValidator()),
            new VariationTransformer(),
            $adapter,
        );

        $handler->handle($this->event());

        self::assertSame(1, $adapter->tombstoneCalls);
    }

    private function handler(SpyVariationAdapter $adapter, string $parentType): VariationUpsertHandler
    {
        $loader = new InMemoryCommerceLoader();

        $loader->products[42]   = ['product_type' => $parentType];
        $loader->variations[77] = $this->raw();

        return new VariationUpsertHandler(
            $loader,
            new VariationExtractor(new VariationValidator()),
            new VariationTransformer(),
            $adapter,
        );
    }

    private function event(): EventInterface
    {
        return new FakeCommerceEvent('commerce.product_variation.updated', 'product_variation', '77');
    }
}

/**
 * Adapter spy.
 *
 * The subject is which write path the handler CHOOSES — persist or tombstone — not what the
 * adapter writes, so this records the decision and touches no database.
 */
final class SpyVariationAdapter implements \HSP\Core\Contracts\AdapterInterface
{
    public int $persistCalls   = 0;
    public int $tombstoneCalls = 0;

    public ?CanonicalVariation $lastModel = null;

    /** @var array{0:string,1:string}|null */
    public ?array $lastTombstone = null;

    public function persist(
        \HSP\Core\Contracts\CanonicalModelInterface $model,
        EventInterface $event,
    ): void {
        $this->persistCalls++;

        if ($model instanceof CanonicalVariation) {
            $this->lastModel = $model;
        }
    }

    public function tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void
    {
        $this->tombstoneCalls++;
        $this->lastTombstone = [$aggregateType, $aggregateId];
    }

    /** @param \HSP\Core\Contracts\CanonicalModelInterface[] $models */
    public function bulkPersist(array $models): void
    {
        throw new \LogicException('not exercised');
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalVariation::class;
    }
}
