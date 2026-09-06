<?php

declare(strict_types=1);

/**
 * Worker runtime configuration skeleton.
 * No business logic permitted (Doc 2 §5).
 *
 * processing — ADR-054 / Doc 8 v2.0 §9/§12 (DECISION X v1.24): the WP-Cron Processing
 *   Engine cycle. All values config-driven, no hardcoded batch/budget in the engine
 *   (DECISION R config-driven precedent). NO schema change — these are runtime knobs only.
 *   relay_batch_size          — max wp_hsp_outbox rows relayed per cycle.
 *   dispatch_batch_size       — max system.events rows enqueued per cycle.
 *   projection_batch_size     — max system.queue_jobs claimed + projected per cycle.
 *   cycle_time_budget_seconds — soft execution-time budget; the cycle stops claiming new
 *                               work once elapsed time reaches this, finishes the in-flight
 *                               event's single DECISION 3 transaction, and exits cleanly.
 *                               Set WELL INSIDE the environment's PHP max_execution_time.
 *   schedule                  — WP-Cron schedule name for the recurring processing-cycle
 *                               event (defaults to WP core 'hsp_processing' custom interval;
 *                               the cron registrar registers the interval).
 *   interval_seconds          — interval (seconds) for the custom 'hsp_processing' schedule.
 *                               DEFAULT 20 per DECISION AB (FLAG-P1BS0-1 ruling, 2026-09-06):
 *                               latency = (wait for the next cycle) + (cycle duration), and the
 *                               cycle is ~0.06s for one edit / ~6–9s for a saturated 200-batch, so
 *                               this interval IS the sync latency. 20s puts the worst case at
 *                               ~20.1s (saturated ~26–29s) inside the PRD <30s SLA. A change here
 *                               takes effect on the next firing — wp_reschedule_event() looks the
 *                               interval up by schedule name; no migration, no re-scheduling.
 *                               OPERATOR OBLIGATION: WordPress's own request-triggered cron will
 *                               not spawn more often than WP_CRON_LOCK_TIMEOUT (60s core default,
 *                               enforced in spawn_cron()), so this interval ALONE does not deliver
 *                               <30s. The SLA requires an out-of-band trigger running
 *                               `wp cron event run --due-now` at <= 20s (WP-CLI defines DOING_CRON
 *                               and bypasses the spawn lock). Without one the platform still runs
 *                               with zero configuration (Principle 8) — only the <30s SLA is
 *                               unmet. Recipe: docs/notes/PERFORMANCE-BRIEF.md.
 *
 * maintenance — under ADR-054 the MaintenanceWorkerStrategy sweep runs once per WP-Cron cycle
 *   (the cron cadence IS the maintenance cadence — DECISION X); the strategy no longer
 *   self-throttles. The old recovery_interval_seconds throttle key is REMOVED (superseded per
 *   DECISION X — it was inert after ALIGN-S1 and is not read anywhere).
 *   heartbeat_retention_seconds — how much per-cycle heartbeat history the sweep keeps.
 *
 * reconciliation — DECISION U (v1.19): reconciliation cadence + paging, config-driven.
 *   schedules  — WP-Cron schedule name per mode (hourly / hsp_nightly / hsp_weekly are
 *                the defaults; the cron registrar registers the custom nightly/weekly
 *                intervals). Swapping the trigger to external scheduling requires no
 *                repair-path change.
 *   page_size  — full/window sweep page size (DECISION U D7 — unbounded, paged).
 *
 * heartbeat — DECISION P (v1.16) crash detection is by last_heartbeat_at age. The
 *   Operations Console worker-status provider (OPSC-S2) computes "offline" as a
 *   heartbeat-age comparison at read time; the threshold is config-driven (following the
 *   DECISION R config-driven-cadence precedent — no hardcoded timing). This is a read-time
 *   derivation only; it adds NO schema and NO persistence (DECISION Q / DECISION V (c)).
 *   offline_after_seconds — a worker whose last heartbeat is older than this is "offline".
 */
return [
    'processing' => [
        'relay_batch_size'          => 200,
        'dispatch_batch_size'       => 200,
        'projection_batch_size'     => 200,
        'cycle_time_budget_seconds' => 20,
        'schedule'                  => 'hsp_processing',
        'interval_seconds'          => 20,   // DECISION AB — see the note above.
    ],
    'maintenance' => [
        'partitions' => ['content', 'commerce', 'system'],
        // Retention for the per-cycle rows in system.worker_heartbeats. Each ADR-054 cycle mints a
        // fresh worker_id (DECISION X (1)), so the table grows by one row per cycle; the sweep
        // deletes rows older than this. Must stay well above the console's freshness threshold and
        // its cycles-completed window so pruning never removes a row still being displayed.
        'heartbeat_retention_seconds' => 86400,
    ],
    'reconciliation' => [
        'schedules' => [
            'drift'       => 'hourly',
            'incremental' => 'hsp_nightly',
            'full'        => 'hsp_weekly',
        ],
        'page_size' => 500,
    ],
    'heartbeat' => [
        'offline_after_seconds' => 60,
    ],
    'console' => [
        // Trailing window (seconds) for the derived processing-rate metric (jobs
        // completed per minute). Point-in-time derivation only — no persistence
        // (DECISION Q / DECISION V (c)); config-driven so the horizon is never hardcoded.
        'processing_rate_window_seconds' => 300,
    ],
];
