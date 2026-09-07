<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\ProjectionDescriptor;
use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Extractors\AttributeExtractor;
use HSP\Modules\Commerce\Transformers\AttributeTransformer;
use HSP\Modules\Commerce\Validation\AttributeValidator;
use HSP\Modules\Commerce\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P2-S4 — global attribute definitions, and the dynamic-taxonomy machinery they need.
 *
 * AG-9 splits WooCommerce's attribute model in two, and the split is the thing worth testing:
 * a DEFINITION (`Colour`, id 3, taxonomy `pa_colour`, type `select`) is not a taxonomy term and
 * gets its own projection, while the terms of `pa_colour` are ordinary terms sharing
 * commerce.taxonomies with product categories.
 *
 * What makes attribute terms different from every aggregate before them is that their taxonomy
 * is DYNAMIC — an operator creates `pa_colour` whenever they like — so nothing in the platform
 * can enumerate the set. That is why the descriptor grew a prefix-match mode, and most of the
 * assertions here are about the boundary of that prefix.
 */
final class AttributePipelineTest extends TestCase
{
    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function raw(array $overrides = []): array
    {
        return $overrides + [
            'id'           => 3,
            'slug'         => 'pa_colour',
            'name'         => 'Colour',
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private function canonical(array $overrides = []): \HSP\Modules\Commerce\CanonicalModels\CanonicalAttribute
    {
        $extractor = new AttributeExtractor(new AttributeValidator());

        $model = (new AttributeTransformer())->transform($extractor->extract($this->raw($overrides)));
        self::assertInstanceOf(\HSP\Modules\Commerce\CanonicalModels\CanonicalAttribute::class, $model);

        return $model;
    }

    // -------------------------------------------------------------------------
    // Extract → transform
    // -------------------------------------------------------------------------

    public function testADefinitionSurvivesTheSpineIntact(): void
    {
        $model = $this->canonical();

        self::assertSame(3, $model->sourceAttributeId);
        self::assertSame('pa_colour', $model->slug);
        self::assertSame('Colour', $model->name);
        self::assertSame('select', $model->type);
        self::assertSame('menu_order', $model->orderBy);
        self::assertFalse($model->hasArchives);
    }

    /**
     * The slug keeps its `pa_` prefix all the way to the contract.
     *
     * Stripping it would look tidier and would break the only join a consumer has: a term row
     * carries `taxonomy_type = 'pa_colour'`, so an attribute publishing `colour` could not be
     * matched to its own terms without every consumer re-adding the prefix.
     */
    public function testTheTaxonomyPrefixIsNotStripped(): void
    {
        self::assertSame('pa_colour', $this->canonical()->slug);
    }

    public function testTheProjectedDefaultsMatchWooCommerce(): void
    {
        $extractor = new AttributeExtractor(new AttributeValidator());

        $model = $extractor->extract(['id' => 9, 'slug' => 'pa_size', 'name' => 'Size']);

        self::assertSame('select', $model->type);
        self::assertSame('menu_order', $model->orderBy);
        self::assertFalse($model->hasArchives);
    }

    // -------------------------------------------------------------------------
    // Checksum (DECISION 3)
    // -------------------------------------------------------------------------

    public function testTheChecksumIsDeterministic(): void
    {
        self::assertSame($this->canonical()->getChecksum(), $this->canonical()->getChecksum());
    }

    /**
     * Every projected field must move the checksum.
     *
     * A field left out is a field whose change is write-suppressed by DECISION 3 and never
     * reaches delivery — and because reconciliation compares the same checksum, the drift is
     * invisible to repair as well. `slug` is the one that bites: WooCommerce renames the whole
     * taxonomy when an attribute is renamed, which is why the update hook carries the old slug.
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
            'renamed taxonomy' => [['slug' => 'pa_color']],
            'renamed label'    => [['name' => 'Colours']],
            'changed type'     => [['type' => 'text']],
            'changed ordering' => [['order_by' => 'name']],
            'archives enabled' => [['has_archives' => true]],
        ];
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testAnAttributeWithNoTaxonomySlugIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        (new AttributeExtractor(new AttributeValidator()))->extract($this->raw(['slug' => '']));
    }

    public function testAnAttributeWithNoLabelIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        (new AttributeExtractor(new AttributeValidator()))->extract($this->raw(['name' => '  ']));
    }

    public function testAnAttributeWithoutASourceIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        (new AttributeExtractor(new AttributeValidator()))->extract($this->raw(['id' => 0]));
    }

    // -------------------------------------------------------------------------
    // Taxonomy routing — the dynamic half
    // -------------------------------------------------------------------------

    public function testEveryPaTaxonomyMapsToTheOneAttributeTermAggregate(): void
    {
        self::assertSame('attribute_term', CommerceTaxonomies::aggregateFor('pa_colour'));
        self::assertSame('attribute_term', CommerceTaxonomies::aggregateFor('pa_size'));
        self::assertSame('attribute_term', CommerceTaxonomies::aggregateFor('pa_anything_at_all'));
    }

    public function testProductCategoriesKeepTheirOwnAggregate(): void
    {
        self::assertSame('product_category', CommerceTaxonomies::aggregateFor('product_cat'));
        self::assertFalse(CommerceTaxonomies::isAttributeTaxonomy('product_cat'));
    }

    /**
     * The bare prefix is not a taxonomy, and neither is something that merely contains it.
     *
     * `pa_` alone cannot exist as a WordPress taxonomy, and treating it as one would give the
     * attribute-term aggregate a member with no attribute behind it. `spa_colour` is the other
     * direction: prefix matching must anchor at the start or the aggregate quietly claims
     * another plugin's taxonomy.
     */
    public function testTheBarePrefixAndNearMissesAreNotOwned(): void
    {
        self::assertNull(CommerceTaxonomies::aggregateFor('pa_'));
        self::assertNull(CommerceTaxonomies::aggregateFor('spa_colour'));
        self::assertNull(CommerceTaxonomies::aggregateFor('post_tag'));
        self::assertNull(CommerceTaxonomies::aggregateFor(''));
    }

    public function testAttributeTermsRouteToTheirOwnEventTypes(): void
    {
        self::assertSame(
            CommerceEventTypes::ATTRIBUTE_TERM_CREATED,
            CommerceTaxonomies::eventFor('pa_colour', 'created'),
        );
        self::assertSame(
            CommerceEventTypes::CATEGORY_UPDATED,
            CommerceTaxonomies::eventFor('product_cat', 'updated'),
        );
        self::assertNull(CommerceTaxonomies::eventFor('post_tag', 'created'));
    }

    // -------------------------------------------------------------------------
    // ProjectionDescriptor prefix mode (AG-3)
    // -------------------------------------------------------------------------

    public function testAPrefixDescriptorReportsItsMatchMode(): void
    {
        $descriptor = new ProjectionDescriptor(
            'attribute_term',
            'commerce.taxonomies',
            'source_term_id',
            'pa_',
            'taxonomy_type',
            ProjectionDescriptor::MATCH_PREFIX,
        );

        self::assertTrue($descriptor->isDiscriminated());
        self::assertTrue($descriptor->matchesByPrefix());
    }

    public function testAnExactDescriptorIsNotAPrefixDescriptor(): void
    {
        $descriptor = new ProjectionDescriptor(
            'product_category',
            'commerce.taxonomies',
            'source_term_id',
            'product_cat',
            'taxonomy_type',
        );

        self::assertTrue($descriptor->isDiscriminated());
        self::assertFalse($descriptor->matchesByPrefix());
    }

    /**
     * A prefix reaches SQL inside a LIKE pattern, so a wildcard in it would silently widen the
     * set the aggregate claims — and an aggregate claiming rows it does not own is how an
     * orphan sweep tombstones another aggregate's data. Refused, not escaped.
     */
    #[DataProvider('wildcardPrefixes')]
    public function testAPrefixCarryingALikeWildcardIsRefused(string $prefix): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProjectionDescriptor(
            'attribute_term',
            'commerce.taxonomies',
            'source_term_id',
            $prefix,
            'taxonomy_type',
            ProjectionDescriptor::MATCH_PREFIX,
        );
    }

    /** @return array<string, array{0: string}> */
    public static function wildcardPrefixes(): array
    {
        return [
            'percent'    => ['pa%'],
            'underscore-wildcard prefixed with quote' => ["pa_' OR '1'='1"],
            'quote'      => ["pa'"],
            'whitespace' => ['pa '],
        ];
    }

    public function testAnUnknownMatchModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ProjectionDescriptor(
            'attribute_term',
            'commerce.taxonomies',
            'source_term_id',
            'pa_',
            'taxonomy_type',
            'regex',
        );
    }
}
