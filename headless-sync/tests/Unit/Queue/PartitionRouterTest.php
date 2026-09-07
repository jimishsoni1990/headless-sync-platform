<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Queue;

use HSP\Core\Queue\PartitionRouter;
use PHPUnit\Framework\TestCase;

/**
 * P2-S1 acceptance — domain → partition routing (DECISION AG AG-4).
 *
 * DECISION L (v1.12) hardcoded the dispatcher to 'content' and deferred this routing
 * "to a future ADR when a second domain is introduced". These assertions define what that
 * ruling actually requires, including the part that is easy to get backwards: uniqueness
 * runs FROM a domain, not TO a partition.
 */
final class PartitionRouterTest extends TestCase
{
    private function router(): PartitionRouter
    {
        return new PartitionRouter(['content', 'commerce', 'system']);
    }

    public function testRoutesEventTypesByDomain(): void
    {
        $router = $this->router();
        $router->register('content', 'content');
        $router->register('commerce', 'commerce');

        self::assertSame('content', $router->partitionForEventType('content.post.updated'));
        self::assertSame('commerce', $router->partitionForEventType('commerce.product.created'));
        self::assertSame('commerce', $router->partitionForEventType('commerce.inventory.changed'));
    }

    public function testUnroutableEventRaisesRatherThanBeingParkedElsewhere(): void
    {
        $router = $this->router();
        $router->register('content', 'content');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no partition registered for domain/');

        // Before AG-4 this event would have been enqueued into 'content' — another domain's
        // queue — where the content worker would claim it and fail to resolve a handler.
        $router->partitionForEventType('commerce.product.created');
    }

    public function testMalformedEventTypeIsRejected(): void
    {
        $router = $this->router();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a fully-qualified event type/');

        $router->partitionForEventType('notqualified');
    }

    public function testConflictingRoutingForOneDomainFailsLoudly(): void
    {
        $router = $this->router();
        $router->register('commerce', 'commerce');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Conflicting partition routing/');

        $router->register('commerce', 'content');
    }

    public function testRegisteringTheSameMappingTwiceIsIdempotent(): void
    {
        $router = $this->router();
        $router->register('content', 'content');
        $router->register('content', 'content');

        self::assertSame(['content'], $router->activePartitions());
    }

    /**
     * Two domains MAY share one physical partition — a future topology could funnel several
     * low-volume domains into one queue. Only the mapping from a domain must be unique.
     */
    public function testTwoDomainsMayShareOnePartition(): void
    {
        $router = $this->router();
        $router->register('content', 'system');
        $router->register('commerce', 'system');

        self::assertSame('system', $router->partitionForDomain('content'));
        self::assertSame('system', $router->partitionForDomain('commerce'));
        self::assertSame(['system'], $router->activePartitions(), 'de-duplicated');
    }

    public function testPartitionOutsideTheQueueWhitelistIsRejectedAtRegistration(): void
    {
        $router = $this->router();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/is not a valid queue partition/');

        // Catching this at registration turns what would be an enqueue failure deep inside a
        // cron cycle into a boot-time error.
        $router->register('membership', 'membership');
    }

    public function testActivePartitionsAreEmptyUntilADomainRegisters(): void
    {
        self::assertSame([], $this->router()->activePartitions());
    }
}
