<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\Version;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Migrations\MigrationRunner;
use HSP\Core\Module\ModuleRegistry;
use HSP\Core\Onboarding\Backfill\BackfillGate;
use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Onboarding\Backfill\BackfillService;
use HSP\Core\Onboarding\MigrationApplier;
use HSP\Core\Onboarding\OnboardingAdminRegistrar;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Core\Onboarding\OnboardingPageController;
use HSP\Core\Onboarding\OnboardingRestController;
use HSP\Core\Onboarding\OnboardingRestRegistrar;
use HSP\Core\Onboarding\OnboardingState;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use HSP\Core\Onboarding\Preflight\PgConstantsCheck;
use HSP\Core\Onboarding\Preflight\PgReachableCheck;
use HSP\Core\Onboarding\Preflight\PgsqlExtensionCheck;
use HSP\Core\Onboarding\Preflight\PhpVersionCheck;
use HSP\Core\Onboarding\PreflightRunner;
use HSP\Core\Onboarding\WorkerCronSpawner;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Workers\ProcessingCronRegistrar;

/**
 * Registers the Onboarding / First-Run surface (ONB-S1a + ONB-S1b; DECISION W (a)/(d)/(e)/(f);
 * DECISION V (b); DECISION K/L Ruling 0/E; ADR-012; Rule 5).
 *
 * ONB-S1a bindings (page skeleton + mount seam):
 *   OnboardingPageController  — the wp-admin boundary: registers ONE capability-gated page,
 *                               renders the React mount shell, enqueues the committed dist/ bundle.
 *   OnboardingAdminRegistrar  — thin hook registrar (admin_menu + admin_enqueue_scripts).
 *
 * ONB-S1b bindings (preflight + completion flag + REST):
 *   OnboardingStateInterface  — the `hsp_onboarding_state` WP-option round-trip (DECISION W (d)).
 *                               A single MySQL option; NO schema change, NO PG handle. Bound to
 *                               the interface so the Operations nav gate depends on the contract.
 *   OnboardingConnectionProbe — read-only PG probe reusing the EXISTING delivery
 *                               DatabaseConnectionInterface (DECISION K) — no fifth handle
 *                               (L Ruling 0), no new pg_* wrapper (E). Onboarding opens no handle.
 *   Four preflight checks + PreflightRunner — the four hard-blocking environment prerequisite checks
 *                               (DECISION W (f), amended v1.22): pgsql extension, PG constants, PG
 *                               reachable (delegates to the probe), PHP version. The migration-state
 *                               check (`MigrationsAppliedCheck`) is bound for ONB-S2 reuse but is
 *                               NOT in this runner.
 *   OnboardingRestController / OnboardingRestRegistrar — the WPCS-guarded JSON endpoints the
 *                               React app calls (nonce + capability + sanitize — DECISION W (a) /
 *                               V (b)); delegate-only, no infra.
 *
 * Placement is core/Onboarding/ (NOT core/Operations/) so the observability-only console
 * (DECISION V (j)) is untouched (DECISION W (e)). This provider touches NO schema and opens NO PG
 * handle of its own — the probe rides the delivery handle registered by DeliveryServiceProvider.
 */
final class OnboardingServiceProvider extends ServiceProvider
{
    /**
     * @param array<string,mixed> $config platform config (heartbeat offline threshold + reconcile
     *        page size are read from here — mirrors WorkerServiceProvider/OperationsServiceProvider).
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        // --- ONB-S1a page skeleton + mount seam -----------------------------------------
        $container->singleton(
            OnboardingPageController::class,
            fn () => new OnboardingPageController(Version::CURRENT),
        );

        $container->singleton(
            OnboardingAdminRegistrar::class,
            fn (Container $c) => new OnboardingAdminRegistrar(
                $c->get(OnboardingPageController::class),
            ),
        );

        // --- ONB-S1b completion flag (single WP option, MySQL — DECISION W (d)) ----------
        $container->singleton(OnboardingState::class, fn () => new OnboardingState());
        $container->singleton(
            OnboardingStateInterface::class,
            fn (Container $c) => $c->get(OnboardingState::class),
        );

        // --- ONB-S1b PG probe (delivery-handle reuse — DECISION W (e); K/L Ruling 0/E) ---
        // The delivery handle is resolved LAZILY: DeliveryServiceProvider opens libpq eagerly and
        // THROWS when PG is unreachable, so a pre-resolved handle would make that throw fire during
        // container resolution (before any check runs) → uncaught exception / HTTP 500 on the
        // preflight endpoint. Passing a resolver defers the (possibly-throwing) open to inside the
        // probe's try/catch, where connection failure becomes a preflight FAIL. Still the delivery
        // handle (DECISION K), no fifth handle (L Ruling 0), no new pg_* wrapper (E).
        $container->singleton(
            OnboardingConnectionProbe::class,
            fn (Container $c) => new OnboardingConnectionProbe(
                static fn (): DatabaseConnectionInterface => $c->get(DatabaseConnectionInterface::class),
            ),
        );

        // --- ONB-S1b FOUR hard-blocking environment preflight checks (DECISION W (f),
        //     amended v1.22 — the migration-engine-state check moved to ONB-S2 as a backfill
        //     prerequisite, so it is NOT part of this environment preflight). ----------------
        $container->singleton(PgsqlExtensionCheck::class, fn () => new PgsqlExtensionCheck());
        $container->singleton(PgConstantsCheck::class, fn () => new PgConstantsCheck());
        $container->singleton(
            PgReachableCheck::class,
            fn (Container $c) => new PgReachableCheck($c->get(OnboardingConnectionProbe::class)),
        );
        $container->singleton(PhpVersionCheck::class, fn () => new PhpVersionCheck());

        // MigrationsAppliedCheck is bound here (constructed over the same probe) so ONB-S2 can
        // reuse it as its backfill migration-state gate — it is deliberately NOT added to the
        // ONB-S1b PreflightRunner below (DECISION W (f) amendment v1.22).
        $container->singleton(
            MigrationsAppliedCheck::class,
            fn (Container $c) => new MigrationsAppliedCheck($c->get(OnboardingConnectionProbe::class)),
        );

        // Runner in display order: extension → constants → reachable → PHP version.
        $container->singleton(
            PreflightRunner::class,
            fn (Container $c) => new PreflightRunner(
                $c->get(PgsqlExtensionCheck::class),
                $c->get(PgConstantsCheck::class),
                $c->get(PgReachableCheck::class),
                $c->get(PhpVersionCheck::class),
            ),
        );

        // --- ONB-S2 backfill: reader + gate + progress + service (thin delegators) ----------
        // All PG reads reuse the delivery handle via the SAME lazy resolver the probe uses
        // (DECISION W (e); K/L Ruling 0/E) — onboarding opens no handle of its own.
        $container->singleton(
            BackfillReader::class,
            fn (Container $c) => new BackfillReader(
                static fn (): DatabaseConnectionInterface => $c->get(DatabaseConnectionInterface::class),
            ),
        );

        // Backfill gate: live worker heartbeat (DECISION P freshness, same offline threshold the
        // Worker Status provider uses) + applied migrations (reuses the ONB-S1b MigrationsAppliedCheck
        // moved here by DECISION W (f) v1.22). Hard block with remediation when unmet.
        $container->singleton(
            BackfillGate::class,
            fn (Container $c) => new BackfillGate(
                $c->get(BackfillReader::class),
                $c->get(MigrationsAppliedCheck::class),
                $this->heartbeatOfflineAfterSeconds(),
            ),
        );

        // Derived-on-demand progress (DECISION Q / W (d)): expected WP counts (via the existing
        // WpReconciliationSourceInterface — Rule 5, no module import) vs live projection counts.
        // WpReconciliationSourceInterface is bound by ContentServiceProvider; resolved lazily so
        // provider order is safe.
        $container->singleton(
            BackfillProgress::class,
            fn (Container $c) => new BackfillProgress(
                $c->get(WpReconciliationSourceInterface::class),
                $c->get(BackfillReader::class),
                $this->reconcilePageSize(),
            ),
        );

        // Backfill trigger: THIN DELEGATOR to ReconciliationService::reconcileFull() (DECISION W
        // (b)). Holds only the gate + the reconciliation service — no DatabaseConnectionInterface,
        // no adapter, no projection writer — so the write-spy proof holds by construction.
        // ReconciliationService is bound by WorkerServiceProvider; resolved lazily.
        $container->singleton(
            BackfillService::class,
            fn (Container $c) => new BackfillService(
                $c->get(BackfillGate::class),
                $c->get(ReconciliationService::class),
            ),
        );

        // --- ONB-S2 self-remediation: migration applier + worker cron spawner (DECISION W (e)/(f)
        //     v1.23; DECISION W (c); DECISION X (4); ADR-054 Principle 8) ----------------------
        // MigrationApplier: THIN DELEGATOR to the EXISTING migration engine over the DECISION W (e)
        // delegate list (core migrations from `migrations.core` + module migrations collected via the
        // module registry's declarative getMigrations() — Rule 5, core imports no module migration
        // class). All three inputs are resolved LAZILY: the engine + pgsql migration connection open
        // libpq eagerly and throw when PG is unreachable, so building them at container-resolution
        // time would fatal an unconfigured site. Adds no new engine / DDL / schema (DECISION E).
        $container->singleton(
            MigrationApplier::class,
            fn (Container $c) => new MigrationApplier(
                static fn (): MigrationRunner => $c->get('migration.runner'),
                /** @return list<MigrationInterface> */
                static fn (): array => array_values($c->get('migrations.core')),
                /** @return list<MigrationInterface> */
                static function () use ($c): array {
                    $migrations = [];
                    /** @var ModuleInterface $module */
                    foreach ($c->get('module.registry')->all() as $module) {
                        foreach ($module->getMigrations() as $migration) {
                            $migrations[] = $migration;
                        }
                    }

                    return $migrations;
                },
            ),
        );

        // WorkerCronSpawner: THIN DELEGATOR that ensures the processing cron is scheduled and issues a
        // NON-BLOCKING WP-Cron spawn (no in-request drain — DECISION W (c)). Reuses the existing
        // ProcessingCronRegistrar (bound by WorkerServiceProvider); resolved lazily.
        $container->singleton(
            WorkerCronSpawner::class,
            fn (Container $c) => new WorkerCronSpawner(
                $c->get(ProcessingCronRegistrar::class),
            ),
        );

        // --- ONB-S1b/S2 WPCS-guarded REST endpoints the React app calls (DECISION W (a)) ----
        $container->singleton(
            OnboardingRestController::class,
            fn (Container $c) => new OnboardingRestController(
                $c->get(PreflightRunner::class),
                $c->get(OnboardingStateInterface::class),
                $c->get(BackfillService::class),
                $c->get(BackfillProgress::class),
                $c->get(MigrationApplier::class),
                $c->get(MigrationsAppliedCheck::class),
                $c->get(WorkerCronSpawner::class),
            ),
        );

        $container->singleton(
            OnboardingRestRegistrar::class,
            fn (Container $c) => new OnboardingRestRegistrar(
                $c->get(OnboardingRestController::class),
            ),
        );
    }

    /**
     * The heartbeat freshness threshold the backfill worker-gate uses — the SAME config key the
     * Operations Worker Status provider reads (DECISION P), so "live worker" means the same thing
     * in both places.
     */
    private function heartbeatOfflineAfterSeconds(): int
    {
        $value = $this->config['worker']['heartbeat']['offline_after_seconds'] ?? 60;

        return (int) $value;
    }

    /**
     * Reconciliation/backfill page size (DECISION U D7 config-driven paging) — the same key
     * WorkerServiceProvider uses to build ReconciliationService, so expected-count paging matches
     * the reconcile paging.
     */
    private function reconcilePageSize(): int
    {
        $value = $this->config['worker']['reconciliation']['page_size'] ?? 500;
        $value = (int) $value;

        return $value > 0 ? $value : 500;
    }
}
