<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

/**
 * Immutable outcome of an onboarding migration-apply run (ONB-S2 self-remediation;
 * DECISION W (f) v1.23).
 *
 * The applier is a THIN DELEGATOR to the existing migration engine ({@see MigrationApplier}); this
 * VO reports what the engine did in JSON-friendly scalars so the onboarding REST endpoint can echo
 * it straight to the React client. `ran` is true when the applier reached the engine (env-preflight
 * passed and no fatal); `error` carries a caught engine/connection failure message when the run
 * could not complete (the endpoint surfaces it as a blocked gate, never a 500).
 */
final class MigrationApplyResult
{
    public function __construct(
        /** True when the migration engine ran to completion (bootstrap + run, no throw). */
        public readonly bool $ran,
        /** How many migrations the engine was asked to apply (core + module delegate list). */
        public readonly int $total,
        /** Caught failure message when the run could not complete; empty on success. */
        public readonly string $error = '',
    ) {}

    /** @return array{ran:bool,total:int,error:string} */
    public function toArray(): array
    {
        return [
            'ran'   => $this->ran,
            'total' => $this->total,
            'error' => $this->error,
        ];
    }
}
