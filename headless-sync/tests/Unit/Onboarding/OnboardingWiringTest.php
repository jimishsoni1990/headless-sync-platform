<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OnboardingServiceProvider;
use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Onboarding\Backfill\BackfillGate;
use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Onboarding\Backfill\BackfillService;
use HSP\Core\Onboarding\MigrationApplier;
use HSP\Core\Onboarding\OnboardingAdminRegistrar;
use HSP\Core\Onboarding\WorkerCronSpawner;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconciliationSource;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use HSP\Core\Onboarding\OnboardingPageController;
use HSP\Core\Onboarding\OnboardingRestController;
use HSP\Core\Onboarding\OnboardingRestRegistrar;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use HSP\Core\Onboarding\Preflight\PgConstantsCheck;
use HSP\Core\Onboarding\Preflight\PgReachableCheck;
use HSP\Core\Onboarding\Preflight\PgsqlExtensionCheck;
use HSP\Core\Onboarding\Preflight\PhpVersionCheck;
use HSP\Core\Onboarding\PreflightRunner;
use PHPUnit\Framework\TestCase;

/**
 * Wiring smoke for the Onboarding surface (ONB-S1a + ONB-S1b; ADR-012 / Rule 7).
 *
 * Proves the OnboardingServiceProvider bindings resolve as singletons via constructor injection,
 * with no service-locator call and no PostgreSQL handle OPENED by onboarding (the ONB-S1b DB
 * checks reuse the EXISTING delivery DatabaseConnectionInterface — DECISION W (e) / K). A stub
 * delivery handle is pre-bound to stand in for DeliveryServiceProvider; onboarding never opens
 * its own connection.
 */
final class OnboardingWiringTest extends TestCase
{
    private function containerWithDeliveryHandle(): Container
    {
        $container = new Container();

        // Stand-in for the delivery handle DeliveryServiceProvider binds. Onboarding must reuse
        // it (never open its own) — resolving the probe/checks proves the reuse.
        $container->singleton(DatabaseConnectionInterface::class, fn () => new class implements DatabaseConnectionInterface {
            public function execute(string $sql, array $params = []): int
            {
                throw new \RuntimeException('onboarding wiring must not write');
            }

            public function query(string $sql, array $params = []): array
            {
                return [];
            }

            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollback(): void {}
        });

        // ONB-S2 upstream deps normally bound by ContentServiceProvider / WorkerServiceProvider.
        // Onboarding resolves them LAZILY, so binding them here proves the backfill graph wires up
        // via constructor injection without onboarding owning them.
        $container->singleton(
            WpReconciliationSourceInterface::class,
            fn () => new FakeReconciliationSource(),
        );
        $container->singleton(
            ReconciliationService::class,
            fn (Container $c) => new ReconciliationService(
                new FakeReconConnection(),
                $c->get(WpReconciliationSourceInterface::class),
                new ReplayService(new FakeDbConnection(), [new FakeReplayEmitter()]),
            ),
        );

        $this->bindSelfRemediationDeps($container);

        (new OnboardingServiceProvider())->register($container);

        return $container;
    }

    /**
     * Bind the upstream deps the ONB-S2 self-remediation graph (MigrationApplier / WorkerCronSpawner)
     * resolves — normally provided by MigrationServiceProvider / ModuleServiceProvider /
     * WorkerServiceProvider. WorkerCronSpawner needs ProcessingCronRegistrar at construction; the
     * migrate delegate closures resolve migration.runner / migrations.core / module.registry lazily.
     */
    private function bindSelfRemediationDeps(Container $container): void
    {
        $container->singleton(
            \HSP\Core\Workers\ProcessingCronRegistrar::class,
            fn () => new \HSP\Core\Workers\ProcessingCronRegistrar(
                static fn (): \HSP\Core\Contracts\WorkerInterface => throw new \LogicException(
                    'engine never materialised in wiring tests',
                ),
                [],
            ),
        );

        // Lazy delegate-list inputs for MigrationApplier (resolved only if apply() runs).
        $container->singleton('migration.runner', fn () => null);
        $container->singleton('migrations.core', fn (): array => []);
        $container->singleton('module.registry', fn () => new class {
            /** @return array<string,object> */
            public function all(): array
            {
                return [];
            }
        });
    }

    public function test_bindings_resolve_via_constructor_injection_as_singletons(): void
    {
        $container = $this->containerWithDeliveryHandle();

        $page = $container->get(OnboardingPageController::class);
        self::assertInstanceOf(OnboardingPageController::class, $page);

        $registrar = $container->get(OnboardingAdminRegistrar::class);
        self::assertInstanceOf(OnboardingAdminRegistrar::class, $registrar);

        // Singletons: same instance on re-resolution.
        self::assertSame($page, $container->get(OnboardingPageController::class));
        self::assertSame($registrar, $container->get(OnboardingAdminRegistrar::class));
    }

    public function test_onb_s1b_preflight_and_rest_bindings_resolve(): void
    {
        $container = $this->containerWithDeliveryHandle();

        self::assertInstanceOf(
            OnboardingStateInterface::class,
            $container->get(OnboardingStateInterface::class),
        );
        self::assertInstanceOf(PreflightRunner::class, $container->get(PreflightRunner::class));
        self::assertInstanceOf(
            OnboardingRestController::class,
            $container->get(OnboardingRestController::class),
        );

        $rest = $container->get(OnboardingRestRegistrar::class);
        self::assertInstanceOf(OnboardingRestRegistrar::class, $rest);
        self::assertSame($rest, $container->get(OnboardingRestRegistrar::class));
    }

    public function test_onb_s1b_preflight_ships_the_four_environment_checks_and_not_migrations(): void
    {
        // DECISION W (f) amendment (v1.22): the ONB-S1b environment preflight is FOUR checks
        // (pgsql extension, PG constants, PG reachable, PHP version). The migration-engine-state
        // check moved to ONB-S2 and must NOT appear in this runner, even though its class stays
        // bound (for ONB-S2 to reuse).
        $container = $this->containerWithDeliveryHandle();

        $summary = $container->get(PreflightRunner::class)->summary();
        $keys    = array_map(static fn (array $c) => $c['key'], $summary['checks']);

        self::assertSame(
            [PgsqlExtensionCheck::KEY, PgConstantsCheck::KEY, PgReachableCheck::KEY, PhpVersionCheck::KEY],
            $keys,
        );
        self::assertNotContains(MigrationsAppliedCheck::KEY, $keys);

        // The migration check class is still resolvable for ONB-S2 reuse.
        self::assertInstanceOf(
            MigrationsAppliedCheck::class,
            $container->get(MigrationsAppliedCheck::class),
        );
    }

    public function test_onb_s2_backfill_bindings_resolve_via_constructor_injection(): void
    {
        $container = $this->containerWithDeliveryHandle();

        self::assertInstanceOf(BackfillReader::class, $container->get(BackfillReader::class));
        self::assertInstanceOf(BackfillGate::class, $container->get(BackfillGate::class));
        self::assertInstanceOf(BackfillProgress::class, $container->get(BackfillProgress::class));

        $service = $container->get(BackfillService::class);
        self::assertInstanceOf(BackfillService::class, $service);
        self::assertSame($service, $container->get(BackfillService::class));
    }

    public function test_registrars_register_are_safe_no_ops_without_wordpress_hooks(): void
    {
        $container = $this->containerWithDeliveryHandle();

        // add_action / register_rest_route are stubbed/no-op in tests/bootstrap.php.
        $container->get(OnboardingAdminRegistrar::class)->register();
        $container->get(OnboardingRestRegistrar::class)->register();

        $this->addToAssertionCount(1);
    }

    public function test_rest_graph_resolves_even_when_the_delivery_handle_factory_throws(): void
    {
        // Mirrors DeliveryServiceProvider throwing when PostgreSQL is unreachable. Because the
        // probe resolves the handle LAZILY, resolving the REST controller (and its PreflightRunner
        // → PgReachableCheck → probe) must NOT trigger the throwing factory — the throw is deferred
        // until a check actually runs, where it is caught. This is the regression guard for the
        // HTTP-500-on-unreachable-PG bug (DECISION W (e)/(f)).
        $container = new Container();
        $container->singleton(DatabaseConnectionInterface::class, static function (): DatabaseConnectionInterface {
            throw new \RuntimeException('Delivery PostgreSQL connect failed.');
        });
        // Upstream deps (never resolve the throwing delivery handle during graph construction).
        $container->singleton(
            WpReconciliationSourceInterface::class,
            fn () => new FakeReconciliationSource(),
        );
        $container->singleton(
            ReconciliationService::class,
            fn (Container $c) => new ReconciliationService(
                new FakeReconConnection(),
                $c->get(WpReconciliationSourceInterface::class),
                new ReplayService(new FakeDbConnection(), [new FakeReplayEmitter()]),
            ),
        );
        $this->bindSelfRemediationDeps($container);
        (new OnboardingServiceProvider())->register($container);

        $controller = $container->get(OnboardingRestController::class);
        self::assertInstanceOf(OnboardingRestController::class, $controller);

        // And the runner runs without leaking the connect exception — every check yields a result.
        $summary = $container->get(PreflightRunner::class)->summary();
        self::assertFalse($summary['ok']);
        self::assertNotEmpty($summary['checks']);
    }
}
