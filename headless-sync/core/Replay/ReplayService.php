<?php

declare(strict_types=1);

namespace HSP\Core\Replay;

use HSP\Core\Contracts\ReplayEmitterInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Orchestrates entity and date-range replay via synthetic re-emission (DECISION T).
 *
 * Replay is projection repair, not event re-enqueue. For each target aggregate the
 * service delegates to a ReplayEmitterInterface, which reads current WordPress state
 * and emits ONE synthetic event through the outbox with a fresh aggregate_version
 * (DECISION 2). Those events flow relay → dispatch → worker and pass the DECISION J
 * stale guard naturally (new version > stored). The service itself writes no
 * projections and never mutates or re-enqueues historical system.events rows.
 *
 * Two modes (Doc 4 §24):
 *   - Entity:     one (aggregate_type, aggregate_id) → one synthetic emit.
 *   - Date range: SELECT DISTINCT (aggregate_type, aggregate_id) FROM system.events
 *                 WHERE created_at IN [from, to) → one synthetic emit per aggregate.
 *
 * Connection (DECISION T point 3 / DECISION L Ruling 0): the date-range discovery read
 * of system.events reuses the EXISTING delivery DatabaseConnectionInterface handle —
 * the same handle the Dispatcher and Resolve-stage already read system.events with. No
 * fifth PG handle is opened; no new raw pg_* wrapper.
 *
 * Traceability (DECISION T point 4): each run assigns one correlation_id shared across
 * all synthetic events, and one causation_id identifying the replay operation.
 *
 * Module isolation (Rule 5): depends only on the core-owned ReplayEmitterInterface;
 * never imports a module.
 */
final class ReplayService
{
    /** @var array<string, ReplayEmitterInterface> aggregate_type → emitter */
    private array $emitterByType = [];

    /**
     * @param DatabaseConnectionInterface  $conn     Existing delivery handle (system.events read).
     * @param iterable<ReplayEmitterInterface> $emitters One or more module emitters.
     */
    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
        iterable $emitters,
    ) {
        foreach ($emitters as $emitter) {
            foreach ($emitter->getSupportedAggregateTypes() as $type) {
                $this->emitterByType[$type] = $emitter;
            }
        }
    }

    /**
     * Entity replay — reproject a single aggregate to its current WordPress state.
     *
     * @throws \InvalidArgumentException if the aggregate type has no registered emitter.
     */
    public function replayEntity(string $aggregateType, string $aggregateId): ReplayResult
    {
        $correlationId = $this->uuidv7();
        $causationId   = $this->uuidv7();

        $emitted = [$this->emitOne($aggregateType, $aggregateId, $correlationId, $causationId)];

        return new ReplayResult($correlationId, $causationId, $emitted);
    }

    /**
     * Date-range replay — reproject every aggregate that has at least one event in the
     * half-open window [from, to). Each distinct aggregate is emitted once, regardless
     * of how many events it has in the window.
     *
     * @throws \InvalidArgumentException if a discovered aggregate type has no emitter.
     */
    public function replayRange(\DateTimeImmutable $from, \DateTimeImmutable $to): ReplayResult
    {
        $correlationId = $this->uuidv7();
        $causationId   = $this->uuidv7();

        $aggregates = $this->discoverAggregatesInRange($from, $to);

        $emitted = [];
        foreach ($aggregates as $aggregate) {
            $emitted[] = $this->emitOne(
                (string) $aggregate['aggregate_type'],
                (string) $aggregate['aggregate_id'],
                $correlationId,
                $causationId,
            );
        }

        return new ReplayResult($correlationId, $causationId, $emitted);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed> One ReplayResult::$emitted row.
     */
    private function emitOne(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): array {
        $emitter = $this->emitterByType[$aggregateType]
            ?? throw new \InvalidArgumentException(
                "No replay emitter registered for aggregate type '{$aggregateType}'."
            );

        $event = $emitter->emitForAggregate($aggregateType, $aggregateId, $correlationId, $causationId);

        return [
            'aggregate_type'    => $aggregateType,
            'aggregate_id'      => $aggregateId,
            'event_type'        => $event->getEventType(),
            'event_id'          => $event->getId(),
            'aggregate_version' => $event->getAggregateVersion(),
        ];
    }

    /**
     * SELECT DISTINCT (aggregate_type, aggregate_id) FROM system.events in [from, to).
     * Read via the existing delivery handle (DECISION T point 3). Ordered for
     * deterministic emit order (and stable tests); ordering has no correctness effect
     * (correct-final-state semantics, DECISION L clause (f)).
     *
     * @return array<int, array<string, mixed>>
     */
    private function discoverAggregatesInRange(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->conn->query(
            "SELECT DISTINCT aggregate_type, aggregate_id
             FROM   system.events
             WHERE  created_at >= $1::timestamptz
               AND  created_at <  $2::timestamptz
             ORDER BY aggregate_type, aggregate_id",
            [
                $from->format('Y-m-d H:i:sP'),
                $to->format('Y-m-d H:i:sP'),
            ],
        );
    }

    /**
     * UUIDv7 for correlation/causation ids (ADR-015, v1.1 canon).
     */
    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tailHex;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
