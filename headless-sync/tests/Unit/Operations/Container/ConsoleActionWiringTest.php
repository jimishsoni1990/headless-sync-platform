<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Container;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OperationsServiceProvider;
use HSP\Core\Contracts\Operations\ActionRegistryInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\Admin\ConsoleActionController;
use HSP\Core\Operations\Services\OperationsActionService;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use HSP\Tests\Unit\Operations\Fakes\WiringTestBindings;
use PHPUnit\Framework\TestCase;

/**
 * Wiring smoke for the OPSC-S4 action additions to OperationsServiceProvider (DECISION V (d)/(e)/(f)).
 *
 * Proves the action seam resolves via constructor injection (ADR-012), that boot() registers
 * EXACTLY the two permitted actions (Replay + Reconcile) — and, mechanically, that NO Flush Queue
 * and NO Restart Workers action is ever registered (DECISION V (e)/(f)). The write-spy delivery
 * handle stands in so no live DB is needed; the worker-strategy dependencies are provided as the
 * real composition root would (WorkerServiceProvider runs before this provider).
 */
final class ConsoleActionWiringTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->container->instance(DatabaseConnectionInterface::class, new ScriptedReaderConnection());
        WiringTestBindings::registerActionDependencies($this->container);

        $provider = new OperationsServiceProvider(['worker' => []]);
        $provider->register($this->container);
        $provider->boot($this->container);
    }

    public function test_action_seam_resolves_via_constructor_injection(): void
    {
        self::assertInstanceOf(
            OperationsActionService::class,
            $this->container->get(OperationsActionService::class),
        );
        self::assertInstanceOf(
            ConsoleActionController::class,
            $this->container->get(ConsoleActionController::class),
        );
    }

    public function test_boot_registers_exactly_replay_and_reconcile_actions(): void
    {
        /** @var ActionRegistryInterface $actions */
        $actions = $this->container->get(ActionRegistryInterface::class);

        $keys = array_map(static fn ($a) => $a->key, $actions->all());
        sort($keys);

        self::assertSame(['reconcile', 'replay'], $keys);

        // Each action is capability-gated and confirmation-required (ADR-053 / DECISION V (b)).
        foreach ($actions->all() as $action) {
            self::assertNotSame('', $action->capability, "action '{$action->key}' must require a capability");
            self::assertTrue($action->confirmationRequired, "action '{$action->key}' must require confirmation");
        }
    }

    public function test_no_flush_queue_or_restart_workers_action_exists(): void
    {
        /** @var ActionRegistryInterface $actions */
        $actions = $this->container->get(ActionRegistryInterface::class);

        // Mechanical assertion of the DECISION V (e)/(f) prohibition: the destructive / lifecycle
        // actions are ABSENT from the registry, under any plausible key.
        foreach (['flush_queue', 'flush-queue', 'flush', 'restart_workers', 'restart-workers', 'restart'] as $forbidden) {
            self::assertFalse($actions->has($forbidden), "forbidden action '{$forbidden}' must not be registered");
        }
    }

    public function test_the_action_service_permits_only_replay_and_reconcile(): void
    {
        /** @var OperationsActionService $service */
        $service = $this->container->get(OperationsActionService::class);

        self::assertSame(['replay', 'reconcile'], $service->supportedActions());
    }
}
