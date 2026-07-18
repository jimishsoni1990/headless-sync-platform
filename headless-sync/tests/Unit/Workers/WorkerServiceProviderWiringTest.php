<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\WorkerServiceProvider;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Contracts\WorkerInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Events\Dispatcher\DispatcherWorkerStrategy;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\ProcessingCronRegistrar;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;
use HSP\Tests\Unit\Events\Outbox\FakeMysqlOutboxConnection;
use HSP\Tests\Unit\Events\Outbox\FakePgsqlOutboxConnection;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Events/Outbox/FakeOutboxConnection.php';

/**
 * WorkerServiceProvider wiring (ADR-054 / DECISION X): the ONE bounded Processing Engine cycle
 * and the WP-Cron trigger resolve via constructor injection, and the retired per-strategy
 * daemon-engine bindings are gone.
 *
 * The upstream stage-strategy bindings ('relay.worker', 'dispatcher.strategy',
 * 'worker.strategy.event', 'worker.strategy.maintenance') are normally supplied by
 * OutboxServiceProvider / DispatcherServiceProvider / (the rest of) WorkerServiceProvider; here
 * they are stubbed with lightweight doubles so the cycle-engine binding can be resolved without
 * a live database.
 */
final class WorkerServiceProviderWiringTest extends TestCase
{
    private function containerWithStubbedStages(): Container
    {
        $container = new Container();

        // Config the provider reads (processing.* batch sizes + budget).
        $container->instance('config', (object) [
            'worker' => ['processing' => [
                'projection_batch_size'     => 50,
                'cycle_time_budget_seconds' => 15,
            ]],
        ]);

        $db = new FakePgsqlOutboxConnection();

        // Upstream stage strategies (normally from Outbox/Dispatcher/Worker providers).
        $container->singleton('relay.worker', fn () => new RelayWorkerStrategy(
            new FakeMysqlOutboxConnection(), $db, 'wp_', 100,
        ));
        $container->singleton(DatabaseConnectionInterface::class, fn () => $db);
        $container->singleton(DatabaseQueueProvider::class, fn () => new DatabaseQueueProvider($db));
        $container->singleton(QueueProviderInterface::class, fn () => new DatabaseQueueProvider($db));
        // Heartbeat publisher rides the worker-runtime handle (DECISION L Ruling 0).
        $container->singleton('queue.connection.pgsql', fn () => $db);
        $container->singleton('dispatcher.strategy', fn (Container $c) => new DispatcherWorkerStrategy(
            new EventDispatcher($db, $c->get(DatabaseQueueProvider::class), 100),
        ));

        // Register the real WorkerServiceProvider — it binds the rest (event/maintenance
        // strategies, heartbeat publisher, the cycle engine, and the cron registrar).
        (new WorkerServiceProvider((array) $container->get('config')))->register($container);

        return $container;
    }

    public function test_worker_interface_resolves_to_the_cycle_engine(): void
    {
        $engine = $this->containerWithStubbedStages()->get(WorkerInterface::class);

        self::assertInstanceOf(WorkerEngine::class, $engine);
    }

    public function test_processing_engine_alias_resolves_to_the_same_engine(): void
    {
        $c = $this->containerWithStubbedStages();

        self::assertSame($c->get(WorkerInterface::class), $c->get('processing.engine'));
    }

    public function test_processing_cron_registrar_resolves_via_constructor_injection(): void
    {
        $registrar = $this->containerWithStubbedStages()->get(ProcessingCronRegistrar::class);

        self::assertInstanceOf(ProcessingCronRegistrar::class, $registrar);
    }

    public function test_retired_daemon_engine_bindings_are_absent(): void
    {
        $c = $this->containerWithStubbedStages();

        self::assertFalse($c->has('worker.engine.event'), 'the per-strategy daemon engine is retired');
        self::assertFalse($c->has('worker.engine.maintenance'), 'the per-strategy daemon engine is retired');
    }
}
