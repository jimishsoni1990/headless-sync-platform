<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Preflight;

use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;
use HSP\Core\Onboarding\OnboardingConnectionProbe;

/**
 * Migration-state hard-block check: the migration engine state shows the required core +
 * content migrations applied.
 *
 * DECISION W (f) amendment (v1.22, 2026-07-17): this check is a **backfill prerequisite evaluated
 * in ONB-S2**, NOT part of the ONB-S1b environment preflight. It ships in ONB-S1b (bound + tested)
 * so ONB-S2 reuses it, but it is deliberately excluded from the ONB-S1b PreflightRunner. Same
 * hard-block semantics, same delivery-handle read path — moved to where delivery schema/data
 * readiness actually gates work (immediately before backfill).
 *
 * Read via system.schema_versions (OPEN-8 read path) through the EXISTING delivery handle
 * ({@see OnboardingConnectionProbe} — no fifth handle, no new pg_* wrapper). The pipeline cannot
 * project content until the core system tables (events / queue / processed / aggregate versions)
 * AND every installed module's projection tables exist, so those migrations form the required set.
 * A missing member is a hard block naming exactly what to apply.
 *
 * The required set has two halves (FLAG-P1BS1-1 resolution):
 *   - CORE: the pipeline-critical system migrations, listed in {@see REQUIRED_CORE}. These are
 *     core's own and change only when core changes.
 *   - MODULES: DERIVED at run time from the module registry's declarative getMigrations()
 *     (OPEN-9 / DECISION W (e)) — the same collection the {@see \HSP\Core\Onboarding\MigrationApplier}
 *     applies. Deriving rather than listing means a module that adds a projection table is covered
 *     automatically and never has to edit this core class to stay covered. Rule 5 holds: core
 *     imports no module migration class; it receives MigrationInterface instances the module built.
 *
 * The check matches by the recorded `migration_name` (e.g. `0002_create_system_events`), which
 * is stable and schema-context independent, so it does not depend on the delivery handle's search
 * path. When the DB is unreachable the probe returns an empty applied set and every required
 * migration reads as missing — the remediation then points at running migrations (and implicitly
 * at the reachability check above).
 */
final class MigrationsAppliedCheck implements PreflightCheckInterface
{
    public const KEY = 'migrations_applied';

    /**
     * Required CORE migration names. Kept as the pipeline-critical subset — not every core
     * migration — so the check stays meaningful without being brittle to additive non-critical
     * ones.
     *
     * Module projection tables are NOT listed here: they are derived from the module registry
     * (see the class docblock and {@see requiredNames()}).
     *
     * @var list<string>
     */
    private const REQUIRED_CORE = [
        '0002_create_system_events',
        '0003_create_system_queue_jobs',
        // 0011 adds UNIQUE(event_id) on system.queue_jobs — the dispatcher's ON CONFLICT idempotent
        // enqueue REQUIRES it (DECISION L v1.12); without it the first dispatch fails. Pipeline-critical.
        '0011_add_unique_event_id_to_queue_jobs',
        '0005_create_system_aggregate_versions',
        '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
    ];

    /** @var callable(): list<MigrationInterface> */
    private $resolveModules;

    /**
     * @param callable(): list<MigrationInterface> $resolveModules module-owned migrations,
     *        collected from the module registry's declarative getMigrations() (OPEN-9 / Rule 5 —
     *        core imports no module migration class; the module constructs its own instances).
     *        Resolved LAZILY and defensively: the pgsql migration connection opens libpq eagerly
     *        and throws when PostgreSQL is unreachable, which must read as "nothing applied", not
     *        as a fatal (Principle 8).
     */
    public function __construct(
        private readonly OnboardingConnectionProbe $probe,
        callable $resolveModules,
    ) {
        $this->resolveModules = $resolveModules;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(): PreflightResult
    {
        $applied = $this->probe->appliedMigrationNames();
        $missing = array_values(array_diff($this->requiredNames(), $applied));

        $passed = $missing === [];

        return new PreflightResult(
            self::KEY,
            'Required migrations applied',
            $passed,
            $passed
                ? 'All required core and content migrations are applied.'
                : count($missing) . ' required migration(s) not applied: ' . implode(', ', $missing) . '.',
            $passed
                ? ''
                : 'Run the HSP migration engine to apply the outstanding core and content migrations '
                    . '(ensure PostgreSQL is reachable first).',
        );
    }

    /**
     * The required set: the pipeline-critical CORE migrations plus EVERY migration each installed
     * module declares.
     *
     * Module names are derived rather than listed so a module that adds a projection table never
     * has to edit this core class to stay covered (FLAG-P1BS1-1). Before this, the list was
     * hand-maintained: a module could ship a projection migration, have it applied by the engine,
     * and still leave this gate reporting "passed" on an install where the table was missing —
     * the same shape of gap that let migration 0011 ship unwired (FLAG-ONBS2-1).
     *
     * A module declaring a migration is taken at its word: if the module needs the table, a
     * missing one is a hard block.
     *
     * @return list<string>
     */
    private function requiredNames(): array
    {
        return array_values(array_unique([...self::REQUIRED_CORE, ...$this->moduleMigrationNames()]));
    }

    /**
     * @return list<string>
     */
    private function moduleMigrationNames(): array
    {
        try {
            $migrations = ($this->resolveModules)();
        } catch (\Throwable) {
            // PostgreSQL unreachable (the migration connection opens libpq eagerly), or a module
            // failed to build its list. Either way the core requirements below already fail the
            // check against an empty applied set, so degrade to core-only rather than fatal —
            // activation and the onboarding screen must never 500 on an unconfigured site.
            return [];
        }

        $names = [];
        foreach ($migrations as $migration) {
            $names[] = $migration->getName();
        }

        return $names;
    }
}
