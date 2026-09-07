<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * A Commerce taxonomy term in canonical, delivery-ready shape.
 *
 * Taxonomy-generic: one model serves `product_cat` and every `pa_*` attribute taxonomy,
 * distinguished by `taxonomyType`.
 *
 * `taxonomyType` IS in the checksum, and that is not incidental. It is a stored projection
 * column, and DECISION 3 requires every stored column to be in the digest — P1B-S3 had to
 * append exactly this field to `CanonicalCategory` for the same reason, causing a one-off
 * re-projection wave. Getting it right here means Commerce never pays that.
 */
final class CanonicalTerm implements CanonicalModelInterface
{
    public function __construct(
        public readonly int $sourceTermId,
        public readonly string $taxonomyType,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly int $parentId,
        public readonly int $count,
    ) {
    }

    public function getSourceId(): int
    {
        return $this->sourceTermId;
    }

    /**
     * sha256 over every projected value.
     *
     * `count` is included because it is a projected column a consumer can see. It does mean a
     * term re-projects when its product count moves, which is correct: the published value
     * changed.
     */
    public function getChecksum(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->sourceTermId,
            $this->taxonomyType,
            $this->slug,
            $this->name,
            $this->description,
            (string) $this->parentId,
            (string) $this->count,
        ]));
    }
}
