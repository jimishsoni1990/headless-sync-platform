<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * A global attribute definition in canonical, delivery-ready shape.
 *
 * Every projected column is in the checksum (DECISION 3). `slug` especially: renaming an
 * attribute renames its taxonomy — verified, woocommerce_attribute_updated carries $old_slug
 * for exactly that reason — so a rename MUST move the digest or the change is write-suppressed
 * and consumers keep the stale taxonomy name.
 */
final class CanonicalAttribute implements CanonicalModelInterface
{
    public function __construct(
        public readonly int $sourceAttributeId,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $type,
        public readonly string $orderBy,
        public readonly bool $hasArchives,
    ) {
    }

    public function getSourceId(): int
    {
        return $this->sourceAttributeId;
    }

    public function getChecksum(): string
    {
        return hash('sha256', implode('|', [
            (string) $this->sourceAttributeId,
            $this->slug,
            $this->name,
            $this->type,
            $this->orderBy,
            $this->hasArchives ? '1' : '0',
        ]));
    }
}
