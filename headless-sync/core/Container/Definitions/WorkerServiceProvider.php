<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Contracts\WorkerInterface;
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
use HSP\Core\Workers\ProcessingCronRegistrar;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;

/**
 * Registers the WP-Cron Processing Engine cycle, registries, strategies, and heartbeat publisher.
 *
 * Bindings:
 *   EventRegistry                   — singleton; explicit registration only (no discovery)
 *   AdapterRegistry                 — singleton; explicit registration only
 *   HeartbeatPublisherInterface     — DatabaseHeartbeatPublisher (DECISION P) on the
 *                                     worker-runtime handle (DECISION L Ruling 0)
 *   'worker.strategy.event'         — EventWorkerStrategy (projection stage, 'content' queue)
 *   'worker.strategy.replay'        — ReplayWorkerStrategy (producer-side, CLI/cron-triggered)
 *   'worker.strategy.reconciliation'— ReconciliationWorkerStrategy (producer-side)
 *   'worker.strategy.maintenance'   — MaintenanceWorkerStrategy (drives requeueTimedOut)
 *   WorkerInterface / 'processing.engine' — the ONE bounded Processing Engine cycle
 *                                     composing relay → dispatch → projection → maintenance
 *                                     (ADR-054 / Doc 8 v2.0 §9). Reads processing.* batch
 *                                     sizes + cycle_time_budget_seconds from config/worker.php.
 *
 * ADR-054 (DECISION X, v1.24): there is NO daemon engine. The per-strategy daemon-engine
 * bindings ('worker.engine.event' / 'worker.engine.maintenance') and the standalone
 * 'dispatcher.engine' are RETIRED from the execution path — replaced by the single bounded
 * cycle engine bound here, which composes the retained stage strategies. The strategy
 * bindings themselves are kept.
 *
 * Authority:
 *   ADR-054 / Doc 8 v2.0 §9/§12 — one bounded cron cycle composing the four stages;
 *                        config-driven per-stage batch sizes + execution-time budget.
 *   DECISION X (v1.24) — per-cycle fresh UUID / running-idle status / bounded-cycle contract.
 *   DECISION E (v1.6)  — EventWorkerStrategy receives DatabaseConnectionInterface for
 *                        Resolve-stage stale guard (DECISION J); no new raw pg_* wrapper.
 *   DECISION P (v1.16) — DatabaseHeartbeatPublisher replaces NullHeartbeatPublisher on
 *                        the runtime path; upserts system.worker_heartbeats per cycle.
 *   DECISION L Ruling 0 (v1.16) — heartbeat rides the EXISTING worker-runtime connection
 *                        ('queue.connection.pgsql'); no new handle/class/pg_* wrapper.
 *   DECISION R (v1.16) — MaintenanceWorkerStrategy drives requeueTimedOut() (per-cycle sweep).
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

        // ADR-054 / Doc 8 v2.0 §9: the ONE bounded Processing Engine cycle. Composes the four
        // consumer-side stage strategies (relay → dispatch → projection → maintenance) into a
        // single bounded, time-budgeted cycle. Resolves 'relay.worker' (OutboxServiceProvider)
        // and 'dispatcher.strategy' (DispatcherServiceProvider) lazily — both are registered
        // before this provider, and closures defer resolution, so order is safe. Batch sizes +
        // budget are config-driven (processing.* keys; no hardcoded values — DECISION R precedent).
        $container->singleton(WorkerInterface::class, function (Container $c) {
            /** @var array<string,mixed> $processing */
            $processing         = $this->config['worker']['processing'] ?? [];
            $projectionBatchSize = (int) ($processing['projection_batch_size'] ?? 100);
            $budgetSeconds       = (float) ($processing['cycle_time_budget_seconds'] ?? 20);

            return new WorkerEngine(
                $c->get('relay.worker'),
                $c->get('dispatcher.strategy'),
                $c->get('worker.strategy.event'),
                $c->get('worker.strategy.maintenance'),
                $c->get(HeartbeatPublisherInterface::class),
                projectionBatchSize:    $projectionBatchSize > 0 ? $projectionBatchSize : 100,
                cycleTimeBudgetSeconds:  $budgetSeconds > 0 ? $budgetSeconds : 20,
                workerType:              'processing',
                counters:                $c->get(WorkerCounters::class),
                logger:                  $c->get(StructuredLogger::class),
            );
        });

        // Alias so cron/CLI callers can resolve the engine by an explicit key too.
        $container->singleton('processing.engine', fn (Container $c) => $c->get(WorkerInterface::class));

        // ADR-054 / Doc 8 v2.0 §23: the WP-Cron trigger that fires ONE bounded cycle per tick.
        // Custom-interval cadence config-driven (processing.schedule / interval_seconds);
        // ReconciliationCronRegistrar precedent. No daemon CLI (superseded ADR-024 surface).
        $container->singleton(ProcessingCronRegistrar::class, function (Container $c) {
            /** @var array<string,mixed> $processing */
            $processing = $this->config['worker']['processing'] ?? [];

            // HOTFIX: pass a LAZY engine resolver, not the built engine. Resolving the
            // registrar (done on every request at plugins_loaded) must NOT construct the
            // engine — that would cascade into opening the outbox MySQL connection and
            // fatal wp-admin. The closure defers WorkerInterface resolution to runCycle().
            return new ProcessingCronRegistrar(
                static fn (): WorkerInterface => $c->get(WorkerInterface::class),
                $processing,
            );
        });
    }
}
