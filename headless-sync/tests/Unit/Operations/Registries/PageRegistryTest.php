<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Registries;

use HSP\Core\Contracts\Operations\ConsolePage;
use HSP\Core\Operations\Registries\PageRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageRegistry (ADR-048/ADR-052 — explicit registration, no reflection).
 */
final class PageRegistryTest extends TestCase
{
    private PageRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PageRegistry();
    }

    public function test_has_is_false_before_registration_and_true_after(): void
    {
        self::assertFalse($this->registry->has('operations'));
        $this->registry->register(new ConsolePage('operations', 'Operations', 'manage_options'));
        self::assertTrue($this->registry->has('operations'));
    }

    public function test_get_returns_registered_page(): void
    {
        $page = new ConsolePage('operations', 'Operations', 'manage_options');
        $this->registry->register($page);
        self::assertSame($page, $this->registry->get('operations'));
    }

    public function test_get_throws_for_unknown_slug(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No console page registered');
        $this->registry->get('nope');
    }

    public function test_duplicate_slug_registration_throws(): void
    {
        $this->registry->register(new ConsolePage('operations', 'Operations', 'manage_options'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already registered');
        $this->registry->register(new ConsolePage('operations', 'Ops 2', 'manage_options'));
    }

    public function test_empty_slug_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(new ConsolePage('', 'Operations', 'manage_options'));
    }

    public function test_all_is_ordered_by_position_then_registration_order(): void
    {
        $this->registry->register(new ConsolePage('b', 'B', 'cap', position: 20));
        $this->registry->register(new ConsolePage('a', 'A', 'cap', position: 10));
        // Same position as 'a' → tie broken by registration order (registered after 'a').
        $this->registry->register(new ConsolePage('a2', 'A2', 'cap', position: 10));

        $slugs = array_map(static fn (ConsolePage $p): string => $p->slug, $this->registry->all());
        self::assertSame(['a', 'a2', 'b'], $slugs);
    }

    public function test_registry_starts_empty(): void
    {
        self::assertSame([], $this->registry->all());
    }
}
