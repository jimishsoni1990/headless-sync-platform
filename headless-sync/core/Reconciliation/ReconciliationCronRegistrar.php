<?php

declare(strict_types=1);

namespace HSP\Core\Reconciliation;

use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;

/**
 * Wires WordPress cron to TRIGGER reconciliation passes (DECISION U point 5 + D5).
 *
 * WP-Cron is authorized for MVP under the CLAUDE.md recovery-jobs carve-out — but only to
 * TRIGGER. The detection scan and all repair re-emission run on the worker-bootstrapped
 * process when the cron callback fires (executor B1); WP-Cron never processes or writes PG.
 *
 * Three ADR-026 cadences → three schedules (cadence names config-driven):
 *   hsp_reconcile_drift        → reconcileDrift()        (hourly)
 *   hsp_reconcile_incremental  → reconcileIncremental()  (nightly)
 *   hsp_reconcile_full         → reconcileFull()         (weekly)
 *
 * The trigger is swappable to systemd/external scheduling later with no repair-path change
 * (the callbacks call the same strategy methods the WP-CLI surface does).
 *
 * Registration adds custom WP-Cron intervals for 'nightly'/'weekly' (WP core only ships
 * hourly/twicedaily/daily), then schedules each event if not already scheduled. All timing
 * is config-driven — no hardcoded cadence at the strategy (mirrors DECISION R).
 */
final class ReconciliationCronRegistrar
{
    public const HOOK_DRIFT       = 'hsp_reconcile_drift';
    public const HOOK_INCREMENTAL = 'hsp_reconcile_incremental';
    public const HOOK_FULL        = 'hsp_reconcile_full';

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config The 'reconciliation' config block (schedules + page size).
     */
    public function __construct(
        private readonly ReconciliationWorkerStrategy $strategy,
        array $config = [],
    ) {
        $this->config = $config;
    }

    /**
     * Register cron intervals, schedule the three events, and bind their callbacks.
     * No-op-safe: re-running does not double-schedule (wp_next_scheduled guard).
     */
    public function register(): void
    {
        if (! function_exists('add_filter')) {
            return; // Not in a WordPress runtime.
        }

        \add_filter('cron_schedules', [$this, 'addSchedules']);

        \add_action(self::HOOK_DRIFT,       [$this, 'runDrift']);
        \add_action(self::HOOK_INCREMENTAL, [$this, 'runIncremental']);
        \add_action(self::HOOK_FULL,        [$this, 'runFull']);

        $this->ensureScheduled(self::HOOK_DRIFT,       $this->scheduleName('drift',       'hourly'));
        $this->ensureScheduled(self::HOOK_INCREMENTAL, $this->scheduleName('incremental', 'hsp_nightly'));
        $this->ensureScheduled(self::HOOK_FULL,        $this->scheduleName('full',        'hsp_weekly'));
    }

    /**
     * Add the 'hsp_nightly' (daily) and 'hsp_weekly' intervals WP core does not ship.
     *
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function addSchedules(array $schedules): array
    {
        $schedules['hsp_nightly'] = ['interval' => DAY_IN_SECONDS,        'display' => 'HSP nightly'];
        $schedules['hsp_weekly']  = ['interval' => 7 * DAY_IN_SECONDS,    'display' => 'HSP weekly'];

        return $schedules;
    }

    public function runDrift(): void
    {
        $this->strategy->reconcileDrift();
    }

    public function runIncremental(): void
    {
        $this->strategy->reconcileIncremental();
    }

    public function runFull(): void
    {
        $this->strategy->reconcileFull();
    }

    private function ensureScheduled(string $hook, string $schedule): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (\wp_next_scheduled($hook) !== false) {
            return;
        }

        // First run is ONE FULL INTERVAL out — never time(). A first run due immediately makes the
        // very first WP-Cron tick after activation run a FULL reconciliation, which re-emits the
        // entire corpus before the operator ever reaches the onboarding page: the first-run backfill
        // then finds everything already projected and degrades to a no-op (DECISION W (b)/(d) — the
        // backfill is the operator-triggered first-run action, not something activation pre-empts).
        // Reconciliation is a periodic BACKSTOP (DECISION U); it has no reason to fire at t=0.
        \wp_schedule_event(time() + $this->intervalFor($schedule), $schedule, $hook);
    }

    /**
     * Seconds in one period of the named WP-Cron schedule, for offsetting the first run.
     *
     * register() adds the `cron_schedules` filter before ensureScheduled() runs, so the custom
     * 'hsp_nightly' / 'hsp_weekly' periods resolve here too. Falls back to one hour when the
     * schedule is unknown (or wp_get_schedules() is unavailable) — any non-zero offset is enough
     * to keep activation from triggering a reconciliation pass.
     */
    private function intervalFor(string $schedule): int
    {
        $fallback = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;

        if (! function_exists('wp_get_schedules')) {
            return $fallback;
        }

        $interval = \wp_get_schedules()[$schedule]['interval'] ?? 0;

        return is_numeric($interval) && (int) $interval > 0 ? (int) $interval : $fallback;
    }

    /** Resolve the configured schedule name for a mode, falling back to the WP default. */
    private function scheduleName(string $mode, string $default): string
    {
        $schedules = $this->config['schedules'] ?? [];

        return is_array($schedules) && isset($schedules[$mode]) && is_string($schedules[$mode])
            ? $schedules[$mode]
            : $default;
    }
}
