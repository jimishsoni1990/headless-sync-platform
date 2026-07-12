<?php

declare(strict_types=1);

namespace HSP\Core\Cli;

use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Replay\ReplayResult;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;

/**
 * WP-CLI surface for entity and date-range replay: `hsp replay entity|range` (DECISION T).
 *
 * WP-CLI is the only operational surface for replay (consistent with DECISION S clause (d):
 * no admin UI, sidestepping the still-TBD WPCS decision at the WP admin boundary).
 *
 * Design (ADR-012, minimal WP coupling): the command depends only on ReplayWorkerStrategy
 * (which owns the ReplayService delegation) and a StructuredLogger. Each subcommand returns
 * a plain ReplayResult; the WP-CLI registration shim formats it. This keeps replay testable
 * without a WP-CLI runtime.
 *
 * On success each run emits the `replay` runtime counter as a structured log event
 * (DECISION Q clause 2), count = number of synthetic events emitted. Replay runs via
 * WP-CLI, outside the WorkerEngine tick loop, so the emission lives here.
 */
final class ReplayCommand
{
    public function __construct(
        private readonly ReplayWorkerStrategy $replay,
        private readonly StructuredLogger     $logger,
    ) {}

    /**
     * `hsp replay entity <aggregate_type> <aggregate_id>` — reproject one aggregate to
     * current WordPress state (DECISION T entity mode).
     */
    public function entity(string $aggregateType, string $aggregateId): ReplayResult
    {
        $result = $this->replay->replayEntity($aggregateType, $aggregateId);
        $this->emitMetric('entity', $result);

        return $result;
    }

    /**
     * `hsp replay range <from> <to>` — reproject every aggregate with an event in the
     * half-open window [from, to) (DECISION T date-range mode).
     *
     * @param string $from Any strtotime/ISO8601-parseable UTC datetime.
     * @param string $to   Any strtotime/ISO8601-parseable UTC datetime (exclusive bound).
     *
     * @throws \InvalidArgumentException on unparseable or inverted bounds.
     */
    public function range(string $from, string $to): ReplayResult
    {
        $fromDt = $this->parseUtc($from, 'from');
        $toDt   = $this->parseUtc($to, 'to');

        if ($fromDt >= $toDt) {
            throw new \InvalidArgumentException(
                "Replay range 'from' ({$from}) must be strictly before 'to' ({$to})."
            );
        }

        $result = $this->replay->replayRange($fromDt, $toDt);
        $this->emitMetric('range', $result);

        return $result;
    }

    private function emitMetric(string $mode, ReplayResult $result): void
    {
        // DECISION Q: runtime counters emitted as structured log events. One replay run
        // increments the `replay` counter by the number of synthetic events emitted.
        $this->logger->metric('replay', [
            'mode'           => $mode,
            'correlation_id' => $result->correlationId,
            'causation_id'   => $result->causationId,
            'replay'         => $result->count(),
        ]);
    }

    private function parseUtc(string $value, string $label): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Replay range '{$label}' value '{$value}' is not a valid datetime: " . $e->getMessage()
            );
        }
    }
}
