<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Providers;

use HSP\Core\Contracts\Operations\QueueStatus;
use HSP\Core\Contracts\Operations\QueueStatusProviderInterface;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;

/**
 * Current-state queue status provider (OPSC-S2; Doc 12 §12; DECISION Q).
 *
 * Derives queue depth, DLQ depth, and oldest-pending age on demand from the existing
 * system.queue_jobs / system.dead_letter_jobs tables via the delivery-handle reader
 * (DECISION V (g)). ZERO new persistence (DECISION V (c)); read-only.
 */
final class QueueStatusProvider implements QueueStatusProviderInterface
{
    public const KEY = 'queue';

    public function __construct(
        private readonly OperationsQueryReader $reader,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function status(): QueueStatus
    {
        $ageSeconds = $this->reader->oldestPendingAgeSeconds();

        return new QueueStatus(
            depth: $this->reader->queueDepth(),
            deadLetterDepth: $this->reader->deadLetterDepth(),
            oldestPendingAge: $this->intervalFromSeconds($ageSeconds),
        );
    }

    /**
     * Convert a fractional-seconds age into a whole-second DateInterval, or null when there
     * is no pending job. Sub-second precision is dropped (DateInterval holds integer parts);
     * the console displays age at second granularity.
     */
    private function intervalFromSeconds(?float $seconds): ?\DateInterval
    {
        if ($seconds === null) {
            return null;
        }

        $whole = (int) max(0, (int) round($seconds));

        return new \DateInterval('PT' . $whole . 'S');
    }
}
