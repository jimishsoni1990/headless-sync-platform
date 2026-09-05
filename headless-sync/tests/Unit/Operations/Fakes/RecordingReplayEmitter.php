<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\ReplayEmitterInterface;

/**
 * A ReplayEmitterInterface that RECORDS each emit (aggregate type/id + ids) and returns a
 * lightweight synthetic event — without touching WordPress or any database.
 *
 * Used to prove the OPSC-S4 action path is a THIN DELEGATOR: an action executed through
 * OperationsActionService → ReplayWorkerStrategy → ReplayService reaches the emitter (re-emission,
 * the ONLY repair path) and NEVER writes a projection directly. Paired with a write-spy
 * DatabaseConnectionInterface, this makes the "zero direct `content.*` / `system.*` writes"
 * proof observable in a unit test (mirrors the GATE-S3 write-spy).
 */
final class RecordingReplayEmitter implements ReplayEmitterInterface
{
    /** @var array<int, array{aggregate_type:string, aggregate_id:string, correlation_id:string, causation_id:string}> */
    public array $emitted = [];

    /**
     * Aggregate ids to treat as absent from WordPress, so the emit produces a TOMBSTONE
     * (`content.<type>.deleted`) rather than a reprojection — what a real emitter does for an
     * aggregate that no longer exists, and equally what a mistyped id produces.
     *
     * @var list<string>
     */
    public array $absentAggregateIds = [];

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
        $this->emitted[] = [
            'aggregate_type' => $aggregateType,
            'aggregate_id'   => $aggregateId,
            'correlation_id' => $correlationId,
            'causation_id'   => $causationId,
        ];

        $action = in_array($aggregateId, $this->absentAggregateIds, true) ? 'deleted' : 'updated';

        return new SyntheticReplayEvent(
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            eventType: "content.{$aggregateType}.{$action}",
            correlationId: $correlationId,
            causationId: $causationId,
        );
    }
}
