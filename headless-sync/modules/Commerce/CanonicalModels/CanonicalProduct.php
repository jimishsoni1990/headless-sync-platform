<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * The Commerce product in its canonical, delivery-ready shape.
 *
 * Produced by ProductTransformer; consumed by ProductAdapter. Rule 2 — this is the
 * TRANSFORMED delivery shape, never a `wp_posts`/`wp_postmeta` replica.
 *
 * CHECKSUM DISCIPLINE (DECISION 3): every value that is STORED in the projection must be part
 * of the digest, or a change to it is silently write-suppressed and never reaches consumers.
 * Phase 1B learned this three separate times — featured media, taxonomy type, tag ids — each
 * time by shipping a field that the checksum ignored. The digest below therefore covers every
 * projected column, and money is included in its normalised form so that `10`, `10.0` and
 * `10.00` cannot churn it (Requirement C).
 */
final class CanonicalProduct implements CanonicalModelInterface
{
    /**
     * @param int                 $sourceProductId   wp_posts.ID
     * @param string              $sku
     * @param string              $slug
     * @param string              $name
     * @param string              $description
     * @param string              $shortDescription
     * @param string              $status            WordPress post status
     * @param string              $productType       'simple' | 'variable' (AG-13)
     * @param string              $catalogVisibility 'visible'|'catalog'|'search'|'hidden'
     * @param bool                $featured
     * @param string|null         $price             normalised exact decimal string
     * @param string|null         $regularPrice      normalised exact decimal string
     * @param string|null         $salePrice         normalised exact decimal string
     * @param int                 $featuredMediaId   soft reference (AG-10); 0 = none
     * @param list<int>           $galleryMediaIds   soft references (AG-10)
     * @param \DateTimeImmutable  $publishedAt
     * @param \DateTimeImmutable  $updatedAt
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly int $sourceProductId,
        public readonly string $sku,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly string $shortDescription,
        public readonly string $status,
        public readonly string $productType,
        public readonly string $catalogVisibility,
        public readonly bool $featured,
        public readonly ?string $price,
        public readonly ?string $regularPrice,
        public readonly ?string $salePrice,
        public readonly int $featuredMediaId,
        public readonly array $galleryMediaIds,
        public readonly \DateTimeImmutable $publishedAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly array $meta,
    ) {
    }

    public function getSourceId(): int
    {
        return $this->sourceProductId;
    }

    /**
     * sha256 over every projected value, in a fixed order with recursively ksorted meta.
     *
     * Meta is sorted RECURSIVELY, not just at the top level: WordPress does not guarantee
     * nested key order, so two identical structured values could otherwise hash differently
     * and the projection would churn forever or read as permanently drifted. That exact bug
     * shipped in P1B-S4 with a docblock claiming recursion the code did not do.
     *
     * `updatedAt` is deliberately EXCLUDED: it moves on every save even when nothing a
     * consumer can see has changed, so including it would defeat write suppression entirely.
     */
    public function getChecksum(): string
    {
        $meta = $this->meta;
        self::ksortRecursive($meta);

        $parts = [
            (string) $this->sourceProductId,
            $this->sku,
            $this->slug,
            $this->name,
            $this->description,
            $this->shortDescription,
            $this->status,
            $this->productType,
            $this->catalogVisibility,
            $this->featured ? '1' : '0',
            $this->price ?? '',
            $this->regularPrice ?? '',
            $this->salePrice ?? '',
            (string) $this->featuredMediaId,
            implode(',', $this->galleryMediaIds),
            $this->publishedAt->format(\DateTimeInterface::ATOM),
            (string) json_encode($meta),
        ];

        return hash('sha256', implode('|', $parts));
    }

    /** @param array<mixed> $array */
    private static function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }
}
