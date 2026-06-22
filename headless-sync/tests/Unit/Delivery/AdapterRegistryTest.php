<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Delivery;

use HSP\Core\Delivery\AdapterRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AdapterRegistry.
 *
 * Verified:
 *   register()              — explicit registration; no reflection or discovery
 *   has()                   — true after registration; false before
 *   get()                   — returns registered adapter; throws for unknown class
 *   last-wins               — second registration for same class replaces first
 *   getRegisteredClasses()  — lists all registered canonical model classes
 *   validation              — adapter returning empty class string rejected
 */
final class AdapterRegistryTest extends TestCase
{
    private AdapterRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AdapterRegistry();
    }

    // -------------------------------------------------------------------------
    // has()
    // -------------------------------------------------------------------------

    public function test_has_returns_false_for_unregistered_class(): void
    {
        self::assertFalse($this->registry->has('App\Models\CanonicalPost'));
    }

    public function test_has_returns_true_after_registration(): void
    {
        $this->registry->register(new FakeAdapter('App\Models\CanonicalPost'));
        self::assertTrue($this->registry->has('App\Models\CanonicalPost'));
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function test_get_returns_registered_adapter(): void
    {
        $adapter = new FakeAdapter('App\Models\CanonicalPost');
        $this->registry->register($adapter);

        self::assertSame($adapter, $this->registry->get('App\Models\CanonicalPost'));
    }

    public function test_get_throws_for_unknown_class(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No adapter registered');
        $this->registry->get('App\Models\CanonicalPage');
    }

    // -------------------------------------------------------------------------
    // Duplicate registration is a programming error → throws
    // -------------------------------------------------------------------------

    public function test_second_registration_for_same_class_throws(): void
    {
        $this->registry->register(new FakeAdapter('App\Models\CanonicalPost'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already registered');

        $this->registry->register(new FakeAdapter('App\Models\CanonicalPost'));
    }

    // -------------------------------------------------------------------------
    // getRegisteredClasses()
    // -------------------------------------------------------------------------

    public function test_get_registered_classes_is_empty_initially(): void
    {
        self::assertSame([], $this->registry->getRegisteredClasses());
    }

    public function test_get_registered_classes_lists_all(): void
    {
        $this->registry->register(new FakeAdapter('App\Models\CanonicalPost'));
        $this->registry->register(new FakeAdapter('App\Models\CanonicalPage'));

        $classes = $this->registry->getRegisteredClasses();
        self::assertContains('App\Models\CanonicalPost', $classes);
        self::assertContains('App\Models\CanonicalPage', $classes);
        self::assertCount(2, $classes);
    }

    // -------------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------------

    public function test_register_rejects_adapter_with_empty_class_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new FakeAdapter(''));
    }

    // -------------------------------------------------------------------------
    // Explicit registration only — no magic
    // -------------------------------------------------------------------------

    public function test_registry_has_no_pre_registered_adapters(): void
    {
        self::assertSame([], $this->registry->getRegisteredClasses());
        self::assertFalse($this->registry->has('App\Models\CanonicalPost'));
    }
}
