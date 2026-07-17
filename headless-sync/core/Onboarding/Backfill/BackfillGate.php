<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Backfill;

use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;

/**
 * The two hard-blocking backfill prerequisites (ONB-S2; DECISION W (c)/(f) amended v1.22).
 *
 * Before onboarding will trigger the first-run backfill, BOTH must hold:
 *   (1) a LIVE worker heartbeat — a system.worker_heartbeats row fresher than the same offline
 *       threshold the Worker Status provider uses (DECISION P / DECISION W (c)). Workers are the
 *       execution path; there is no in-request tick drain, so a live worker is mandatory or the
 *       re-emitted events would never drain. A failed gate yields worker-status + runbook guidance
 *       — never a Restart Workers action (DECISION V (f)); the supervisor owns worker lifecycle.
 *   (2) the required core + content migrations applied — reusing the ONB-S1b
 *       {@see MigrationsAppliedCheck} (moved here from the environment preflight by the DECISION W
 *       (f) amendment v1.22). The pipeline cannot project content into tables that do not exist.
 *
 * Each unmet gate is a HARD BLOCK with remediation (not a warning). Both reads reuse the delivery
 * handle (no new handle/wrapper — DECISION W (e)); connection failure surfaces as a blocked gate
 * with remediation, never an uncaught exception (the reader/probe swallow it).
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
     * @return array{key:string,label:string,passed:bool,detail:string,remediation:string}
     */
    private function workerGate(): array
    {
        $age    = $this->reader->freshestHeartbeatAgeSeconds();
        $passed = $age !== null && $age <= $this->heartbeatOfflineAfterSeconds;

        if ($age === null) {
            $detail = 'No worker heartbeat found — no worker process appears to be running.';
        } elseif ($passed) {
            $detail = sprintf('A worker heartbeat was seen %ds ago.', (int) $age);
        } else {
            $detail = sprintf(
                'The most recent worker heartbeat is %ds old (older than the %ds offline threshold).',
                (int) $age,
                $this->heartbeatOfflineAfterSeconds,
            );
        }

        return [
            'key'         => self::GATE_WORKER,
            'label'       => 'Live worker heartbeat',
            'passed'      => $passed,
            'detail'      => $detail,
            'remediation' => $passed
                ? ''
                : 'Start (or restart) the HSP worker via your process supervisor (systemd / '
                    . 'Supervisor / container), then re-check. See the worker operational runbook. '
                    . 'The console cannot start workers — worker lifecycle belongs to the supervisor.',
        ];
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
