<?php

declare(strict_types=1);

namespace HSP\Modules\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * Canonical representation of a WordPress category term (taxonomy='category').
 *
 * Produced by CategoryTransformer from CategorySourceModel. Delivery-target
 * agnostic — no PostgreSQL column names, no checksum here (checksum is computed
 * write-side per DECISION 3, not stored on the canonical model).
 *
 * Immutable value object; no side effects.
 */
final class CanonicalCategory implements CanonicalModelInterface
{
    /**
     * @param int    $termId      wp_terms.term_id
     * @param string $name        Display label
     * @param string $slug        URL slug
     * @param string $description Term description (may be empty string)
     * @param int    $parentId    Parent term_id (0 = top-level category)
     * @param int    $count       Published post count
     */
    public function __construct(
        public readonly int $termId,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $description,
        public readonly int $parentId,
        public readonly int $count,
    ) {}

    public function getSourceId(): int
    {
        return $this->termId;
    }

    public function getChecksum(): string
    {
        // Fixed field order (must match the write-side recomputation in P1A-S4 adapters):
        // termId | name | slug | description | parentId | count
        // Separator: chr(0) — cannot appear in any field value.
        // All fields are scalars; no PHP-internal serialization used.
        return hash('sha256', implode("\0", [
            (string) $this->termId,
            $this->name,
            $this->slug,
            $this->description,
            (string) $this->parentId,
            (string) $this->count,
        ]));
    }
}
