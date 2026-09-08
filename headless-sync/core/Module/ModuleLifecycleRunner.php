<?php

declare(strict_types=1);

namespace HSP\Core\Module;

/**
 * Drives the AG-12 module lifecycle to completion — the piece that was missing.
 *
 * {@see ModuleLifecycleCoordinator} decides what state a module is IN. Nothing invoked it, so a
 * site where WooCommerce was activated after HSP never advanced: a live install was found with
 * onboarding complete, Content fully synced, eighteen products in WooCommerce, and no `commerce`
 * schema in PostgreSQL at all. AG-12 requires exactly that case to converge with "no reactivation,
 * no manual migrate and no manual reconcile"; all three were needed. This class is what makes the
 * transition actually happen.
 *
 * The whole sequence, per module:
 *
 *     complete?            → nothing to do, and no PostgreSQL round trip paid
 *     available but not ready
 *                          → apply pending migrations through the EXISTING engine
 *     ready, bootstrap pending
 *                          → module-scoped ReconciliationService re-emission
 *                          → mark complete
 *
 * WHERE THIS RUNS: inside the bounded WP-Cron processing cycle. ADR-054 makes WP-Cron the only
 * v1.x execution mechanism, and the cycle is where the platform already does background work with
 * a PostgreSQL connection in hand — so a newly available module converges within one cycle and no
 * new execution path is introduced. Running it on `plugins_loaded` instead would put a PostgreSQL
 * round trip on every page load, which is precisely what the delivery-path rules forbid.
 *
 * AUTHORISED BY AG-12, not invented here: *"P2-S1 is authorised to fix the engine if module
 * migrations can only run at plugin activation; no second migration system."* That was the exact
 * condition found. Migrations go through the shared applier, bootstrap goes through the ratified
 * ReconciliationService re-emission path (DECISION T/U) — no direct WordPress→PostgreSQL copy, no
 * second repair path, no in-request queue drain, and global onboarding is never reset.
 *
 * NOTHING HERE MAY FATAL. It runs inside a cron cycle on sites that may have PostgreSQL
 * misconfigured or unreachable, and a module that cannot advance must leave every other module
 * running (AG-12, ADR-054 Principle 8). Every step is wrapped.
 */
final class ModuleLifecycleRunner
{
    /** @var list<string> modules already advanced in THIS request — the cycle runs once, but be safe. */
    private array $seen = [];

    /**
     * @param \Closure(): array<string, \HSP\Core\Contracts\ModuleInterface> $resolveModules
     *        The registered modules, resolved lazily so constructing this class opens nothing.
     * @param \Closure(): array{ran: bool, error: string} $applyMigrations Attempts pending
     *        migrations through the shared engine and reports what happened. A closure rather
     *        than the applier itself because building the applier reaches the DDL link, which
     *        throws on an unconfigured site.
     * @param \Closure(list<string>): void $reconcileScoped Converges a module by re-emitting the
     *        named aggregate types through ReconciliationService.
     * @param \Closure(string): void|null $log Optional diagnostic sink.
     */
    public function __construct(
        private readonly ModuleLifecycleCoordinator $coordinator,
        private readonly ModuleBootstrapState $state,
        private readonly \Closure $resolveModules,
        private readonly \Closure $applyMigrations,
        private readonly \Closure $reconcileScoped,
        private readonly ?\Closure $log = null,
    ) {
    }

    /**
     * Advance every registered module by at most one step.
     *
     * Idempotent and cheap in the steady state: a converged module costs one WordPress option
     * read and returns immediately.
     */
    public function run(): void
    {
        try {
            $modules = ($this->resolveModules)();
        } catch (\Throwable $e) {
            $this->note('cannot resolve modules: ' . $e->getMessage());

            return;
        }

        foreach ($modules as $module) {
            try {
                $this->advance($module);
            } catch (\Throwable $e) {
                // One module's failure must never stop the others (AG-12).
                $this->note(sprintf('module lifecycle failed: %s', $e->getMessage()));
            }
        }
    }

    private function advance(\HSP\Core\Contracts\ModuleInterface $module): void
    {
        $name = $module->getName();

        if ($name === '' || in_array($name, $this->seen, true)) {
            return;
        }

        // The cheap exit, taken on essentially every cycle once a site has settled: a converged
        // module is answered from a WordPress option with no PostgreSQL contact at all.
        if ($this->state->isComplete($name)) {
            return;
        }

        $this->seen[] = $name;

        $schemaVersion = $this->schemaVersionOf($module);
        $status        = $this->coordinator->evaluate($name, $schemaVersion);

        if (! $status->ready) {
            // Available but not ready means its migrations have not applied. THIS is the gap
            // AG-12 authorised correcting: before, module migrations only ran from
            // Application::activate()/upgrade(), so a module that became available later never
            // got its schema and never projected anything.
            $applied = ($this->applyMigrations)();

            if (($applied['ran'] ?? false) !== true) {
                $this->note(sprintf(
                    "module '%s' is not ready and migrations did not run: %s",
                    $name,
                    $applied['error'] ?? 'unknown',
                ));

                return; // Try again next cycle; never fatal, never a partial bootstrap.
            }

            $status = $this->coordinator->evaluate($name, $schemaVersion);

            if (! $status->ready) {
                $this->note(sprintf("module '%s' still not ready after applying migrations", $name));

                return;
            }

            $this->note(sprintf("module '%s' became READY — migrations applied", $name));
        }

        if ($status->bootstrapState !== ModuleBootstrapState::PENDING) {
            return;
        }

        // Ready and owing a bootstrap: converge its EXISTING source data. Scoped to this
        // module's aggregates so a second module's arrival does not re-scan the first
        // (AG-12: "module-scoped ReconciliationService re-emission").
        $aggregates = $this->aggregateTypesOf($module);

        if ($aggregates === []) {
            // A module owning no aggregates has nothing to converge.
            $this->coordinator->markBootstrapComplete($name);

            return;
        }

        ($this->reconcileScoped)($aggregates);

        // Marked complete once the re-emission has been ISSUED. The events then drain through
        // the ordinary pipeline over the following cycles — deliberately not waited on, because
        // an in-request drain is exactly what AG-12 forbids.
        $this->coordinator->markBootstrapComplete($name);

        $this->note(sprintf(
            "module '%s' bootstrap re-emitted for [%s] and marked complete",
            $name,
            implode(', ', $aggregates),
        ));
    }

    /**
     * The module's aggregate types, derived from its declared event vocabulary.
     *
     * OPEN-1 fixes the event shape as `<domain>.<aggregate>.<action>`, so the second segment is
     * the aggregate type — the same derivation the event providers use. That makes the module's
     * own `getEventTypes()` the module→aggregate mapping, with no new contract and nothing for a
     * future module to forget to register.
     *
     * @return list<string>
     */
    private function aggregateTypesOf(\HSP\Core\Contracts\ModuleInterface $module): array
    {
        $types = [];

        foreach ($module->getEventTypes() as $eventType) {
            $segment = explode('.', (string) $eventType)[1] ?? '';

            if ($segment !== '' && ! in_array($segment, $types, true)) {
                $types[] = $segment;
            }
        }

        return $types;
    }

    private function schemaVersionOf(\HSP\Core\Contracts\ModuleInterface $module): string
    {
        // ModuleInterface does not carry the manifest's schema_version, and AG-6 wants the
        // DECLARED value recorded. Modules that expose it are asked; the rest fall back to a
        // stable placeholder rather than blocking the lifecycle on a metadata detail.
        if (method_exists($module, 'getSchemaVersion')) {
            $version = $module->getSchemaVersion();

            if (is_string($version) && $version !== '') {
                return $version;
            }
        }

        return '1.0.0';
    }

    private function note(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)('[HSP] module lifecycle: ' . $message);
        }
    }
}
