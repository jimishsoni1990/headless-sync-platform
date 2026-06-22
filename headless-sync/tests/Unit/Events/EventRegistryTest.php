<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events;

use HSP\Core\Events\EventRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for EventRegistry.
 *
 * Verified:
 *   register()              — explicit registration only; no reflection
 *   has()                   — true after registration; false before
 *   getHandlers()           — returns handlers in registration order
 *   getRegisteredTypes()    — lists all registered types
 *   multiple handlers       — same type can have multiple handlers
 *   validation              — empty string rejected
 *   validation              — fewer than two dots rejected (OPEN-1 naming)
 *   two-dot boundary        — three-part names accepted (e.g. content.post.updated)
 *   longer names            — four+ parts accepted (domain.aggregate.action.sub)
 */
final class EventRegistryTest extends TestCase
{
    private EventRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new EventRegistry();
    }

    // -------------------------------------------------------------------------
    // has()
    // -------------------------------------------------------------------------

    public function test_has_returns_false_before_registration(): void
    {
        self::assertFalse($this->registry->has('content.post.updated'));
    }

    public function test_has_returns_true_after_registration(): void
    {
        $this->registry->register('content.post.updated', fn () => null);
        self::assertTrue($this->registry->has('content.post.updated'));
    }

    // -------------------------------------------------------------------------
    // register() + getHandlers()
    // -------------------------------------------------------------------------

    public function test_register_single_handler_and_retrieve(): void
    {
        $handler = fn () => null;
        $this->registry->register('content.page.created', $handler);

        $handlers = $this->registry->getHandlers('content.page.created');
        self::assertCount(1, $handlers);
        self::assertSame($handler, $handlers[0]);
    }

    public function test_register_multiple_handlers_same_type_in_order(): void
    {
        $h1 = fn () => 'first';
        $h2 = fn () => 'second';
        $h3 = fn () => 'third';

        $this->registry->register('content.post.deleted', $h1);
        $this->registry->register('content.post.deleted', $h2);
        $this->registry->register('content.post.deleted', $h3);

        $handlers = $this->registry->getHandlers('content.post.deleted');
        self::assertCount(3, $handlers);
        self::assertSame($h1, $handlers[0]);
        self::assertSame($h2, $handlers[1]);
        self::assertSame($h3, $handlers[2]);
    }

    public function test_get_handlers_returns_empty_array_for_unknown_type(): void
    {
        self::assertSame([], $this->registry->getHandlers('content.post.updated'));
    }

    // -------------------------------------------------------------------------
    // getRegisteredTypes()
    // -------------------------------------------------------------------------

    public function test_get_registered_types_is_empty_initially(): void
    {
        self::assertSame([], $this->registry->getRegisteredTypes());
    }

    public function test_get_registered_types_returns_all_registered(): void
    {
        $this->registry->register('content.post.created', fn () => null);
        $this->registry->register('content.page.updated', fn () => null);

        $types = $this->registry->getRegisteredTypes();
        self::assertContains('content.post.created', $types);
        self::assertContains('content.page.updated', $types);
        self::assertCount(2, $types);
    }

    public function test_get_registered_types_does_not_duplicate_on_multiple_handlers(): void
    {
        $this->registry->register('content.category.created', fn () => null);
        $this->registry->register('content.category.created', fn () => null);

        self::assertCount(1, $this->registry->getRegisteredTypes());
    }

    // -------------------------------------------------------------------------
    // Validation (OPEN-1 naming convention)
    // -------------------------------------------------------------------------

    public function test_register_rejects_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register('', fn () => null);
    }

    public function test_register_rejects_single_word(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register('post', fn () => null);
    }

    public function test_register_rejects_two_part_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register('content.post', fn () => null);
    }

    public function test_register_accepts_three_part_name(): void
    {
        $this->registry->register('content.post.updated', fn () => null);
        self::assertTrue($this->registry->has('content.post.updated'));
    }

    public function test_register_accepts_four_part_name(): void
    {
        $this->registry->register('content.post.updated.v2', fn () => null);
        self::assertTrue($this->registry->has('content.post.updated.v2'));
    }

    // -------------------------------------------------------------------------
    // Explicit registration only — no magic
    // -------------------------------------------------------------------------

    public function test_registry_has_no_pre_registered_handlers(): void
    {
        self::assertSame([], $this->registry->getRegisteredTypes());
        self::assertFalse($this->registry->has('content.post.created'));
    }
}
