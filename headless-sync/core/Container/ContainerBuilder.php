<?php

declare(strict_types=1);

namespace HSP\Core\Container;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Definitions\CoreServiceProvider;
use HSP\Core\Container\Definitions\DeliveryServiceProvider;
use HSP\Core\Container\Definitions\DispatcherServiceProvider;
use HSP\Core\Container\Definitions\MigrationServiceProvider;
use HSP\Core\Container\Definitions\ModuleServiceProvider;
use HSP\Core\Container\Definitions\OnboardingServiceProvider;
use HSP\Core\Container\Definitions\OperationsServiceProvider;
use HSP\Core\Container\Definitions\OutboxServiceProvider;
use HSP\Core\Container\Definitions\QueueServiceProvider;
use HSP\Core\Container\Definitions\WorkerServiceProvider;
use HSP\Core\Module\ModuleDiscovery;
use HSP\Core\Module\ModuleProviderComposer;

/**
 * Builds and wires the DI container.
 *
 * This is the composition root: service providers are registered here,
 * the container is built, and the two-phase lifecycle (register → boot) runs.
 *
 * Adding CORE bindings: add a ServiceProvider under core/Container/Definitions/ and
 * register it below.
 *
 * Adding a MODULE: nothing changes here. DECISION AG (AG-1) — core must not import or
 * hardcode a concrete module class. Each module declares `service_provider` in its
 * module.json; ModuleProviderComposer discovers those, skips modules whose runtime
 * requirements are unmet (AG-12), and hands the rest to the same ServiceRegistry as any
 * core provider. Commerce, and every module after it, adds no line to this file.
 */
final class ContainerBuilder
{
    public function build(array $config, string $modulesBasePath = ''): Container
    {
        $container = new Container();

        $container->instance('config', (object) $config);

        // Single resolver for all runtime DB credential resolution — DECISION O (v1.15).
        // Constructed once here (composition root) and injected into each provider factory.
        $resolver = new CredentialResolver();

        $registry = new ServiceRegistry();
        $registry->addProvider(new CoreServiceProvider($config));
        $registry->addProvider(new MigrationServiceProvider($config, $resolver));
        $registry->addProvider(new OutboxServiceProvider($config, $resolver));
        $registry->addProvider(new QueueServiceProvider($config, $resolver));
        // DeliveryServiceProvider must be registered before WorkerServiceProvider
        // and ContentServiceProvider — both resolve DatabaseConnectionInterface
        // (DECISION K v1.11: dedicated FORCE_NEW delivery connection).
        $registry->addProvider(new DeliveryServiceProvider($config, $resolver));
        // DispatcherServiceProvider must follow QueueServiceProvider (DatabaseQueueProvider).
        // It opens its own FORCE_NEW connection — does NOT use DatabaseConnectionInterface
        // (DECISION K delivery handle). DECISION L v1.12.
        $registry->addProvider(new DispatcherServiceProvider($config, $resolver));
        $registry->addProvider(new WorkerServiceProvider($config));
        // Operations Console core scaffolding (OPSC-S1) + concrete diagnostics/metrics
        // providers (OPSC-S2). Read-only; no UI (OPSC-S3), no actions (OPSC-S4). Provider PG
        // reads ride the delivery DatabaseConnectionInterface (DECISION V (g)) — no fifth
        // handle (DECISION L Ruling 0 topology unchanged), no new pg_* wrapper (DECISION E).
        $registry->addProvider(new OperationsServiceProvider($config));
        // Onboarding / First-Run (ONB-S1a): React+shadcn admin page skeleton + mount seam.
        // Frontend + mount only — opens no PG handle, no new pg_* wrapper, no schema
        // (DECISION W (a)/(e); DECISION K reuse / L Ruling 0 / E). core/Onboarding/, not
        // core/Operations/ (DECISION V (j) console unaffected).
        $registry->addProvider(new OnboardingServiceProvider($config));

        // Module service providers — discovered, never imported (DECISION AG AG-1).
        // Providers for modules whose requirements are unmet are not added at all, so an
        // unavailable module contributes no bindings and no boot behaviour (AG-12).
        $composition = (new ModuleProviderComposer(new ModuleDiscovery($modulesBasePath)))->compose();
        foreach ($composition->providers as $moduleProvider) {
            $registry->addProvider($moduleProvider);
        }

        // The registry must run the lifecycle for AVAILABLE modules only; an unavailable
        // module has no bindings, so loading it would fail rather than degrade.
        $available   = $composition->availableModules;
        $unavailable = $composition->unavailableModules;
        $container->singleton('module.available_names', static fn (): array => $available);
        $container->singleton('module.unavailable_names', static fn (): array => $unavailable);

        $registry->addProvider(new ModuleServiceProvider($modulesBasePath));

        $registry->registerAll($container);
        $registry->bootAll($container);

        return $container;
    }
}
