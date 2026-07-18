<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Providers;

use HSP\Core\Contracts\Operations\HealthProviderInterface;
use HSP\Core\Contracts\Operations\HealthReport;
use HSP\Core\Contracts\Operations\Severity;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;

/**
 * Current-state health provider (OPSC-S2; ADR-049; ADR-054 §5; Doc 12 §11).
 *
 * Rolls live operational state — database reachability, PROCESSING FRESHNESS (ADR-054 §5), DLQ
 * backlog, queue backlog — into per-component HealthReport DTOs with an ADR-049 Severity.
 * Current state ONLY (ADR-049): every value is derived at read time via the delivery-handle
 * reader (DECISION V (g)); nothing is stored (DECISION V (c)).
 *
 * Processing-freshness signal (ADR-054 §5, NOT daemon liveness): a stale heartbeat is only a
 * problem WHILE the queue is non-empty — "processing stalled: cycles are not advancing the
 * backlog". A stale heartbeat with an EMPTY queue is benign (there is simply nothing to
 * process; a cron cycle that finds an empty pipeline need not have run recently). The engine is
 * a bounded WP-Cron cycle, not a supervised daemon, so this reports "cycles advancing / stalled",
 * never "a worker is offline".
 *
 * The severity mapping is intentionally simple and threshold-driven (thresholds are
 * config-injected, not hardcoded), because this is an MVP observability surface, not an
 * alerting engine.
 */
final class HealthProvider implements HealthProviderInterface
{
    public const KEY = 'health';

    public function __construct(
        private readonly OperationsQueryReader $reader,
        private readonly int $offlineAfterSeconds,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    /** @return HealthReport[] */
    public function reports(): array
    {
        // Database first: if it is unreachable, the remaining reports cannot be derived, so
        // return a single CRITICAL database report rather than letting reads throw upward.
        try {
            $workers = $this->reader->workerHeartbeats();
            $dlqDepth = $this->reader->deadLetterDepth();
            $queueDepth = $this->reader->queueDepth();
        } catch (DatabaseException $e) {
            return [
                new HealthReport(
                    component: 'database',
                    severity: Severity::CRITICAL,
                    summary: 'Delivery PostgreSQL is unreachable.',
                    details: ['error' => $e->getMessage()],
                ),
            ];
        }

        return [
            new HealthReport(
                component: 'database',
                severity: Severity::OK,
                summary: 'Delivery PostgreSQL is reachable.',
            ),
            $this->processingReport($workers, $queueDepth),
            $this->deadLetterReport($dlqDepth),
            $this->queueReport($queueDepth),
        ];
    }

    /**
     * Processing-freshness health (ADR-054 §5 — cycle model, NOT daemon liveness).
     *
     * A "stale" heartbeat is a heartbeat older than the freshness threshold — a processing
     * cycle has not run recently. This is only escalated to ERROR when the queue is
     * NON-EMPTY: a stale heartbeat while work is waiting means "processing stalled: cycles are
     * not advancing the backlog". A stale heartbeat with an empty queue is benign (nothing to
     * process) → OK. No recent cycle at all (no heartbeat row) is a WARNING (the pipeline may
     * never have run), escalated to ERROR only while the queue is non-empty.
     *
     * @param array<int, array{worker_type:string, age_seconds:float}> $cycles
     */
    private function processingReport(array $cycles, int $queueDepth): HealthReport
    {
        $recent = 0;
        $stale  = 0;
        foreach ($cycles as $c) {
            if ($c['age_seconds'] > $this->offlineAfterSeconds) {
                $stale++;
            } else {
                $recent++;
            }
        }

        $queueBacklog = $queueDepth > 0;
        $details      = ['recent' => $recent, 'stale' => $stale, 'queue_depth' => $queueDepth];

        // No cycle has heartbeated recently at all.
        if ($recent === 0) {
            if ($queueBacklog) {
                return new HealthReport(
                    component: 'processing',
                    severity: Severity::ERROR,
                    summary: sprintf(
                        'Processing stalled: no recent cycle while %d job(s) are waiting.',
                        $queueDepth,
                    ),
                    details: $details,
                );
            }

            return new HealthReport(
                component: 'processing',
                severity: Severity::WARNING,
                summary: 'No recent processing cycle (the pipeline is idle — no work is waiting).',
                details: $details,
            );
        }

        // A recent cycle ran. Stale rows alongside it only matter while the queue is backing up.
        if ($stale > 0 && $queueBacklog) {
            return new HealthReport(
                component: 'processing',
                severity: Severity::ERROR,
                summary: sprintf(
                    'Processing stalled: heartbeat stale for %d stage(s) while %d job(s) are waiting.',
                    $stale,
                    $queueDepth,
                ),
                details: $details,
            );
        }

        return new HealthReport(
            component: 'processing',
            severity: Severity::OK,
            summary: sprintf('Processing is advancing (%d recent cycle heartbeat(s)).', $recent),
            details: $details,
        );
    }

    private function deadLetterReport(int $dlqDepth): HealthReport
    {
        return new HealthReport(
            component: 'dead_letter_queue',
            severity: $dlqDepth > 0 ? Severity::WARNING : Severity::OK,
            summary: $dlqDepth > 0
                ? sprintf('%d job(s) in the dead-letter queue.', $dlqDepth)
                : 'Dead-letter queue is empty.',
            details: ['depth' => $dlqDepth],
        );
    }

    private function queueReport(int $queueDepth): HealthReport
    {
        return new HealthReport(
            component: 'queue',
            severity: Severity::OK,
            summary: sprintf('%d job(s) pending.', $queueDepth),
            details: ['depth' => $queueDepth],
        );
    }
}
