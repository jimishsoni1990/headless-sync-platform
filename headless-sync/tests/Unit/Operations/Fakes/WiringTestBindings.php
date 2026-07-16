<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Container\Container;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;

/**
 * Test-support wiring for the OPSC-S4 action seam.
 *
 * In the real composition root, WorkerServiceProvider (registered before OperationsServiceProvider)
 * binds `worker.strategy.replay`, `worker.strategy.reconciliation`, and StructuredLogger — the
 * three dependencies of OperationsActionService. A unit test that registers OperationsServiceProvider
 * in isolation must provide those bindings so the console graph
 * (ConsoleAdminRegistrar → ConsoleActionController → OperationsActionService) resolves.
 *
 * The strategies here are REAL (they are `final`), constructed over real services built on a
 * read-only ScriptedReaderConnection + no-op module fakes. They are never invoked in wiring
 * smoke tests — only resolved — so the fakes need no behaviour.
 */
final class WiringTestBindings
{
    public static function registerActionDependencies(Container $container): void
    {
        $container->singleton(StructuredLogger::class, static fn () => new StructuredLogger(static function (): void {}));

        $container->singleton('worker.strategy.replay', static function () {
            $replayService = new ReplayService(new ScriptedReaderConnection(), [new NoopReplayEmitter()]);

            return new ReplayWorkerStrategy($replayService);
        });

        $container->singleton('worker.strategy.reconciliation', static function () {
            $service = new ReconciliationService(
                new ScriptedReaderConnection(),
                new NoopReconciliationSource(),
                new ReplayService(new ScriptedReaderConnection(), [new NoopReplayEmitter()]),
            );

            return new ReconciliationWorkerStrategy($service);
        });
    }
}
