<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Replay;

use HSP\Modules\Content\EventProvider;
use HSP\Modules\Content\Replay\ContentReplayEmitter;
use HSP\Tests\Unit\Content\FakeOutboxWriter;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * ContentReplayEmitter — DECISION T point 2 (.updated vs .deleted decision) and
 * point 4 (correlation/causation traceability).
 *
 * The emitter reads CURRENT WordPress state (fake loader) and emits through the real
 * EventProvider onto a FakeOutboxWriter, so these tests exercise the actual emit path
 * (aggregate-type mapping, OPEN-1 event type) without a database.
 */
final class ContentReplayEmitterTest extends TestCase
{
    private FakeOutboxWriter $outbox;
    private FakeWpContentLoader $loader;
    private ContentReplayEmitter $emitter;

    protected function setUp(): void
    {
        $this->outbox  = new FakeOutboxWriter();
        $this->loader  = new FakeWpContentLoader();
        $this->emitter = new ContentReplayEmitter(
            new EventProvider($this->outbox),
            $this->loader,
        );
    }

    public function testSupportsThreeContentAggregateTypes(): void
    {
        self::assertSame(['page', 'post', 'category'], $this->emitter->getSupportedAggregateTypes());
    }

    public function testPublishedPostEmitsUpdated(): void
    {
        $this->loader->postResult = ['ID' => 42, 'post_status' => 'publish', 'post_type' => 'post'];

        $this->emitter->emitForAggregate('post', '42', 'corr-1', 'cause-1');

        $write = $this->outbox->lastWrite();
        self::assertSame('content.post.updated', $write['eventType']);
        self::assertSame('post', $write['aggregateType']);
        self::assertSame('42', $write['aggregateId']);
    }

    public function testNonPublicPostEmitsDeleted(): void
    {
        $this->loader->postResult = ['ID' => 42, 'post_status' => 'draft', 'post_type' => 'post'];

        $this->emitter->emitForAggregate('post', '42', 'corr-1', 'cause-1');

        self::assertSame('content.post.deleted', $this->outbox->lastWrite()['eventType']);
    }

    public function testMissingPostEmitsDeletedTombstone(): void
    {
        // Entity deleted from WordPress during an outage window → replay tombstones it.
        $this->loader->postResult = null;

        $this->emitter->emitForAggregate('post', '999', 'corr-1', 'cause-1');

        self::assertSame('content.post.deleted', $this->outbox->lastWrite()['eventType']);
    }

    public function testPublishedPageEmitsUpdated(): void
    {
        $this->loader->postResult = ['ID' => 7, 'post_status' => 'publish', 'post_type' => 'page'];

        $this->emitter->emitForAggregate('page', '7', 'corr-1', 'cause-1');

        self::assertSame('content.page.updated', $this->outbox->lastWrite()['eventType']);
    }

    public function testExistingCategoryEmitsUpdated(): void
    {
        $this->loader->termResult = ['term_id' => 5, 'name' => 'News', 'slug' => 'news'];

        $this->emitter->emitForAggregate('category', '5', 'corr-1', 'cause-1');

        self::assertSame('content.category.updated', $this->outbox->lastWrite()['eventType']);
    }

    public function testMissingCategoryEmitsDeleted(): void
    {
        $this->loader->termResult = null;

        $this->emitter->emitForAggregate('category', '5', 'corr-1', 'cause-1');

        self::assertSame('content.category.deleted', $this->outbox->lastWrite()['eventType']);
    }

    public function testTraceabilityIdsFlowThroughToOutbox(): void
    {
        $this->loader->postResult = ['ID' => 42, 'post_status' => 'publish', 'post_type' => 'post'];

        $event = $this->emitter->emitForAggregate('post', '42', 'run-correlation', 'replay-causation');

        $write = $this->outbox->lastWrite();
        self::assertSame('run-correlation', $write['correlationId']);
        self::assertSame('replay-causation', $write['causationId']);
        self::assertSame('run-correlation', $event->getCorrelationId());
        self::assertSame('replay-causation', $event->getCausationId());
    }

    public function testPayloadMarksReplayForTraceability(): void
    {
        $this->loader->postResult = ['ID' => 42, 'post_status' => 'publish', 'post_type' => 'post'];

        $this->emitter->emitForAggregate('post', '42', 'corr-1', 'cause-1');

        $payload = $this->outbox->lastWrite()['payload'];
        self::assertTrue($payload['replay']);
        self::assertSame('post', $payload['aggregate_type']);
        self::assertSame('42', $payload['aggregate_id']);
    }

    public function testUnsupportedAggregateTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->emitter->emitForAggregate('widget', '1', 'corr-1', 'cause-1');
    }
}
