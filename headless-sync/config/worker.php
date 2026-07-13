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
 *
 * reconciliation — DECISION U (v1.19): reconciliation cadence + paging, config-driven.
 *   schedules  — WP-Cron schedule name per mode (hourly / hsp_nightly / hsp_weekly are
 *                the defaults; the cron registrar registers the custom nightly/weekly
 *                intervals). Swapping the trigger to external scheduling requires no
 *                repair-path change.
 *   page_size  — full/window sweep page size (DECISION U D7 — unbounded, paged).
 */
return [
    'maintenance' => [
        'recovery_interval_seconds' => 30,
        'partitions'                => ['content', 'commerce', 'system'],
    ],
    'reconciliation' => [
        'schedules' => [
            'drift'       => 'hourly',
            'incremental' => 'hsp_nightly',
            'full'        => 'hsp_weekly',
        ],
        'page_size' => 500,
    ],
];
