<?php

declare(strict_types=1);

namespace HSP\Core\Replay;

/**
 * Immutable summary of one replay run (DECISION T).
 *
 * Returned by ReplayService::replayEntity() / replayRange() so the WP-CLI surface can
 * report what happened without re-querying. Carries the shared correlation_id (groups
 * the run) and per-emitted-event details (aggregate identity, chosen event type, the
 * synthetic event_id, and its fresh aggregate_version) for operator traceability.
 */
final class ReplayResult
{
    /**
     * @param string                              $correlationId  Shared across the run.
     * @param string                              $causationId    Identifies the replay operation.
     * @param array<int, array<string, mixed>>    $emitted        One row per synthetic event:
     *        ['aggregate_type','aggregate_id','event_type','event_id','aggregate_version'].
     */
    public function __construct(
        public readonly string $correlationId,
        public readonly string $causationId,
        public readonly array  $emitted,
    ) {}

    public function count(): int
    {
        return count($this->emitted);
    }
}
