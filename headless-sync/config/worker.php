<?php

declare(strict_types=1);

/**
 * Worker runtime configuration skeleton.
 * No business logic permitted (Doc 2 §5).
 *
 * maintenance.recovery_interval_seconds — DECISION R (v1.16): cadence for the
 *   MaintenanceWorkerStrategy visibility-timeout recovery sweep. Config-driven with
 *   a sensible default; the strategy hardcodes no timing. This mirrors the OPEN-4
 *   config-driven-timeout precedent (queue.visibility_timeout).
 */
return [
    'maintenance' => [
        'recovery_interval_seconds' => 30,
        'partitions'                => ['content', 'commerce', 'system'],
    ],
];
