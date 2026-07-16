<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;

/**
 * A scriptable WpReconciliationSourceInterface for OPSC-S4 action-path unit tests.
 *
 * Reports a small, fixed WordPress corpus for ONE aggregate type so ReconciliationService's
 * forward pass detects a missed capture (a live/public WP aggregate with no projection row) and
 * repairs it via re-emission. Read-only on the WP side (matches the contract); no DB, no WP.
 */
final class ScriptedReconciliationSource implements WpReconciliationSourceInterface
{
    /**
     * @param string   $type public/live aggregate type reported (e.g. 'post')
     * @param string[] $ids  live WordPress aggregate ids of that type
     */
    public function __construct(
        private readonly string $type,
        private readonly array $ids,
    ) {}

    /** @return string[] */
    public function getSupportedAggregateTypes(): array
    {
        return [$this->type];
    }

    /** @return string[] */
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        if ($aggregateType !== $this->type) {
            return [];
        }

        // Return ids strictly greater than $afterId, ascending, one page (test corpus is tiny).
        $remaining = array_values(array_filter(
            $this->ids,
            static fn (string $id): bool => (int) $id > $afterId,
        ));

        return array_slice($remaining, 0, $limit);
    }

    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
        // Every scripted id is a live, public WP aggregate (a category has public == exists).
        if ($aggregateType === $this->type && in_array($aggregateId, $this->ids, true)) {
            return new SourceState(exists: true, public: true, modifiedAt: null);
        }

        return SourceState::absent();
    }

    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string
    {
        return null;
    }

    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool
    {
        return false;
    }
}
