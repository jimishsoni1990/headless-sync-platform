<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;

/**
 * One stock owner's inventory in canonical, delivery-ready shape.
 *
 * CHECKSUM DISCIPLINE (DECISION 3): every projected value is in the digest. `ownerType` is in it
 * too, even though it cannot change for a given id in practice — leaving an identity component
 * out of a digest is the kind of shortcut that only stops being safe once something else moves.
 *
 * Note that `getSourceId()` returns the OWNER's WordPress id. That is what makes the aggregate
 * addressable: products and variations share the wp_posts sequence, so an owner id is unique
 * across both owner types without composing a key.
 */
final class CanonicalInventory implements CanonicalModelInterface
{
    public function __construct(
        public readonly string $ownerType,
        public readonly int $ownerId,
        public readonly bool $managesStock,
        public readonly ?int $stockQuantity,
        public readonly string $stockStatus,
        public readonly string $backorders,
        public readonly ?int $lowStockAmount,
    ) {
    }

    public function getSourceId(): int
    {
        return $this->ownerId;
    }

    public function getChecksum(): string
    {
        return hash('sha256', implode('|', [
            $this->ownerType,
            (string) $this->ownerId,
            $this->managesStock ? '1' : '0',
            // NULL and 0 must not collide: "untracked" and "none left" are opposite facts, and
            // a digest that conflated them would suppress the write that moves between them.
            $this->stockQuantity === null ? 'null' : (string) $this->stockQuantity,
            $this->stockStatus,
            $this->backorders,
            $this->lowStockAmount === null ? 'null' : (string) $this->lowStockAmount,
        ]));
    }
}
