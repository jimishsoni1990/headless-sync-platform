<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Module\ModuleBootstrapState;
use HSP\Core\Module\ModuleDiscovery;
use HSP\Core\Module\ModuleLifecycleCoordinator;
use HSP\Core\Module\ModuleLifecycleRunner;
use HSP\Core\Module\ModuleLoader;
use HSP\Core\Module\ModuleRegistrar;
use HSP\Core\Module\ModuleRegistry;
use HSP\Core\Module\ModuleVersionRecorder;
use HSP\Core\Onboarding\OnboardingConnectionProbe;

/**
 * Registers the module infrastructure (discovery, registry, registrar) in the DI container.
 *
 * Bindings:
 *   'module.discovery'  — ModuleDiscovery
 *   'module.loader'     — ModuleLoader
 *   'module.registry'   — ModuleRegistry
 *   'module.registrar'  — ModuleRegistrar
 *
 * Constructor injection only — ADR-012.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function __construct(private readonly string $modulesBasePath) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('module.discovery', fn() =>
            new ModuleDiscovery($this->modulesBasePath)
        );

        $container->singleton('module.loader', fn(Container $c) =>
            new ModuleLoader($c)
        );

        $container->singleton('module.registry', fn(Container $c) =>
            new ModuleRegistry(
                $c->get('module.discovery'),
                $c->get('module.loader'),
                // Availability decided at composition time by ModuleProviderComposer
                // (DECISION AG AG-12); absent in contexts that build the container by hand.
                $c->has('module.available_names') ? $c->get('module.available_names') : null,
            )
        );

        $container->singleton('module.registrar', fn(Container $c) =>
            new ModuleRegistrar($c->get('module.registry'))
        );

        // ---------------------------------------------------------------------
        // DECISION AG (AG-6, AG-12) — module readiness, version recording and the
        // data-bootstrap lifecycle. This is the seam that lets WooCommerce be installed
        // AFTER HSP and still converge its existing catalog with no reactivation, no manual
        // migrate and no manual reconcile. It lives in core, never inside a module provider.
        // ---------------------------------------------------------------------

        $container->singleton(ModuleBootstrapState::class, fn() => new ModuleBootstrapState());

        // Writes system.module_versions over the migration engine's own DDL connection: the
        // write belongs to the migration lifecycle, so the four runtime handles stay untouched
        // (DECISION L Ruling 0) and no new pg_* wrapper appears (DECISION E).
        $container->singleton(ModuleVersionRecorder::class, fn(Container $c) =>
            new ModuleVersionRecorder($c->get('migration.connection.pgsql'))
        );

        // The piece that makes AG-12's automatic activation transition actually happen. The
        // coordinator decides what state a module is IN; nothing invoked it, so a site where
        // WooCommerce was activated after HSP never advanced past DISCOVERED. Every dependency
        // is a lazy closure: resolving this binding on a normal request must not reach
        // PostgreSQL, and must not fatal on a site that has none configured.
        $container->singleton(ModuleLifecycleRunner::class, fn(Container $c) =>
            new ModuleLifecycleRunner(
                $c->get(ModuleLifecycleCoordinator::class),
                $c->get(ModuleBootstrapState::class),
                static fn (): array => $c->get('module.registry')->all(),
                static function () use ($c): array {
                    // The EXISTING shared engine — AG-12 forbids a second migration system.
                    $result = $c->get(\HSP\Core\Onboarding\MigrationApplier::class)->apply();

                    return ['ran' => $result->ran, 'error' => $result->error];
                },
                static function (array $aggregateTypes) use ($c): void {
                    // The ratified re-emission path (DECISION T/U), scoped to this module's
                    // aggregates. Never a direct WordPress->PostgreSQL copy.
                    $c->get(\HSP\Core\Reconciliation\ReconciliationService::class)
                        ->reconcile(\HSP\Core\Reconciliation\ReconciliationService::MODE_FULL, false, $aggregateTypes);
                },
                static function (string $message): void {
                    error_log($message);
                },
            ));
        $container->singleton(ModuleLifecycleCoordinator::class, fn(Container $c) =>
            new ModuleLifecycleCoordinator(
                $c->get(ModuleBootstrapState::class),
                // Readiness reads system.schema_versions — the AUTHORITATIVE migration-state
                // record. AG-6 forbids inferring it from system.module_versions.
                static function (string $moduleName) use ($c): bool {
                    $module = $c->get('module.registry')->get($moduleName);
                    if ($module === null) {
                        return false;
                    }

                    $declared = [];
                    foreach ($module->getMigrations() as $migration) {
                        $declared[] = $migration->getName();
                    }

                    if ($declared === []) {
                        return true; // a module owning no schema is ready as soon as it loads
                    }

                    $applied = $c->get(OnboardingConnectionProbe::class)->appliedMigrationNames();

                    return array_diff($declared, $applied) === [];
                },
                static function (string $moduleName, string $schemaVersion) use ($c): void {
                    // Never allowed to break a page load: an unreachable database is normal
                    // on an unconfigured site (ADR-054 Principle 8).
                    try {
                        $c->get(ModuleVersionRecorder::class)->record($moduleName, $schemaVersion);
                    } catch (\Throwable) {
                        // Recording is metadata, not correctness — schema_versions remains
                        // the authoritative record either way.
                    }
                },
            )
        );
    }
}
