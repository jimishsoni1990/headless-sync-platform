<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\WpCommerceLoader;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — product-type scope and its transitions (DECISION AG AG-13).
 *
 * AG-13 makes all four transitions MANDATORY coverage, because WooCommerce product type
 * changes over time and the two interesting directions have opposite requirements:
 *
 *   unsupported → simple/variable   enters scope; projects normally
 *   simple/variable → unsupported   LEAVES scope; must TOMBSTONE, not linger
 *   unsupported → unsupported       successful no-op; no DLQ, no projection
 *   simple ↔ variable               normal current-state synchronisation
 *
 * The second is the one that would otherwise rot: without an explicit tombstone, a `variable`
 * product retyped to `grouped` would stay publicly visible forever, because nothing else in
 * the pipeline would ever touch it again.
 *
 * Equally load-bearing: an unsupported type is NORMAL SOURCE, not a processing failure. It
 * must not throw, because throwing means retry, and retry ends in the DLQ — which would let a
 * store full of grouped products block reconciliation and bootstrap convergence.
 */
final class ProductTypeTransitionTest extends TestCase
{
    private SpyProductAdapter $adapter;
    private FakeCommerceLoader $loader;
    private ProductUpsertHandler $handler;

    protected function setUp(): void
    {
        $this->loader  = new FakeCommerceLoader();
        $this->adapter = new SpyProductAdapter();

        $this->handler = new ProductUpsertHandler(
            $this->loader,
            new ProductExtractor(new ProductValidator()),
            new ProductTransformer(),
            $this->adapter,
        );
    }

    private function event(string $type = 'commerce.product.updated'): EventInterface
    {
        return new FakeCommerceEvent($type, 'product', '42');
    }

    // -------------------------------------------------------------------------
    // Scope
    // -------------------------------------------------------------------------

    public function testOnlySimpleAndVariableAreSupported(): void
    {
        self::assertSame(['simple', 'variable'], ProductScope::SUPPORTED_TYPES);

        self::assertTrue(ProductScope::isSupportedType('simple'));
        self::assertTrue(ProductScope::isSupportedType('variable'));

        foreach (['grouped', 'external', 'subscription', 'bundle', ''] as $unsupported) {
            self::assertFalse(ProductScope::isSupportedType($unsupported), $unsupported);
        }
    }

    // -------------------------------------------------------------------------
    // The four transitions
    // -------------------------------------------------------------------------

    public function testEnteringScopeProjectsNormally(): void
    {
        $this->loader->product = $this->product('simple');

        $this->handler->handle($this->event());

        self::assertSame(1, $this->adapter->persistCalls);
        self::assertSame(0, $this->adapter->tombstoneCalls);
    }

    /**
     * The transition that would otherwise leave a permanently visible stale projection.
     * Tombstoning uses the existing DECISION I path — no product-type-specific repair.
     */
    public function testLeavingScopeTombstonesRatherThanLingering(): void
    {
        $this->loader->product = $this->product('grouped');

        $this->handler->handle($this->event());

        self::assertSame(0, $this->adapter->persistCalls, 'an out-of-scope product must not project');
        self::assertSame(1, $this->adapter->tombstoneCalls);
        self::assertSame(['product', '42'], $this->adapter->lastTombstone);
    }

    public function testAnUnsupportedTypeIsANoOpNotAFailure(): void
    {
        $this->loader->product = $this->product('external');

        // No exception: throwing would mean retry, and retry ends in the DLQ — which AG-13
        // forbids for what is simply source outside Phase 2's scope.
        $this->handler->handle($this->event());

        self::assertSame(0, $this->adapter->persistCalls);
    }

    public function testSimpleToVariableProjectsTheNewType(): void
    {
        $this->loader->product = $this->product('variable');

        $this->handler->handle($this->event());

        self::assertSame(1, $this->adapter->persistCalls);
        self::assertSame('variable', $this->adapter->lastModel?->productType);
    }

    public function testVariableToSimpleProjectsTheNewType(): void
    {
        $this->loader->product = $this->product('simple');

        $this->handler->handle($this->event());

        self::assertSame('simple', $this->adapter->lastModel?->productType);
    }

    // -------------------------------------------------------------------------
    // Deleted between capture and processing
    // -------------------------------------------------------------------------

    /**
     * State sync (ADR-044): the product vanished after its event was captured. The deletion
     * event that follows is authoritative, so this is a no-op rather than a retry.
     */
    public function testAProductDeletedSinceCaptureIsANoOp(): void
    {
        $this->loader->product = null;

        $this->handler->handle($this->event());

        self::assertSame(0, $this->adapter->persistCalls);
        self::assertSame(0, $this->adapter->tombstoneCalls);
    }

    /** @return array<string,mixed> */
    private function product(string $type): array
    {
        return [
            'id'                 => 42,
            'sku'                => 'X-1',
            'slug'               => 'x-one',
            'name'               => 'X One',
            'description'        => '',
            'short_description'  => '',
            'status'             => 'publish',
            'product_type'       => $type,
            'catalog_visibility' => 'visible',
            'featured'           => false,
            'price'              => '10.00',
            'regular_price'      => '10.00',
            'sale_price'         => null,
            'featured_media_id'  => 0,
            'gallery_media_ids'  => [],
            'published_at'       => '2026-01-01 00:00:00',
            'modified_at'        => '2026-01-01 00:00:00',
            'meta'               => [],
        ];
    }
}

/** Loader double: one product, whose type each test controls. */
final class FakeCommerceLoader implements WpCommerceLoader
{
    /** @var array<string,mixed>|null */
    public ?array $product = null;

    /** @var list<int> */
    public array $ids = [];

    public function loadProduct(int $productId): ?array
    {
        return $this->product;
    }

    public function productType(int $productId): ?string
    {
        return $this->product === null ? null : (string) $this->product['product_type'];
    }

    /** @return list<int> */
    public function listProductIdsAfter(int $afterId, int $limit): array
    {
        return array_values(array_filter($this->ids, static fn (int $id): bool => $id > $afterId));
    }

    public function productExists(int $productId): bool
    {
        return $this->product !== null;
    }

    /** @var array<int, array<string,mixed>> */
    public array $terms = [];

    /** @return array<string,mixed>|null */
    public function loadTerm(int $termId): ?array
    {
        return $this->terms[$termId] ?? null;
    }

    /** @return list<int> */
    public function listTermIdsAfter(string $taxonomy, int $afterId, int $limit): array
    {
        $ids = [];

        foreach ($this->terms as $id => $term) {
            if ($id > $afterId && ($term['taxonomy'] ?? '') === $taxonomy) {
                $ids[] = $id;
            }
        }

        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    /** @var array<int, array<string,mixed>> */
    public array $attributes = [];

    /** @return array<string,mixed>|null */
    public function loadAttribute(int $attributeId): ?array
    {
        return $this->attributes[$attributeId] ?? null;
    }

    /** @return list<int> */
    public function listAttributeIdsAfter(int $afterId, int $limit): array
    {
        $ids = array_values(array_filter(
            array_keys($this->attributes),
            static fn (int $id): bool => $id > $afterId,
        ));
        sort($ids);

        return array_slice($ids, 0, $limit);
    }

    /** @return list<string> */
    public function attributeTaxonomyNames(): array
    {
        return array_values(array_map(
            static fn (array $a): string => (string) $a['slug'],
            $this->attributes,
        ));
    }
}

/**
 * Adapter spy.
 *
 * The subject of these tests is which write path the handler CHOOSES — persist or tombstone —
 * not what the adapter writes, so this records the decision and touches no database.
 *
 * The handlers depend on the core-owned AdapterInterface rather than the concrete
 * ProductAdapter, which is both the correct direction (a handler needs the contract, not the
 * implementation) and what makes this double possible without loosening `final`.
 */
final class SpyProductAdapter implements \HSP\Core\Contracts\AdapterInterface
{
    public int $persistCalls   = 0;
    public int $tombstoneCalls = 0;

    public ?\HSP\Modules\Commerce\CanonicalModels\CanonicalProduct $lastModel = null;

    /** @var array{0:string,1:string}|null */
    public ?array $lastTombstone = null;

    public function persist(
        \HSP\Core\Contracts\CanonicalModelInterface $model,
        \HSP\Core\Contracts\EventInterface $event,
    ): void {
        $this->persistCalls++;

        if ($model instanceof \HSP\Modules\Commerce\CanonicalModels\CanonicalProduct) {
            $this->lastModel = $model;
        }
    }

    public function tombstone(
        string $aggregateType,
        string $aggregateId,
        \HSP\Core\Contracts\EventInterface $event,
    ): void {
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
        return \HSP\Modules\Commerce\CanonicalModels\CanonicalProduct::class;
    }
}

/** Minimal EventInterface stub for the handler tests. */
final class FakeCommerceEvent implements \HSP\Core\Contracts\EventInterface
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
    ) {
    }

    public function getId(): string { return '01900000-0000-7000-8000-000000000001'; }
    public function getEventType(): string { return $this->eventType; }
    public function getEventVersion(): int { return 1; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getAggregateVersion(): int { return 1; }
    /** @return array<string,mixed> */
    public function getPayload(): array { return []; }
    public function getChecksum(): string { return str_repeat('c', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-01-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-01-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string { return null; }
}
