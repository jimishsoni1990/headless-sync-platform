<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * A product variation in canonical, delivery-ready shape.
 *
 * CHECKSUM DISCIPLINE (DECISION 3): every value STORED in the projection is in the digest, or a
 * change to it is silently write-suppressed and never reaches consumers. Two entries here carry
 * that weight specifically:
 *
 *   - `attributes`, because a variation moving from Blue to Red changes nothing else about
 *     itself — same sku, same price, same name is even possible — so an omitted attribute map
 *     would leave the projection permanently showing the wrong selection, invisible to
 *     reconciliation because it compares the same checksum.
 *
 *   - `parentId`, because WooCommerce genuinely allows a variation to be re-parented, and the
 *     parent id is what every read of "this product's variations" resolves through.
 */
final class CanonicalVariation implements CanonicalModelInterface
{
    /**
     * @param int                  $sourceVariationId wp_posts.ID of the variation
     * @param int                  $sourceParentId    wp_posts.ID of the parent (soft ref, AG-7)
     * @param string               $sku
     * @param string               $name
     * @param string               $description
     * @param string               $status            WordPress post status
     * @param string|null          $price             normalised exact decimal string
     * @param string|null          $regularPrice      normalised exact decimal string
     * @param string|null          $salePrice         normalised exact decimal string
     * @param int                  $featuredMediaId   soft reference (AG-10); 0 = none
     * @param int                  $menuOrder
     * @param array<string,string> $attributes        taxonomy => term slug, '' meaning "any"
     * @param list<int>            $attributeTermIds  the subset of $attributes that resolves to a
     *                                                term, as term ids. Strictly smaller than the
     *                                                map: an "any" entry has no term. These drive
     *                                                the join rows; the map drives the contract.
     * @param \DateTimeImmutable   $updatedAt
     */
    public function __construct(
        public readonly int $sourceVariationId,
        public readonly int $sourceParentId,
        public readonly string $sku,
        public readonly string $name,
        public readonly string $description,
        public readonly string $status,
        public readonly ?string $price,
        public readonly ?string $regularPrice,
        public readonly ?string $salePrice,
        public readonly int $featuredMediaId,
        public readonly int $menuOrder,
        public readonly array $attributes,
        public readonly array $attributeTermIds,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }

    public function getSourceId(): int
    {
        return $this->sourceVariationId;
    }

    public function getChecksum(): string
    {
        // The map is serialised with its keys sorted so that the digest depends on the SELECTION
        // and not on the order WooCommerce happened to return it in — the same order-insensitivity
        // CanonicalProduct applies to its meta.
        $attributes = $this->attributes;
        ksort($attributes);

        $parts = [
            (string) $this->sourceVariationId,
            (string) $this->sourceParentId,
            $this->sku,
            $this->name,
            $this->description,
            $this->status,
            $this->price ?? '',
            $this->regularPrice ?? '',
            $this->salePrice ?? '',
            (string) $this->featuredMediaId,
            (string) $this->menuOrder,
            (string) json_encode($attributes),
            // Link rows are STORED in commerce.entity_taxonomies and rewritten only when the
            // projection write is not suppressed, so the resolved ids must move the digest too.
            // Normally they move with the map above, but not always: re-creating a term with the
            // same slug gives a new term id and an unchanged map.
            implode(',', $this->attributeTermIds),
        ];

        return hash('sha256', implode('|', $parts));
    }
}
