<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Services;

use HSP\Core\Operations\Services\ConsoleStateStore;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConsoleStateStore (Doc 12 §9 — request-scope, zero persistence, zero PG).
 */
final class ConsoleStateStoreTest extends TestCase
{
    private ConsoleStateStore $store;

    protected function setUp(): void
    {
        $this->store = new ConsoleStateStore();
    }

    public function test_put_then_get_round_trips_the_snapshot(): void
    {
        $snapshot = ['depth' => 5];
        $this->store->put('queue', $snapshot);

        self::assertTrue($this->store->has('queue'));
        self::assertSame($snapshot, $this->store->get('queue'));
    }

    public function test_get_throws_for_missing_key(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No snapshot stored');
        $this->store->get('queue');
    }

    public function test_empty_key_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->store->put('', 'x');
    }

    public function test_all_returns_every_snapshot_keyed_by_provider(): void
    {
        $this->store->put('queue', 1);
        $this->store->put('workers', 2);

        self::assertSame(['queue' => 1, 'workers' => 2], $this->store->all());
    }

    public function test_clear_discards_all_snapshots(): void
    {
        $this->store->put('queue', 1);
        $this->store->clear();

        self::assertFalse($this->store->has('queue'));
        self::assertSame([], $this->store->all());
    }

    public function test_put_overwrites_prior_snapshot_for_same_key(): void
    {
        $this->store->put('queue', 1);
        $this->store->put('queue', 2);
        self::assertSame(2, $this->store->get('queue'));
    }
}
