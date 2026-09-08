<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalInventory;
use HSP\Modules\Commerce\Extractors\InventoryExtractor;
use HSP\Modules\Commerce\Handlers\InventoryUpsertHandler;
use HSP\Modules\Commerce\InventoryOwner;
use HSP\Modules\Commerce\Transformers\InventoryTransformer;
use HSP\Modules\Commerce\Validation\InventoryValidator;
use HSP\Modules\Commerce\Validation\ValidationException;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * P2-S6 — who owns a stock fact, and the trap in answering it (DECISION AG AG-14).
 *
 * The preflight found that WooCommerce already models ownership:
 * `get_stock_managed_by_id()` returns the id of whatever entity holds this entity's stock. So
 * the rule is a comparison, not a policy — and the danger is entirely in the SIGNAL it is
 * derived from.
 *
 * `WC_Product_Variation::get_manage_stock()` is TRI-STATE: `true`, `false`, or the string
 * `'parent'`. And `'parent'` is TRUTHY. A boolean check therefore reads a parent-managed
 * variation as self-managed, produces the duplicated inventory row AG-14 forbids, and copies the
 * parent's quantity onto the variation as though the variation held it — because
 * `get_stock_quantity()` transparently returns the parent's value in that state. Nothing about
 * that failure looks like an error; it looks like data.
 */
final class InventoryOwnershipTest extends TestCase
{
    // -------------------------------------------------------------------------
    // The ownership rule
    // -------------------------------------------------------------------------

    public function testAnEntityOwnsStockWhenWooCommerceNamesItself(): void
    {
        self::assertTrue(InventoryOwner::owns(42, 42));
    }

    public function testAVariationDoesNotOwnStockItsParentManages(): void
    {
        self::assertFalse(InventoryOwner::owns(77, 42));
    }

    public function testAnInvalidIdOwnsNothing(): void
    {
        self::assertFalse(InventoryOwner::owns(0, 0));
        self::assertFalse(InventoryOwner::owns(-1, -1));
    }

    /**
     * The tri-state value, asserted as a documented constant rather than left implicit.
     *
     * A future reader reaching for `(bool) $manageStock` should find this test first.
     */
    public function testTheInheritedSentinelIsAStringAndIsTruthy(): void
    {
        self::assertSame('parent', InventoryOwner::MANAGED_BY_PARENT);
        self::assertIsString(InventoryOwner::MANAGED_BY_PARENT);
        self::assertTrue((bool) InventoryOwner::MANAGED_BY_PARENT, 'which is exactly the trap');
    }

    #[DataProvider('ownerTypes')]
    public function testTheOwnerTypeForAProductType(string $productType, ?string $expected): void
    {
        self::assertSame($expected, InventoryOwner::typeFor($productType));
    }

    /** @return array<string, array{0: string, 1: string|null}> */
    public static function ownerTypes(): array
    {
        return [
            'simple'     => ['simple', InventoryOwner::TYPE_PRODUCT],
            'variable'   => ['variable', InventoryOwner::TYPE_PRODUCT],
            'variation'  => ['variation', InventoryOwner::TYPE_VARIATION],
            // Out of Phase 2 scope owns no inventory this platform projects — not an error.
            'grouped'    => ['grouped', null],
            'external'   => ['external', null],
            'unknown'    => ['', null],
        ];
    }

    public function testOnlyProductsAndVariationsAreOwnerTypes(): void
    {
        self::assertTrue(InventoryOwner::isOwnerType('product'));
        self::assertTrue(InventoryOwner::isOwnerType('product_variation'));
        self::assertFalse(InventoryOwner::isOwnerType('product_category'));
        self::assertFalse(InventoryOwner::isOwnerType('attribute'));
        self::assertFalse(InventoryOwner::isOwnerType(''));
    }

    // -------------------------------------------------------------------------
    // Canonical shape and checksum
    // -------------------------------------------------------------------------

    /** @param array<string,mixed> $overrides */
    private function canonical(array $overrides = []): CanonicalInventory
    {
        $raw = $overrides + [
            'owner_type'       => InventoryOwner::TYPE_PRODUCT,
            'owner_id'         => 42,
            'manages_stock'    => true,
            'stock_quantity'   => 5,
            'stock_status'     => 'instock',
            'backorders'       => 'no',
            'low_stock_amount' => null,
        ];

        $model = (new InventoryTransformer())->transform(
            (new InventoryExtractor(new InventoryValidator()))->extract($raw)
        );

        self::assertInstanceOf(CanonicalInventory::class, $model);

        return $model;
    }

    public function testTheChecksumIsDeterministic(): void
    {
        self::assertSame($this->canonical()->getChecksum(), $this->canonical()->getChecksum());
    }

    /**
     * "Untracked" and "none left" are opposite facts, so NULL and 0 must not hash the same.
     *
     * If they did, the write that moves a product between them would be suppressed by
     * DECISION 3 — publishing "unlimited" about something that has sold out, or the reverse.
     */
    public function testNullQuantityAndZeroQuantityDoNotCollide(): void
    {
        $untracked = $this->canonical(['manages_stock' => false, 'stock_quantity' => null]);
        $soldOut   = $this->canonical(['manages_stock' => true, 'stock_quantity' => 0]);

        self::assertNotSame($untracked->getChecksum(), $soldOut->getChecksum());
        self::assertNull($untracked->stockQuantity);
        self::assertSame(0, $soldOut->stockQuantity);
    }

    /**
     * A quantity carried alongside "not managed" is dropped.
     *
     * WooCommerce leaves a stale `_stock` value in place when management is switched off, and
     * publishing it would present a number WooCommerce itself no longer considers meaningful.
     */
    public function testAnUntrackedOwnerPublishesNoQuantity(): void
    {
        $model = $this->canonical(['manages_stock' => false, 'stock_quantity' => 99]);

        self::assertNull($model->stockQuantity);
    }

    /** @param array<string,mixed> $change */
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
            'owner type'     => [['owner_type' => InventoryOwner::TYPE_VARIATION]],
            'owner id'       => [['owner_id' => 43]],
            'stopped tracking' => [['manages_stock' => false]],
            'quantity'       => [['stock_quantity' => 4]],
            'status'         => [['stock_status' => 'outofstock']],
            'backorders'     => [['backorders' => 'notify']],
            'low stock'      => [['low_stock_amount' => 2]],
        ];
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function testAnUnrecognisedStockStatusIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->canonical(['stock_status' => 'maybe']);
    }

    public function testANonOwnerAggregateTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->canonical(['owner_type' => 'product_category']);
    }

    /**
     * A negative quantity is ACCEPTED — it is how WooCommerce represents backorders, and
     * refusing it would dead-letter a perfectly normal store state.
     */
    public function testANegativeQuantityIsAccepted(): void
    {
        self::assertSame(-3, $this->canonical(['stock_quantity' => -3])->stockQuantity);
    }

    // -------------------------------------------------------------------------
    // Handler: the ownership transitions
    // -------------------------------------------------------------------------

    public function testAnOwnerProjects(): void
    {
        $adapter = new SpyInventoryAdapter();
        $loader  = new InMemoryCommerceLoader();

        $loader->products[42]  = ['product_type' => 'simple'];
        $loader->inventory[42] = [
            'owner_type' => InventoryOwner::TYPE_PRODUCT, 'owner_id' => 42,
            'manages_stock' => true, 'stock_quantity' => 5,
            'stock_status' => 'instock', 'backorders' => 'no', 'low_stock_amount' => null,
        ];

        $this->handler($loader, $adapter)->handle($this->event(42));

        self::assertSame(1, $adapter->persistCalls);
        self::assertSame(0, $adapter->tombstoneCalls);
    }

    /**
     * An entity that is not an owner TOMBSTONES.
     *
     * This one branch covers three different real situations — the entity is gone, it is out of
     * Phase 2 scope, or its stock is now managed by its parent — because the loader collapses
     * them all to "absent". They share a required outcome, so distinguishing them here would add
     * branches without adding behaviour.
     */
    public function testANonOwnerIsTombstoned(): void
    {
        $adapter = new SpyInventoryAdapter();
        $loader  = new InMemoryCommerceLoader();

        // A variation whose parent manages the stock: no inventory entry for it at all.
        $loader->products[42]   = ['product_type' => 'variable'];
        $loader->variations[77] = ['id' => 77, 'parent_id' => 42];

        $this->handler($loader, $adapter)->handle($this->event(77));

        self::assertSame(0, $adapter->persistCalls);
        self::assertSame(1, $adapter->tombstoneCalls);
        self::assertSame(['inventory', '77'], $adapter->lastTombstone);
    }

    /** A variation of an out-of-scope parent is out of scope with it (AG-13). */
    public function testAVariationOfAnOutOfScopeParentIsTombstoned(): void
    {
        $adapter = new SpyInventoryAdapter();
        $loader  = new InMemoryCommerceLoader();

        $loader->products[42]   = ['product_type' => 'grouped'];
        $loader->variations[77] = ['id' => 77, 'parent_id' => 42];
        $loader->inventory[77]  = [
            'owner_type' => InventoryOwner::TYPE_VARIATION, 'owner_id' => 77,
            'manages_stock' => true, 'stock_quantity' => 2,
            'stock_status' => 'instock', 'backorders' => 'no', 'low_stock_amount' => null,
        ];

        $this->handler($loader, $adapter)->handle($this->event(77));

        self::assertSame(0, $adapter->persistCalls);
        self::assertSame(1, $adapter->tombstoneCalls);
    }

    private function handler(
        InMemoryCommerceLoader $loader,
        SpyInventoryAdapter $adapter,
    ): InventoryUpsertHandler {
        return new InventoryUpsertHandler(
            $loader,
            new InventoryExtractor(new InventoryValidator()),
            new InventoryTransformer(),
            $adapter,
        );
    }

    private function event(int $ownerId): EventInterface
    {
        return new FakeCommerceEvent('commerce.inventory.updated', 'inventory', (string) $ownerId);
    }
}

/** Adapter spy — the subject is which write path the handler chooses, not what it writes. */
final class SpyInventoryAdapter implements \HSP\Core\Contracts\AdapterInterface
{
    public int $persistCalls   = 0;
    public int $tombstoneCalls = 0;

    public ?CanonicalInventory $lastModel = null;

    /** @var array{0:string,1:string}|null */
    public ?array $lastTombstone = null;

    public function persist(
        \HSP\Core\Contracts\CanonicalModelInterface $model,
        EventInterface $event,
    ): void {
        $this->persistCalls++;

        if ($model instanceof CanonicalInventory) {
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
        return CanonicalInventory::class;
    }
}
