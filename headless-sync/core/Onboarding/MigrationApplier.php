<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Migrations\MigrationRunner;

/**
 * Applies the outstanding core + content migrations through the EXISTING migration engine
 * (ONB-S2 self-remediation; DECISION W (e) delegate list; DECISION W (f) v1.23 self-remediating gate;
 * ADR-054 Principle 8 — zero-configuration operation).
 *
 * This is the in-product action behind the ONB-S2 "migrations applied" backfill gate. Before this
 * session the gate was a hard block whose only remediation was an out-of-band CLI/engine run; it is
 * now a hard block WITH a first-run action (Begin setup → apply migrations) — the gate still blocks
 * until the required migrations exist, it just gained a way to satisfy itself in-product with zero
 * manual steps (Principle 8). It is NOT a bypass: nothing is projected until the migrations run and
 * {@see \HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck} re-passes.
 *
 * THIN DELEGATOR (DECISION W (e)): it holds only the EXISTING {@see MigrationRunner} plus the
 * DECISION W (e) delegate list (core migrations + module migrations, collected via the module
 * registry's declarative {@see MigrationInterface} discovery — OPEN-9). It defines NO new engine,
 * NO new DDL, and NO new schema; it runs the frozen migrations exactly as an operator running the
 * engine would. Idempotent: the engine skips already-applied migrations via
 * UNIQUE(migration_name, schema_context), so a re-run applies nothing new (safe to call from both
 * the migrate endpoint and the plugin activate()/upgrade() lifecycle).
 *
 * The migration list is resolved LAZILY through injected closures (identical rationale to
 * {@see OnboardingConnectionProbe}): the pgsql migration connection opens libpq EAGERLY and THROWS
 * when PostgreSQL is unreachable, so building the list at container-resolution time would fire that
 * throw before any guard runs. Resolving on demand — inside {@see apply()}'s try/catch — turns a
 * connect failure into a caught, reported outcome instead of an uncaught 500 / activation fatal.
 * Rule 5 holds: core never imports a module class; module migrations arrive as MigrationInterface
 * instances the module itself constructed (via getMigrations()).
 *
 * Constructor injection only (ADR-012 / Rule 7). Opens no PG handle of its own — the migration
 * engine owns the DDL connection (DECISION E: the migration engine keeps its own DDL abstraction;
 * this delegator reuses it, adds no new pg_* wrapper and no fifth runtime handle).
 *
 * Not `final`: a test double subclass ({@see \HSP\Tests\Unit\Onboarding\SpyMigrationApplier})
 * overrides {@see apply()} to script engine outcomes at the controller boundary without a live PG.
 */
class MigrationApplier
{
    /** @var callable(): list<MigrationInterface> */
    private $resolveCore;

    /** @var callable(): list<MigrationInterface> */
    private $resolveModules;

    /**
     * @param callable(): MigrationRunner            $resolveRunner  resolves the EXISTING engine on
     *        demand (constructing it opens the pgsql schema_versions link, which may throw).
     * @param callable(): list<MigrationInterface>   $resolveCore    the frozen core migration list
     *        (DECISION W (e) delegate list — `migrations.core`).
     * @param callable(): list<MigrationInterface>   $resolveModules module-owned migrations,
     *        collected from the module registry's getMigrations() (Rule 5 — core imports no module).
     */
    public function __construct(
        private $resolveRunner,
        callable $resolveCore,
        callable $resolveModules,
    ) {
        $this->resolveCore    = $resolveCore;
        $this->resolveModules = $resolveModules;
    }

    /**
     * Apply every outstanding core + content migration via the EXISTING engine. Never throws — a
     * connection/engine failure is caught and reported so callers (the migrate endpoint, the
     * activate()/upgrade() lifecycle) can surface it as a blocked gate / silent no-op rather than a
     * 500 or an activation fatal (DECISION W (f) v1.23; Principle 8 — activation must never fatal on
     * an unconfigured site).
     */
    public function apply(): MigrationApplyResult
    {
        try {
            $migrations = $this->delegateList();
            $runner     = ($this->resolveRunner)();

            // Ensure system schema + system.schema_versions exist, then apply pending migrations.
            // Both steps are idempotent (IF NOT EXISTS DDL; UNIQUE-guarded ledger inserts).
            $runner->bootstrap();
            $runner->run($migrations);

            return new MigrationApplyResult(true, count($migrations));
        } catch (\Throwable $e) {
            return new MigrationApplyResult(false, 0, $e->getMessage());
        }
    }

    /**
     * The DECISION W (e) delegate list: core migrations followed by module migrations. Ordering
     * within the engine is by getName() ascending (the engine sorts), so assembly order here only
     * needs to include every migration — the engine orders and skip-guards them.
     *
     * @return list<MigrationInterface>
     */
    private function delegateList(): array
    {
        $core    = ($this->resolveCore)();
        $modules = ($this->resolveModules)();

        return array_values([...$core, ...$modules]);
    }
}
