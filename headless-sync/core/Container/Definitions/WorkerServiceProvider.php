<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Delivery\AdapterRegistry;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Workers\HeartbeatPublisherInterface;
use HSP\Core\Workers\NullHeartbeatPublisher;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;

/**
 * Registers the shared worker engine, registries, and strategies.
 *
 * Bindings:
 *   EventRegistry                   — singleton; explicit registration only (no discovery)
 *   AdapterRegistry                 — singleton; explicit registration only
 *   HeartbeatPublisherInterface     — NullHeartbeatPublisher (no-op until OPS-S1)
 *   'worker.strategy.event'         — EventWorkerStrategy (wired to 'content' queue)
 *   'worker.strategy.replay'        — ReplayWorkerStrategy stub
 *   'worker.strategy.reconciliation'— ReconciliationWorkerStrategy stub
 *   'worker.strategy.maintenance'   — MaintenanceWorkerStrategy stub
 *   'worker.engine.event'           — WorkerEngine driven by EventWorkerStrategy
 *
 * Authority:
 *   DECISION E (v1.6) — EventWorkerStrategy receives DatabaseConnectionInterface for
 *                        Resolve-stage stale guard (DECISION J); no new raw pg_* wrapper.
 *   CLAUDE.md Rule 7  — constructor injection only; no Container::get() inside business logic.
 */
final class WorkerServiceProvider extends ServiceProvider
{
    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton(EventRegistry::class, fn () => new EventRegistry());
        $container->singleton(AdapterRegistry::class, fn () => new AdapterRegistry());

        $container->singleton(
            HeartbeatPublisherInterface::class,
            fn () => new NullHeartbeatPublisher(),
        );

        $container->singleton('worker.strategy.event', function (Container $c) {
            return new EventWorkerStrategy(
                $c->get(QueueProviderInterface::class),
                $c->get(EventRegistry::class),
                $c->get(DatabaseConnectionInterface::class),
            );
        });

        $container->singleton('worker.strategy.replay', fn () => new ReplayWorkerStrategy());
        $container->singleton('worker.strategy.reconciliation', fn () => new ReconciliationWorkerStrategy());
        $container->singleton('worker.strategy.maintenance', fn () => new MaintenanceWorkerStrategy());

        $container->singleton('worker.engine.event', function (Container $c) {
            return new WorkerEngine(
                $c->get('worker.strategy.event'),
                $c->get(HeartbeatPublisherInterface::class),
            );
        });
    }
}
