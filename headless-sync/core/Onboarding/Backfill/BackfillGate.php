<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Backfill;

use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use HSP\Core\Workers\ProcessingCronRegistrar;

/**
 * The two hard-blocking backfill prerequisites (ONB-S2; DECISION W (c)/(f) amended v1.22;
 * realigned to the ADR-054 WP-Cron cycle model by DECISION X ruling (4) — Option C).
 *
 * Before onboarding will trigger the first-run backfill, BOTH must hold:
 *   (1) the PROCESSING PIPELINE is being advanced on a cadence — the DECISION X (4) Option-C
 *       prerequisite. Under ADR-054 there is no supervised worker; the execution path is the
 *       bounded WP-Cron Processing Engine cycle. "A worker is draining" therefore means BOTH
 *       (i) the processing-cycle cron event is SCHEDULED (wp_next_scheduled truthy) AND (ii) a
 *       RECENT processing heartbeat exists — a cycle ran within the same freshness threshold the
 *       Worker Status provider uses (DECISION P age read). A heartbeat alone could be a one-off
 *       manual cycle with no recurring schedule; a scheduled event alone could be firing into a
 *       stalled runtime — together they mean the pipeline is actually being advanced, so the
 *       re-emitted events will drain (there is no in-request tick drain — DECISION W (c)). A
 *       failed gate yields WP-Cron guidance ONLY ("ensure WP-Cron is firing / run
 *       `wp cron event run --due-now`"), NEVER a Restart Workers action and never
 *       supervisor/systemd/daemon wording (DECISION V (f); ADR-054 §5).
 *   (2) the required core + content migrations applied — reusing the ONB-S1b
 *       {@see MigrationsAppliedCheck} (moved here from the environment preflight by the DECISION W
 *       (f) amendment v1.22). The pipeline cannot project content into tables that do not exist.
 *
 * Each unmet gate is a HARD BLOCK with remediation (not a warning). Both DB reads reuse the
 * delivery handle (no new handle/wrapper — DECISION W (e)); connection failure surfaces as a
 * blocked gate with remediation, never an uncaught exception (the reader/probe swallow it). The
 * cron-schedule check is a WordPress wp_next_scheduled read (no DB, no persistence).
 *
 * This class only EVALUATES readiness; it triggers nothing. {@see BackfillService} consults it.
 */
final class BackfillGate
{
    public const GATE_WORKER     = 'worker_heartbeat';
    public const GATE_MIGRATIONS = 'migrations_applied';

    public function __construct(
        private readonly BackfillReader $reader,
        private readonly MigrationsAppliedCheck $migrations,
        private readonly int $heartbeatOfflineAfterSeconds,
    ) {}

    /** True only when BOTH prerequisites pass — the single gate on triggering backfill. */
    public function isReady(): bool
    {
        foreach ($this->gates() as $gate) {
            if (! $gate['passed']) {
                return false;
            }
        }

        return true;
    }

    /**
     * JSON-friendly readiness summary for the onboarding client. Each gate names whether it passed,
     * a human detail, and remediation guidance when blocked (empty when passed).
     *
     * @return array{ready:bool,gates:list<array{key:string,label:string,passed:bool,detail:string,remediation:string}>}
     */
    public function summary(): array
    {
        $gates = $this->gates();
        $ready = true;
        foreach ($gates as $gate) {
            $ready = $ready && $gate['passed'];
        }

        return ['ready' => $ready, 'gates' => $gates];
    }

    /**
     * @return list<array{key:string,label:string,passed:bool,detail:string,remediation:string}>
     */
    private function gates(): array
    {
        return [$this->workerGate(), $this->migrationsGate()];
    }

    /**
     * DECISION X (4) Option-C prerequisite: the processing-cycle cron event is SCHEDULED AND a
     * recent processing heartbeat exists. Both are required — see the class docblock.
     *
     * @return array{key:string,label:string,passed:bool,detail:string,remediation:string}
     */
    private function workerGate(): array
    {
        $scheduled     = $this->processingCronScheduled();
        $age           = $this->reader->freshestHeartbeatAgeSeconds();
        $heartbeatFresh = $age !== null && $age <= $this->heartbeatOfflineAfterSeconds;
        $passed        = $scheduled && $heartbeatFresh;

        if ($passed) {
            $detail = sprintf(
                'The processing cron is scheduled and a processing cycle ran %ds ago.',
                (int) $age,
            );
        } elseif (! $scheduled) {
            $detail = $age === null
                ? 'The processing cron event is not scheduled and no processing cycle has run.'
                : sprintf(
                    'The processing cron event is not scheduled (last cycle heartbeat was %ds ago).',
                    (int) $age,
                );
        } elseif ($age === null) {
            $detail = 'The processing cron is scheduled but no processing cycle has run yet.';
        } else {
            $detail = sprintf(
                'The processing cron is scheduled but the last cycle heartbeat is %ds old '
                    . '(older than the %ds freshness threshold) — cycles are not advancing.',
                (int) $age,
                $this->heartbeatOfflineAfterSeconds,
            );
        }

        return [
            'key'         => self::GATE_WORKER,
            'label'       => 'Processing pipeline advancing',
            'passed'      => $passed,
            'detail'      => $detail,
            'remediation' => $passed
                ? ''
                : 'Ensure WP-Cron is firing so the processing cycle runs on a cadence: confirm the '
                    . '`' . ProcessingCronRegistrar::HOOK . '` cron event is scheduled and run '
                    . '`wp cron event run --due-now` (or trigger a request so WP-Cron fires), then '
                    . 're-check. Backfill drains through the normal WP-Cron processing cycle — there '
                    . 'is no worker daemon to start.',
        ];
    }

    /**
     * True when the processing-cycle WP-Cron event is scheduled (wp_next_scheduled truthy).
     * Outside a WordPress runtime (no wp_next_scheduled) this returns false → the gate blocks
     * with WP-Cron remediation, which is the correct fail-closed behaviour.
     */
    private function processingCronScheduled(): bool
    {
        if (! function_exists('wp_next_scheduled')) {
            return false;
        }

        return \wp_next_scheduled(ProcessingCronRegistrar::HOOK) !== false;
    }

    /**
     * @return array{key:string,label:string,passed:bool,detail:string,remediation:string}
     */
    private function migrationsGate(): array
    {
        $result = $this->migrations->run();

        return [
            'key'         => self::GATE_MIGRATIONS,
            'label'       => $result->label,
            'passed'      => $result->passed,
            'detail'      => $result->detail,
            'remediation' => $result->remediation,
        ];
    }
}
