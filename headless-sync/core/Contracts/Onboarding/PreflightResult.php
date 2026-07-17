<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Onboarding;

/**
 * Immutable result of one onboarding preflight check (ONB-S1b; DECISION W (f)).
 *
 * A failed check is a HARD BLOCK on onboarding progression, not a warning — the operator
 * cannot advance/trigger backfill until every check passes. Each result therefore carries
 * actionable {@see $remediation} guidance so a failing check tells the operator exactly what
 * to fix. Plain, JSON-friendly scalars only (no infra handle): the onboarding REST endpoint
 * serializes these directly to the React client.
 */
final class PreflightResult
{
    public function __construct(
        /** Stable machine key for this check (e.g. `pgsql_extension`). */
        public readonly string $key,
        /** Human-readable check name shown in the UI. */
        public readonly string $label,
        /** True when the prerequisite is satisfied. */
        public readonly bool $passed,
        /** One-line current-state detail (what was observed). */
        public readonly string $detail,
        /** Remediation guidance shown when the check fails; empty when it passes. */
        public readonly string $remediation = '',
    ) {}

    /**
     * @return array{key:string,label:string,passed:bool,detail:string,remediation:string}
     */
    public function toArray(): array
    {
        return [
            'key'         => $this->key,
            'label'       => $this->label,
            'passed'      => $this->passed,
            'detail'      => $this->detail,
            'remediation' => $this->remediation,
        ];
    }
}
