<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Registries;

use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Operations\Registries\WidgetRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WidgetRegistry (Doc 12 §7; ADR-048/ADR-052).
 */
final class WidgetRegistryTest extends TestCase
{
    private WidgetRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new WidgetRegistry();
    }

    public function test_registers_and_lists_all(): void
    {
        $this->registry->register(new ConsoleWidget('queue-depth', 'Queue Depth', 'operations', 'queue'));
        $this->registry->register(new ConsoleWidget('worker-health', 'Workers', 'operations', 'workers'));

        self::assertCount(2, $this->registry->all());
    }

    public function test_for_page_filters_by_page_slug_preserving_order(): void
    {
        $this->registry->register(new ConsoleWidget('w1', 'W1', 'operations', 'queue', position: 20));
        $this->registry->register(new ConsoleWidget('w2', 'W2', 'playground', 'endpoints', position: 10));
        $this->registry->register(new ConsoleWidget('w3', 'W3', 'operations', 'workers', position: 10));

        $ids = array_map(
            static fn (ConsoleWidget $w): string => $w->id,
            $this->registry->forPage('operations'),
        );
        // Ordered by position: w3 (10) before w1 (20); 'playground' widget excluded.
        self::assertSame(['w3', 'w1'], $ids);
    }

    public function test_duplicate_id_registration_throws(): void
    {
        $this->registry->register(new ConsoleWidget('queue-depth', 'Queue Depth', 'operations', 'queue'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already registered');
        $this->registry->register(new ConsoleWidget('queue-depth', 'Dup', 'operations', 'queue'));
    }

    public function test_empty_id_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new ConsoleWidget('', 'W', 'operations', 'queue'));
    }

    public function test_empty_page_slug_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new ConsoleWidget('w', 'W', '', 'queue'));
    }

    public function test_empty_provider_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new ConsoleWidget('w', 'W', 'operations', ''));
    }
}
