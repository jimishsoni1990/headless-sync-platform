<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Replay;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\ReplayEmitterInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * Re-emits a Commerce aggregate through the normal outbox pipeline (DECISION T).
 *
 * Repair is re-emission and nothing else: no direct PostgreSQL write, no second repair path.
 * The emitter reads CURRENT WordPress state and decides the action from it (ADR-045 —
 * WordPress wins), so a replay converges on truth rather than replaying a stale payload.
 *
 * The action decision carries AG-13's scope rule: a product that exists but is no longer a
 * SUPPORTED type re-emits as DELETED, so a `variable` product retyped to `grouped` is
 * tombstoned rather than left publicly visible forever. Existence alone is not enough.
 *
 * Registered into the core-owned ReplayEmitterRegistry by aggregate type (AG-2), so it cannot
 * displace Content's emitter — the single-binding shape it replaced would have done exactly
 * that, silently.
 */
final class CommerceReplayEmitter implements ReplayEmitterInterface
{
    /** @var list<string> */
    private const AGGREGATE_TYPES = ['product', 'product_category', 'attribute', 'attribute_term'];

    public function __construct(
        private readonly EventProviderInterface $events,
        private readonly WpCommerceLoader $loader,
    ) {
    }

    /** @return list<string> */
    public function getSupportedAggregateTypes(): array
    {
        return self::AGGREGATE_TYPES;
    }

    public function emitForAggregate(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): EventInterface {
        if ($aggregateType === 'product_category' || $aggregateType === 'attribute_term') {
            return $this->emitTerm($aggregateType, $aggregateId, $correlationId, $causationId);
        }

        if ($aggregateType === 'attribute') {
            $exists = $this->loader->loadAttribute((int) $aggregateId) !== null;

            return $this->events->provide(
                $exists ? CommerceEventTypes::ATTRIBUTE_UPDATED : CommerceEventTypes::ATTRIBUTE_DELETED,
                $aggregateId,
                [
                    'correlation_id'    => $correlationId,
                    'causation_id'      => $causationId,
                    'source_updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                    'payload'           => ['attribute_id' => (int) $aggregateId],
                ],
            );
        }

        if ($aggregateType !== 'product') {
            throw new \InvalidArgumentException(
                self::class . " cannot emit aggregate type '{$aggregateType}'."
            );
        }

        $productId = (int) $aggregateId;
        $type      = $this->loader->productType($productId);

        // Absent, or present but out of supported scope → tombstone (DECISION I).
        $isPublic  = $type !== null && ProductScope::isSupportedType($type);

        $eventType = $isPublic
            ? CommerceEventTypes::PRODUCT_UPDATED
            : CommerceEventTypes::PRODUCT_DELETED;

        return $this->events->provide($eventType, $aggregateId, [
            'correlation_id'    => $correlationId,
            'causation_id'      => $causationId,
            'source_updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'payload'           => ['product_id' => $productId],
        ]);
    }

    /**
     * A term that has gone — or whose taxonomy this module no longer owns — re-emits as
     * DELETED, so a removed category tombstones rather than lingering.
     */
    private function emitTerm(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): EventInterface {
        $exists = $this->loader->loadTerm((int) $aggregateId) !== null;

        $updated = $aggregateType === 'attribute_term'
            ? CommerceEventTypes::ATTRIBUTE_TERM_UPDATED
            : CommerceEventTypes::CATEGORY_UPDATED;
        $deleted = $aggregateType === 'attribute_term'
            ? CommerceEventTypes::ATTRIBUTE_TERM_DELETED
            : CommerceEventTypes::CATEGORY_DELETED;

        return $this->events->provide(
            $exists ? $updated : $deleted,
            $aggregateId,
            [
                'correlation_id'    => $correlationId,
                'causation_id'      => $causationId,
                'source_updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'payload'           => ['term_id' => (int) $aggregateId],
            ],
        );
    }
}
