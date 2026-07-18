<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

use HSP\Core\Contracts\WorkerInterface;

/**
 * Registers the WP-Cron event that TRIGGERS the Processing Engine cycle (ADR-054 / Doc 8 v2.0
 * §1/§23/§24). WP-Cron is the ONLY v1.x execution mechanism — there is no daemon, no
 * supervisor. Each firing of the event runs exactly ONE bounded Processing Engine cycle and
 * exits (WorkerInterface::runCycle()); a backlog larger than one cycle is continued by the
 * next firing (Doc 8 v2.0 §12).
 *
 * Follows the ReconciliationCronRegistrar precedent exactly: register a custom cron interval,
 * schedule the recurring event if not already scheduled (wp_next_scheduled guard), and bind
 * the callback. All timing is config-driven (no hardcoded cadence — mirrors DECISION R).
 *
 * For reliable cadence, an operator points a system cron / scheduled task at
 * `wp cron event run --due-now` (a TRIGGER for WP-Cron, not a daemon — ADR-054 §23); each
 * invocation still runs one bounded cycle and exits.
 *
 * Concurrency: overlapping cycles are safe via existing guarantees only (SKIP LOCKED +
 * aggregate versioning + visibility timeout + DECISION 3 atomic commit) — ADR-054 §3 adds no
 * lock, so the registrar needs no single-flight guard.
 */
final class ProcessingCronRegistrar
{
    public const HOOK = 'hsp_processing_cycle';

    /** Default custom-interval schedule name if none is configured. */
    private const DEFAULT_SCHEDULE = 'hsp_processing';

    /** Default custom-interval period (seconds). */
    private const DEFAULT_INTERVAL_SECONDS = 60;

    /** @var array<string,mixed> The 'processing' config block. */
    private array $config;

    /**
     * HOTFIX: the engine is resolved LAZILY via a `\Closure(): WorkerInterface`, not
     * injected as a built instance. register() binds the WP-Cron hook and schedules the
     * event WITHOUT constructing the engine — so a normal wp-admin request never builds
     * the Processing Engine (and never opens the outbox MySQL connection at plugins_loaded).
     * The engine is materialised only when the cron callback actually fires (runCycle()),
     * i.e. in the cron/CLI execution context. This preserves ADR-054's requirement that
     * the schedule exists and the callback is bound on every request (Doc 8 v2.0 §23),
     * while removing the eager engine/connection construction that fataled page loads.
     *
     * @param \Closure(): WorkerInterface $engineResolver Resolves the bounded-cycle engine
     *        from the container on demand. Invoked only inside runCycle().
     * @param array<string,mixed>         $config         The 'processing' config block
     *        (schedule + interval).
     */
    public function __construct(
        private readonly \Closure $engineResolver,
        array $config = [],
    ) {
        $this->config = $config;
    }

    /**
     * Register the custom interval, schedule the recurring processing-cycle event, and bind
     * its callback. No-op-safe: re-running does not double-schedule (wp_next_scheduled guard).
     */
    public function register(): void
    {
        if (! function_exists('add_filter')) {
            return; // Not in a WordPress runtime.
        }

        \add_filter('cron_schedules', [$this, 'addSchedule']);
        \add_action(self::HOOK, [$this, 'runCycle']);

        $this->ensureScheduled();
    }

    /**
     * Add the custom 'hsp_processing' interval WP core does not ship.
     *
     * @param array<string,array<string,mixed>> $schedules
     * @return array<string,array<string,mixed>>
     */
    public function addSchedule(array $schedules): array
    {
        $schedules[$this->scheduleName()] = [
            'interval' => $this->intervalSeconds(),
            'display'  => 'HSP processing cycle',
        ];

        return $schedules;
    }

    /**
     * The WP-Cron callback: run exactly ONE bounded Processing Engine cycle and exit.
     * Never loops, never sleeps (ADR-054 §1).
     */
    public function runCycle(): void
    {
        // Materialise the engine only now, when the cron event actually fires — this is
        // the first point the outbox MySQL connection is legitimately needed.
        ($this->engineResolver)()->runCycle();
    }

    /**
     * Schedule the recurring event on the plugin activation path (or lazily on register).
     * Public so Application::activate() can schedule it explicitly.
     */
    public function ensureScheduled(): void
    {
        if (! function_exists('wp_next_scheduled') || ! function_exists('wp_schedule_event')) {
            return;
        }

        if (\wp_next_scheduled(self::HOOK) === false) {
            \wp_schedule_event(time(), $this->scheduleName(), self::HOOK);
        }
    }

    /** Clear the scheduled processing-cycle event (deactivation path). */
    public function clearScheduled(): void
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            \wp_clear_scheduled_hook(self::HOOK);
        }
    }

    private function scheduleName(): string
    {
        $name = $this->config['schedule'] ?? self::DEFAULT_SCHEDULE;

        return is_string($name) && $name !== '' ? $name : self::DEFAULT_SCHEDULE;
    }

    private function intervalSeconds(): int
    {
        $interval = (int) ($this->config['interval_seconds'] ?? self::DEFAULT_INTERVAL_SECONDS);

        return $interval > 0 ? $interval : self::DEFAULT_INTERVAL_SECONDS;
    }
}
