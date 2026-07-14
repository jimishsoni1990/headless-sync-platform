<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Registries;

use HSP\Core\Contracts\Operations\ConsoleAction;
use HSP\Core\Operations\Registries\ActionRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ActionRegistry (Doc 12 §17; ADR-048/ADR-052/ADR-053).
 *
 * OPSC-S1 covers discovery only — action behaviour is OPSC-S4. The permitted set is bound
 * by DECISION V (Replay + Reconcile only; no Flush Queue; no Restart Workers), but the
 * registry itself is action-agnostic; scope enforcement is a composition-root concern.
 */
final class ActionRegistryTest extends TestCase
{
    private ActionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ActionRegistry();
    }

    public function test_registers_and_gets_in_registration_order(): void
    {
        $replay = new ConsoleAction('replay', 'Replay', 'manage_options');
        $recon  = new ConsoleAction('reconcile', 'Reconcile', 'manage_options');
        $this->registry->register($replay);
        $this->registry->register($recon);

        self::assertSame($replay, $this->registry->get('replay'));
        self::assertTrue($this->registry->has('reconcile'));

        $keys = array_map(static fn (ConsoleAction $a): string => $a->key, $this->registry->all());
        self::assertSame(['replay', 'reconcile'], $keys);
    }

    public function test_get_throws_for_unknown_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No action registered');
        $this->registry->get('flush-queue');
    }

    public function test_duplicate_key_registration_throws(): void
    {
        $this->registry->register(new ConsoleAction('replay', 'Replay', 'manage_options'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already registered');
        $this->registry->register(new ConsoleAction('replay', 'Replay 2', 'manage_options'));
    }

    public function test_empty_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new ConsoleAction('', 'Replay', 'manage_options'));
    }

    public function test_empty_capability_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new ConsoleAction('replay', 'Replay', ''));
    }

    public function test_action_defaults_to_requiring_confirmation(): void
    {
        $this->registry->register(new ConsoleAction('replay', 'Replay', 'manage_options'));
        self::assertTrue($this->registry->get('replay')->confirmationRequired);
    }
}
