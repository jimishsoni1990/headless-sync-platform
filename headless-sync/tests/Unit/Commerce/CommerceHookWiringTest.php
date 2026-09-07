<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\HookWiring;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — WooCommerce capture (Rule 3, DECISION 1, AG-13).
 *
 * Three behaviours here are the difference between a working module and a subtly broken one:
 *
 *  1. FIRST-EMIT-WINS. One product save fires `woocommerce_update_product` AND `save_post`,
 *     so without a per-request guard a single edit writes several outbox rows and burns
 *     several aggregate versions. Collapsing them is only safe because processing is state
 *     sync (ADR-044): the one emitted event reloads the product's final state.
 *
 *  2. DELETION IS TERMINAL. It is exempt from the guard and BLOCKS later upserts for that id,
 *     so a create-then-delete inside one request cannot leave the projection serving a
 *     product that no longer exists.
 *
 *  3. UNSUPPORTED TYPES ARE NEVER CAPTURED (AG-13). Filtering at the edge is what keeps them
 *     out of the retry/DLQ path entirely — the alternative, discovering the type at
 *     processing time and failing, is exactly what AG-13 forbids.
 */
final class CommerceHookWiringTest extends TestCase
{
    private SpyEventProvider $events;
    private HookWiring $hooks;

    protected function setUp(): void
    {
        $this->events = new SpyEventProvider();
        $this->hooks  = new HookWiring($this->events);

        // The wiring reads the product's type through wc_get_product(); the suite has no
        // WooCommerce, so the stub below is driven by this global.
        $GLOBALS['_hsp_test_product_types'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_test_product_types']);
    }

    private function givenProduct(int $id, string $type): void
    {
        $GLOBALS['_hsp_test_product_types'][$id] = $type;
    }

    // -------------------------------------------------------------------------
    // Emission
    // -------------------------------------------------------------------------

    public function testCreatingASupportedProductEmitsOneCreatedEvent(): void
    {
        $this->givenProduct(42, 'simple');

        $this->hooks->onProductCreated(42);

        self::assertSame(
            [[CommerceEventTypes::PRODUCT_CREATED, '42']],
            $this->events->emitted,
        );
    }

    public function testUpdatingASupportedProductEmitsOneUpdatedEvent(): void
    {
        $this->givenProduct(42, 'variable');

        $this->hooks->onProductUpdated(42);

        self::assertSame(
            [[CommerceEventTypes::PRODUCT_UPDATED, '42']],
            $this->events->emitted,
        );
    }

    /**
     * The guard that stops one logical save becoming several outbox rows. WooCommerce fires
     * its own CRUD hook and WordPress fires `save_post` for the same edit.
     */
    public function testSeveralHooksForOneSaveEmitExactlyOnce(): void
    {
        $this->givenProduct(42, 'simple');

        $this->hooks->onProductCreated(42);
        $this->hooks->onProductUpdated(42);
        $this->hooks->onProductUpdated(42);

        self::assertCount(1, $this->events->emitted);
    }

    public function testTheGuardIsPerProductNotGlobal(): void
    {
        $this->givenProduct(1, 'simple');
        $this->givenProduct(2, 'simple');

        $this->hooks->onProductUpdated(1);
        $this->hooks->onProductUpdated(2);

        self::assertCount(2, $this->events->emitted, 'two products are two changes');
    }

    // -------------------------------------------------------------------------
    // AG-13 — unsupported types never enter the pipeline
    // -------------------------------------------------------------------------

    public function testUnsupportedProductTypesAreNotCaptured(): void
    {
        $this->givenProduct(42, 'grouped');
        $this->givenProduct(43, 'external');

        $this->hooks->onProductCreated(42);
        $this->hooks->onProductUpdated(43);

        self::assertSame(
            [],
            $this->events->emitted,
            'an out-of-scope product must never reach the queue, so it can never retry or dead-letter',
        );
    }

    // -------------------------------------------------------------------------
    // Deletion — via WordPress hooks, because WooCommerce has none
    // -------------------------------------------------------------------------

    /**
     * Verified against WooCommerce 11.1.0: there is no `woocommerce_delete_product` hook, so
     * deletion is captured from WordPress's own post lifecycle.
     */
    public function testDeletingAProductEmitsADeletedEventFromTheWordPressHook(): void
    {
        $post = (object) ['post_type' => 'product'];

        $this->hooks->onAfterDeletePost(42, $post);

        self::assertSame(
            [[CommerceEventTypes::PRODUCT_DELETED, '42']],
            $this->events->emitted,
        );
    }

    public function testDeletingANonProductPostIsIgnored(): void
    {
        $this->hooks->onAfterDeletePost(42, (object) ['post_type' => 'post']);
        $this->hooks->onAfterDeletePost(43, null);

        self::assertSame([], $this->events->emitted);
    }

    /**
     * Deletion is emitted even for a type the module does not project: the product may have
     * been supported when it was projected, and its row must be tombstoned regardless of what
     * its type reads as now — which at delete time may not be readable at all.
     */
    public function testDeletionIsEmittedRegardlessOfProductType(): void
    {
        $this->givenProduct(42, 'grouped');

        $this->hooks->onAfterDeletePost(42, (object) ['post_type' => 'product']);

        self::assertSame([[CommerceEventTypes::PRODUCT_DELETED, '42']], $this->events->emitted);
    }

    /**
     * Terminal: a create-then-delete in one request must not leave the projection serving a
     * product that no longer exists.
     */
    public function testDeletionBlocksALaterUpsertInTheSameRequest(): void
    {
        $this->givenProduct(42, 'simple');

        $this->hooks->onAfterDeletePost(42, (object) ['post_type' => 'product']);
        $this->hooks->onProductUpdated(42);

        self::assertSame([[CommerceEventTypes::PRODUCT_DELETED, '42']], $this->events->emitted);
    }

    public function testDeletionItselfIsNotEmittedTwice(): void
    {
        $this->hooks->onAfterDeletePost(42, (object) ['post_type' => 'product']);
        $this->hooks->onAfterDeletePost(42, (object) ['post_type' => 'product']);

        self::assertCount(1, $this->events->emitted);
    }

    public function testAnInvalidProductIdIsIgnored(): void
    {
        $this->hooks->onProductUpdated(0);
        $this->hooks->onProductUpdated(-1);

        self::assertSame([], $this->events->emitted);
    }
}

/** Records what was emitted, without writing an outbox row. */
final class SpyEventProvider implements EventProviderInterface
{
    /** @var list<array{0:string,1:string}> */
    public array $emitted = [];

    /** @return string[] */
    public function getSupportedEventTypes(): array
    {
        return CommerceEventTypes::ALL;
    }

    /** @param array<string,mixed> $context */
    public function provide(string $eventType, string $aggregateId, array $context = []): EventInterface
    {
        $this->emitted[] = [$eventType, $aggregateId];

        return new FakeCommerceEvent($eventType, 'product', $aggregateId);
    }
}
