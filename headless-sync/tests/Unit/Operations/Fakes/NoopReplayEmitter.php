<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\ReplayEmitterInterface;

/**
 * A no-behaviour ReplayEmitterInterface for wiring-smoke tests: it supports the three content
 * aggregate types so ReplayService constructs cleanly, but is never invoked (wiring tests only
 * resolve the graph, they do not run actions). If emitForAggregate() is ever called it throws,
 * so an accidental live invocation surfaces loudly instead of silently faking a repair.
 */
final class NoopReplayEmitter implements ReplayEmitterInterface
{
    /** @return string[] */
    public function getSupportedAggregateTypes(): array
    {
        return ['page', 'post', 'category'];
    }

    public function emitForAggregate(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): EventInterface {
        throw new \LogicException('NoopReplayEmitter must not be invoked in a wiring-smoke test.');
    }
}
