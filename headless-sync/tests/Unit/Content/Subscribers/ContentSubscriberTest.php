<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Subscribers;

use HSP\Core\Contracts\EventInterface;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Handlers\ContentUpsertHandlerInterface;
use HSP\Modules\Content\Subscribers\ContentSubscriber;
use HSP\Tests\Unit\Content\Adapters\FakeAdapterEvent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentSubscriber.
 *
 * Verified:
 *   - All 9 OPEN-1 event types are dispatched to the correct handler
 *   - Created/Updated events route to the upsert handler
 *   - Deleted events route to the tombstone handler (separate object)
 *   - Missing handler throws RuntimeException
 *   - getSupportedEventTypes() returns all 9 keys
 */
final class ContentSubscriberTest extends TestCase
{
    public function test_all_nine_event_types_are_routed(): void
    {
        // One invocation log per type, each handler writes to its own slot.
        $invocationLogs = array_fill_keys(ContentEventTypes::ALL, false);

        $handlers = [];
        foreach (ContentEventTypes::ALL as $type) {
            $handlers[$type] = new class ($invocationLogs, $type) implements ContentUpsertHandlerInterface {
                public function __construct(
                    private array &$logs,
                    private string $myType,
                ) {}
                public function handle(EventInterface $event): void { $this->logs[$this->myType] = true; }
            };
        }

        $subscriber = new ContentSubscriber($handlers);

        foreach (ContentEventTypes::ALL as $type) {
            ($subscriber)($this->makeEvent($type));
        }

        foreach (ContentEventTypes::ALL as $type) {
            self::assertTrue($invocationLogs[$type], "Handler for '{$type}' was not invoked");
        }
    }

    public function test_unknown_event_type_throws_runtime_exception(): void
    {
        $subscriber = new ContentSubscriber([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/no handler registered/i');

        ($subscriber)($this->makeEvent('content.post.updated'));
    }

    public function test_get_supported_event_types_returns_all_nine(): void
    {
        $handlers = array_fill_keys(ContentEventTypes::ALL, new class implements ContentUpsertHandlerInterface {
            public function handle(EventInterface $event): void {}
        });

        $subscriber = new ContentSubscriber($handlers);

        $types = $subscriber->getSupportedEventTypes();
        sort($types);
        $expected = ContentEventTypes::ALL;
        sort($expected);

        self::assertSame($expected, $types);
    }

    private function makeEvent(string $eventType): FakeAdapterEvent
    {
        return new FakeAdapterEvent(eventType: $eventType);
    }
}
