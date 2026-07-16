<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;

/**
 * A no-behaviour WpReconciliationSourceInterface for wiring-smoke tests: it supports the three
 * content aggregate types so ReconciliationService constructs cleanly, and returns an empty
 * corpus so any accidental reconcile pass is a no-op rather than a fake repair. Wiring tests
 * only resolve the graph; they do not run reconciliation.
 */
final class NoopReconciliationSource implements WpReconciliationSourceInterface
{
    /** @return string[] */
    public function getSupportedAggregateTypes(): array
    {
        return ['page', 'post', 'category'];
    }

    /** @return string[] */
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        return [];
    }

    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
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
