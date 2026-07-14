<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Registries;

use HSP\Core\Contracts\Operations\NavigationItem;
use HSP\Core\Operations\Registries\NavigationRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NavigationRegistry (Doc 12 §6; ADR-048/ADR-052).
 */
final class NavigationRegistryTest extends TestCase
{
    private NavigationRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new NavigationRegistry();
    }

    public function test_registers_and_orders_by_position_then_registration(): void
    {
        $this->registry->register(new NavigationItem('API Playground', 'api-playground', position: 20));
        $this->registry->register(new NavigationItem('Operations', 'operations', position: 10));

        $labels = array_map(static fn (NavigationItem $i): string => $i->label, $this->registry->all());
        self::assertSame(['Operations', 'API Playground'], $labels);
    }

    public function test_duplicate_label_registration_throws(): void
    {
        $this->registry->register(new NavigationItem('Operations', 'operations'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already registered');
        $this->registry->register(new NavigationItem('Operations', 'other'));
    }

    public function test_empty_label_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new NavigationItem('', 'operations'));
    }

    public function test_empty_target_page_slug_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new NavigationItem('Operations', ''));
    }

    public function test_registry_starts_empty(): void
    {
        self::assertSame([], $this->registry->all());
    }
}
