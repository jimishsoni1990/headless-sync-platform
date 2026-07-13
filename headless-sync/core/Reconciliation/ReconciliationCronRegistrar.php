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

        if (\wp_next_scheduled($hook) === false) {
            \wp_schedule_event(time(), $schedule, $hook);
        }
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
