<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Delivery\AdapterRegistry;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Observability\OperationalMetricsQuery;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Cli\ReconcileCommand;
use HSP\Core\Cli\ReplayCommand;
use HSP\Core\Observability\WorkerCounters;
use HSP\Core\Reconciliation\ReconciliationCronRegistrar;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\DatabaseHeartbeatPublisher;
use HSP\Core\Workers\HeartbeatPublisherInterface;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;

/**
 * Registers the shared worker engine, registries, strategies, and heartbeat publisher.
 *
 * Bindings:
 *   EventRegistry                   — singleton; explicit registration only (no discovery)
 *   AdapterRegistry                 — singleton; explicit registration only
 *   HeartbeatPublisherInterface     — DatabaseHeartbeatPublisher (DECISION P) on the
 *                                     worker-runtime handle (DECISION L Ruling 0)
 *   'worker.strategy.event'         — EventWorkerStrategy (wired to 'content' queue)
 *   'worker.strategy.replay'        — ReplayWorkerStrategy stub
 *   'worker.strategy.reconciliation'— ReconciliationWorkerStrategy stub
 *   'worker.strategy.maintenance'   — MaintenanceWorkerStrategy (drives requeueTimedOut)
 *   'worker.engine.event'           — WorkerEngine driven by EventWorkerStrategy
 *   'worker.engine.maintenance'     — WorkerEngine driven by MaintenanceWorkerStrategy
 *
 * Authority:
 *   DECISION E (v1.6)  — EventWorkerStrategy receives DatabaseConnectionInterface for
 *                        Resolve-stage stale guard (DECISION J); no new raw pg_* wrapper.
 *   DECISION P (v1.16) — DatabaseHeartbeatPublisher replaces NullHeartbeatPublisher on
 *                        the runtime path; upserts system.worker_heartbeats per tick.
 *   DECISION L Ruling 0 (v1.16) — heartbeat rides the EXISTING worker-runtime connection
 *                        ('queue.connection.pgsql'); no new handle/class/pg_* wrapper.
 *   DECISION R (v1.16) — MaintenanceWorkerStrategy drives requeueTimedOut() on a
 *                        config-driven cadence (no hardcoded timing).
 *   CLAUDE.md Rule 7   — constructor injection only; no Container::get() inside business logic.
 */
final class WorkerServiceProvider extends ServiceProvider
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(
        private readonly array $config = [],
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton(EventRegistry::class, fn () => new EventRegistry());
        $container->singleton(AdapterRegistry::class, fn () => new AdapterRegistry());

        // Observability (DECISION Q): in-process runtime counters + structured-log sink,
        // and the on-demand derived-metrics query surface. No metrics table is created.
        $container->singleton(WorkerCounters::class, fn () => new WorkerCounters());
        $container->singleton(StructuredLogger::class, fn () => new StructuredLogger());
        $container->singleton(
            OperationalMetricsQuery::class,
            fn (Container $c) => new OperationalMetricsQuery(
                $c->get(DatabaseConnectionInterface::class),
            ),
        );

        // DECISION P + DECISION L Ruling 0: the heartbeat publisher writes through the
        // EXISTING worker-runtime handle ('queue.connection.pgsql'). No new connection.
        $container->singleton(
            HeartbeatPublisherInterface::class,
            fn (Container $c) => new DatabaseHeartbeatPublisher(
                $c->get('queue.connection.pgsql'),
            ),
        );

        $container->singleton('worker.strategy.event', function (Container $c) {
            return new EventWorkerStrategy(
                $c->get(QueueProviderInterface::class),
                $c->get(EventRegistry::class),
                $c->get(DatabaseConnectionInterface::class),
                retryLimit: 10,
                counters:   $c->get(WorkerCounters::class),
            );
        });

        // DECISION T: ReplayWorkerStrategy owns entity/date-range replay, delegating to
        // ReplayService (bound by ContentServiceProvider — it wires the module emitter and
        // the delivery handle). Resolved lazily, so provider registration order is safe.
        $container->singleton('worker.strategy.replay', fn (Container $c) =>
            new ReplayWorkerStrategy($c->get(ReplayService::class))
        );
        // DECISION T: WP-CLI replay command surface. Depends on the replay strategy and
        // the structured logger (emits the `replay` runtime counter — DECISION Q).
        $container->singleton(ReplayCommand::class, fn (Container $c) =>
            new ReplayCommand(
                $c->get('worker.strategy.replay'),
                $c->get(StructuredLogger::class),
            )
        );

        // DECISION U: ReconciliationService (core detector/orchestrator) + the strategy
        // façade. The WP-side detection source (WpReconciliationSourceInterface) is bound by
        // ContentServiceProvider (module-owned, mirrors ReplayEmitterInterface). Repair is
        // DECISION T re-emission only (ReplayService), never a direct PG write. Page size is
        // config-driven (DECISION U D7). Resolved lazily → provider order is safe.
        $container->singleton(ReconciliationService::class, function (Container $c) {
            /** @var array<string,mixed> $reconConfig */
            $reconConfig = $this->config['worker']['reconciliation'] ?? [];
            $pageSize    = (int) ($reconConfig['page_size'] ?? 500);

            return new ReconciliationService(
                $c->get(DatabaseConnectionInterface::class),
                $c->get(WpReconciliationSourceInterface::class),
                $c->get(ReplayService::class),
                $pageSize > 0 ? $pageSize : 500,
            );
        });

        $container->singleton('worker.strategy.reconciliation', fn (Container $c) =>
            new ReconciliationWorkerStrategy($c->get(ReconciliationService::class))
        );

        // DECISION U: WP-CLI reconcile command surface (emits the `reconcile` runtime
        // counter — DECISION Q) + the WP-Cron trigger registrar (cadence config-driven).
        $container->singleton(ReconcileCommand::class, fn (Container $c) =>
            new ReconcileCommand(
                $c->get('worker.strategy.reconciliation'),
                $c->get(StructuredLogger::class),
            )
        );

        $container->singleton(ReconciliationCronRegistrar::class, function (Container $c) {
            /** @var array<string,mixed> $reconConfig */
            $reconConfig = $this->config['worker']['reconciliation'] ?? [];

            return new ReconciliationCronRegistrar(
                $c->get('worker.strategy.reconciliation'),
                $reconConfig,
            );
        });

        $container->singleton('worker.strategy.maintenance', function (Container $c) {
            /** @var array<string,mixed> $maintenanceConfig */
            $maintenanceConfig = $this->config['worker']['maintenance'] ?? [];

            return new MaintenanceWorkerStrategy(
                $c->get(QueueProviderInterface::class),
                $maintenanceConfig,
            );
        });

        $container->singleton('worker.engine.event', function (Container $c) {
            return new WorkerEngine(
                $c->get('worker.strategy.event'),
                $c->get(HeartbeatPublisherInterface::class),
                idleWaitMs: 200,
                workerType: 'event',
                counters:   $c->get(WorkerCounters::class),
                logger:     $c->get(StructuredLogger::class),
            );
        });

        $container->singleton('worker.engine.maintenance', function (Container $c) {
            return new WorkerEngine(
                $c->get('worker.strategy.maintenance'),
                $c->get(HeartbeatPublisherInterface::class),
                workerType: 'maintenance',
            );
        });
    }
}
