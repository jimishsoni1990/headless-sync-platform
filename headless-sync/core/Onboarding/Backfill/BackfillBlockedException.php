<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Backfill;

/**
 * Raised when {@see BackfillService::start()} is called while a hard backfill prerequisite is unmet
 * (ONB-S2; DECISION W (c)/(f) — live worker heartbeat / applied migrations).
 *
 * Carries the {@see BackfillGate} summary so the REST boundary can return the per-gate remediation
 * guidance verbatim (worker-status / runbook links — never a Restart Workers action, DECISION V
 * (f)). This is an expected control-flow signal (a blocked gate), not a bug.
 */
final class BackfillBlockedException extends \RuntimeException
{
    /**
     * @param array{ready:bool,gates:list<array{key:string,label:string,passed:bool,detail:string,remediation:string}>} $summary
     */
    public function __construct(
        private readonly array $summary,
    ) {
        parent::__construct('Backfill prerequisites are not met.');
    }

    /**
     * @return array{ready:bool,gates:list<array{key:string,label:string,passed:bool,detail:string,remediation:string}>}
     */
    public function summary(): array
    {
        return $this->summary;
    }
}
