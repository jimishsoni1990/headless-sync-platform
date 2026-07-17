<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Onboarding;

/**
 * One hard-blocking onboarding prerequisite check (ONB-S1b; DECISION W (f)).
 *
 * The five ratified checks (pgsql extension, PG constants defined, PG reachable, required
 * core+content migrations applied, PHP version) each implement this contract. Implementations
 * live in core/Onboarding/; any WordPress/PostgreSQL access they need is delegated to ratified
 * services — the PG-reachable and migration-state checks reuse the delivery
 * DatabaseConnectionInterface (DECISION K; no fifth handle — L Ruling 0; no new pg_* wrapper —
 * DECISION E). Onboarding opens no PG handle of its own (DECISION W (e)).
 *
 * A check must NEVER throw for an expected failure condition (e.g. PG unreachable): it catches
 * and reports the failure as a PreflightResult with remediation, so the runner can present all
 * five results together rather than aborting on the first failure.
 */
interface PreflightCheckInterface
{
    /** Stable machine key (matches the emitted PreflightResult::$key). */
    public function key(): string;

    /** Run the check and report its current-state result. Must not throw for a failed check. */
    public function run(): PreflightResult;
}
