<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Workers\ProcessingCronRegistrar;

/**
 * Heartbeat-gate remediation: nudge WordPress to run the processing-cycle WP-Cron event so a
 * processing heartbeat appears, then let the caller re-poll the gate (ONB-S2 self-remediation;
 * DECISION W (c); DECISION X (4) Option-C; ADR-054 §5/Principle 8).
 *
 * The ONB-S2 "processing pipeline advancing" backfill gate ({@see Backfill\BackfillGate::workerGate()})
 * requires BOTH the processing cron scheduled AND a recent heartbeat. On a zero-config fresh install
 * the schedule exists (activation scheduled it) but no cycle has run yet, so no heartbeat exists.
 * This action makes the gate self-satisfying with zero manual steps (Principle 8): it
 *   (1) ensures the processing-cycle event is scheduled (idempotent — activation already did this),
 *   (2) issues a NON-BLOCKING WP-Cron spawn (`spawn_cron()` — a self-loopback that returns
 *       immediately; WordPress runs the due cron event in a separate request), so the next cron tick
 *       runs one bounded Processing Engine cycle and upserts a heartbeat.
 *
 * CRITICAL — no in-request drain (DECISION W (c); ADR-054 §1). This NEVER runs the worker engine
 * inline: it does not call runCycle(), does not loop, does not sleep. The processing cycle runs ONLY
 * inside WP-Cron execution (the spawned loopback request), exactly as it does in steady state. The
 * wp-admin/onboarding request only triggers the spawn and returns; the operator then re-polls the
 * gate. Remediation references WP-Cron ONLY — never a Restart Workers action, never supervisor /
 * systemd / daemon wording (there is no supervised process under ADR-054 §5).
 *
 * When `DISABLE_WP_CRON` is defined truthy, WP-Cron will not self-fire on page loads (a common
 * production hardening); `spawn_cron()` is a no-op there. The result then carries an explicit
 * warning telling the operator to run WP-Cron out-of-band (`wp cron event run --due-now`), which is
 * the only correct guidance under ADR-054 (still WP-Cron, no daemon).
 *
 * Constructor injection only (ADR-012). Opens no PG handle (DECISION W (e)); it touches only WP-Cron
 * scheduling APIs.
 */
final class WorkerCronSpawner
{
    public function __construct(
        private readonly ProcessingCronRegistrar $cron,
    ) {}

    /**
     * Ensure the processing cron is scheduled and issue a non-blocking WP-Cron spawn. Never runs a
     * cycle inline. Returns what happened + a warning when WP-Cron is disabled.
     */
    public function spawn(): WorkerCronSpawnResult
    {
        // Idempotent: activation scheduled this; re-ensuring is a wp_next_scheduled-guarded no-op.
        $this->cron->ensureScheduled();

        if ($this->wpCronDisabled()) {
            return new WorkerCronSpawnResult(
                spawned: false,
                disabled: true,
                warning: 'WP-Cron is disabled on this site (DISABLE_WP_CRON is set), so background '
                    . 'processing will not start automatically. Run the processing cycle out-of-band '
                    . 'with `wp cron event run ' . ProcessingCronRegistrar::HOOK . '` (or '
                    . '`wp cron event run --due-now`), or point a scheduled task at '
                    . '`wp cron event run --due-now`. The processing cycle runs only inside WP-Cron '
                    . 'execution — nothing else needs to be started.',
            );
        }

        // Non-blocking self-loopback: WordPress runs the due processing-cycle event in a SEPARATE
        // request. This does NOT run a cycle in THIS request (no in-request drain — DECISION W (c)).
        if (function_exists('spawn_cron')) {
            \spawn_cron();
        }

        return new WorkerCronSpawnResult(spawned: true, disabled: false);
    }

    /** True when DISABLE_WP_CRON is defined and truthy — WP-Cron will not self-fire. */
    private function wpCronDisabled(): bool
    {
        return defined('DISABLE_WP_CRON') && (bool) constant('DISABLE_WP_CRON') === true;
    }
}
