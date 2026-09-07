<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * One in-memory implementation of the Commerce read boundary, for every test that needs a
 * source without WordPress.
 *
 * WHY THIS EXISTS: three separate test files had grown their own hand-written implementation of
 * this interface, and every session that added an aggregate broke all three at once — P2-S4 for
 * attributes, P2-S5 for variations. The fakes were also subtly inconsistent with each other
 * (one ignored the taxonomy argument to listTermIdsAfter and would happily return a category
 * when asked for `pa_colour` terms), which is worse than the duplication: a fake that answers a
 * question differently from the real loader tests nothing.
 *
 * Deliberately NOT final and deliberately public-property-based: subclasses add nothing but a
 * name, and tests populate the maps directly.
 */
class InMemoryCommerceLoader implements WpCommerceLoader
{
    /** @var array<int, array<string,mixed>> */
    public array $products = [];

    /** @var array<int, array<string,mixed>> */
    public array $terms = [];

    /** @var array<int, array<string,mixed>> */
    public array $attributes = [];

    /** @var array<int, array<string,mixed>> */
    public array $variations = [];

    // -------------------------------------------------------------------------
    // Products
    // -------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function loadProduct(int $productId): ?array
    {
        return $this->products[$productId] ?? null;
    }

    public function productType(int $productId): ?string
    {
        return isset($this->products[$productId])
            ? (string) $this->products[$productId]['product_type']
            : null;
    }

    /** @return list<int> */
    public function listProductIdsAfter(int $afterId, int $limit): array
    {
        return $this->idsAfter(array_keys($this->products), $afterId, $limit);
    }

    public function productExists(int $productId): bool
    {
        return isset($this->products[$productId]);
    }

    // -------------------------------------------------------------------------
    // Terms
    // -------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function loadTerm(int $termId): ?array
    {
        return $this->terms[$termId] ?? null;
    }

    /**
     * SCOPED BY TAXONOMY, matching the real loader.
     *
     * An earlier fake ignored this argument and returned every term regardless. That made the
     * attribute-term reconciliation corpus look correct while actually being the union of every
     * taxonomy — precisely the shared-table confusion DECISION AA exists to prevent, hidden
     * inside the test double rather than in the code under test.
     *
     * @return list<int>
     */
    public function listTermIdsAfter(string $taxonomy, int $afterId, int $limit): array
    {
        $ids = [];

        foreach ($this->terms as $id => $term) {
            if (($term['taxonomy'] ?? '') === $taxonomy) {
                $ids[] = $id;
            }
        }

        return $this->idsAfter($ids, $afterId, $limit);
    }

    // -------------------------------------------------------------------------
    // Attribute definitions
    // -------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public function loadAttribute(int $attributeId): ?array
    {
        return $this->attributes[$attributeId] ?? null;
    }

    /** @return list<int> */
    public function listAttributeIdsAfter(int $afterId, int $limit): array
    {
        return $this->idsAfter(array_keys($this->attributes), $afterId, $limit);
    }

    /** @return list<string> */
    public function attributeTaxonomyNames(): array
    {
        return array_values(array_map(
            static fn (array $a): string => (string) ($a['slug'] ?? ''),
            $this->attributes,
        ));
    }

    // -------------------------------------------------------------------------
    // Variations
    // -------------------------------------------------------------------------

    /**
     * Mirrors the real loader's SCOPE RULE: a variation whose parent is missing or out of
     * Phase 2 scope reads as absent (AG-13), which is what drives the tombstone path.
     *
     * @return array<string,mixed>|null
     */
    public function loadVariation(int $variationId): ?array
    {
        $variation = $this->variations[$variationId] ?? null;

        if ($variation === null) {
            return null;
        }

        $parentType = $this->productType((int) ($variation['parent_id'] ?? 0));

        if ($parentType === null
            || ! \HSP\Modules\Commerce\ProductScope::isSupportedType($parentType)
        ) {
            return null;
        }

        return $variation;
    }

    /** @return list<int> */
    public function listVariationIdsAfter(int $afterId, int $limit): array
    {
        return $this->idsAfter(array_keys($this->variations), $afterId, $limit);
    }

    /** Existence is about the POST, so it ignores the parent's scope — unlike loadVariation(). */
    public function variationExists(int $variationId): bool
    {
        return isset($this->variations[$variationId]);
    }

    // -------------------------------------------------------------------------

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private function idsAfter(array $ids, int $afterId, int $limit): array
    {
        $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > $afterId));
        sort($ids);

        return array_slice($ids, 0, $limit);
    }
}
