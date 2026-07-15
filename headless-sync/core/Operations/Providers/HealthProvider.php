<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Providers;

use HSP\Core\Contracts\Operations\HealthProviderInterface;
use HSP\Core\Contracts\Operations\HealthReport;
use HSP\Core\Contracts\Operations\Severity;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;

/**
 * Current-state health provider (OPSC-S2; ADR-049; Doc 12 §11).
 *
 * Rolls live operational state — database reachability, worker liveness (DECISION P), DLQ
 * backlog, queue backlog — into per-component HealthReport DTOs with an ADR-049 Severity.
 * Current state ONLY (ADR-049): every value is derived at read time via the delivery-handle
 * reader (DECISION V (g)); nothing is stored (DECISION V (c)).
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
            $this->workersReport($workers),
            $this->deadLetterReport($dlqDepth),
            $this->queueReport($queueDepth),
        ];
    }

    /**
     * @param array<int, array{worker_type:string, age_seconds:float}> $workers
     */
    private function workersReport(array $workers): HealthReport
    {
        $total = count($workers);
        $offline = 0;
        foreach ($workers as $w) {
            if ($w['age_seconds'] > $this->offlineAfterSeconds) {
                $offline++;
            }
        }

        $details = ['total' => $total, 'offline' => $offline];

        if ($total === 0) {
            return new HealthReport(
                component: 'workers',
                severity: Severity::WARNING,
                summary: 'No workers have published a heartbeat.',
                details: $details,
            );
        }

        if ($offline > 0) {
            return new HealthReport(
                component: 'workers',
                severity: Severity::ERROR,
                summary: sprintf('%d of %d workers are offline (stale heartbeat).', $offline, $total),
                details: $details,
            );
        }

        return new HealthReport(
            component: 'workers',
            severity: Severity::OK,
            summary: sprintf('%d worker(s) online.', $total),
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
