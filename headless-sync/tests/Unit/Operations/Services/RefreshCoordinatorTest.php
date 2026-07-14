<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Services;

use HSP\Core\Operations\Services\ConsoleStateStore;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Tests\Unit\Operations\Fakes\FakeBareProvider;
use HSP\Tests\Unit\Operations\Fakes\FakeEndpointProvider;
use HSP\Tests\Unit\Operations\Fakes\FakeHealthProvider;
use HSP\Tests\Unit\Operations\Fakes\FakeMetricsProvider;
use HSP\Tests\Unit\Operations\Fakes\FakeQueueStatusProvider;
use HSP\Tests\Unit\Operations\Fakes\FakeWorkerStatusProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RefreshCoordinator (Doc 12 §8 — centralized refresh, no independent polling).
 */
final class RefreshCoordinatorTest extends TestCase
{
    private ConsoleStateStore $store;
    private RefreshCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->store       = new ConsoleStateStore();
        $this->coordinator = new RefreshCoordinator($this->store);
    }

    public function test_refresh_invokes_each_provider_once_and_stores_snapshots(): void
    {
        $health = new FakeHealthProvider('health');
        $queue  = new FakeQueueStatusProvider('queue');
        $this->coordinator->addProvider($health);
        $this->coordinator->addProvider($queue);

        $count = $this->coordinator->refresh();

        self::assertSame(2, $count);
        self::assertSame(1, $health->calls);
        self::assertSame(1, $queue->calls);
        self::assertTrue($this->store->has('health'));
        self::assertTrue($this->store->has('queue'));
    }

    public function test_snapshot_extraction_maps_each_provider_to_its_data_method(): void
    {
        // One of every known provider interface — proves the match() in snapshot() routes
        // each to its correct data method and stores the returned DTO(s).
        $this->coordinator->addProvider(new FakeHealthProvider('health'));
        $this->coordinator->addProvider(new FakeMetricsProvider('metrics'));
        $this->coordinator->addProvider(new FakeWorkerStatusProvider('workers'));
        $this->coordinator->addProvider(new FakeQueueStatusProvider('queue'));
        $this->coordinator->addProvider(new FakeEndpointProvider('endpoints'));

        $this->coordinator->refresh();

        self::assertSame('database', $this->store->get('health')[0]->component);
        self::assertSame('queue_depth', $this->store->get('metrics')[0]->name);
        self::assertSame('event', $this->store->get('workers')[0]->workerType);
        self::assertSame(3, $this->store->get('queue')->depth);
        self::assertSame('/posts', $this->store->get('endpoints')[0]->route);
    }

    public function test_refresh_one_refreshes_a_single_provider(): void
    {
        $health = new FakeHealthProvider('health');
        $queue  = new FakeQueueStatusProvider('queue');
        $this->coordinator->addProvider($health);
        $this->coordinator->addProvider($queue);

        $this->coordinator->refreshOne('queue');

        self::assertSame(0, $health->calls);
        self::assertSame(1, $queue->calls);
        self::assertTrue($this->store->has('queue'));
        self::assertFalse($this->store->has('health'));
    }

    public function test_refresh_one_throws_for_unknown_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No provider registered');
        $this->coordinator->refreshOne('nope');
    }

    public function test_duplicate_provider_key_registration_throws(): void
    {
        $this->coordinator->addProvider(new FakeHealthProvider('health'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already registered');
        $this->coordinator->addProvider(new FakeHealthProvider('health'));
    }

    public function test_provider_without_a_known_data_interface_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not implement a known Operations data-provider');
        $this->coordinator->addProvider(new FakeBareProvider('bare'));
    }

    public function test_keys_lists_registered_providers(): void
    {
        $this->coordinator->addProvider(new FakeHealthProvider('health'));
        $this->coordinator->addProvider(new FakeQueueStatusProvider('queue'));
        self::assertSame(['health', 'queue'], $this->coordinator->keys());
    }
}
