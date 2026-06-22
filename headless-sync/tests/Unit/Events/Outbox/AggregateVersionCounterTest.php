<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events\Outbox;

use HSP\Core\Events\Outbox\AggregateVersionCounter;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AggregateVersionCounter.
 *
 * Verifies the DECISION 2 v1.1 contract:
 *   - The atomic INSERT … ON DUPLICATE KEY UPDATE SQL is issued.
 *   - The value from LAST_INSERT_ID() is returned.
 *   - Failure paths throw OutboxWriteException.
 */
final class AggregateVersionCounterTest extends TestCase
{
    private FakeWpdb $wpdb;
    private AggregateVersionCounter $counter;

    protected function setUp(): void
    {
        $this->wpdb    = new FakeWpdb();
        $this->counter = new AggregateVersionCounter($this->wpdb);
    }

    public function test_next_issues_upsert_and_returns_last_insert_id(): void
    {
        $this->wpdb->nextVarResult = '3';

        $version = $this->counter->next('post', '42');

        self::assertSame(3, $version);
        self::assertCount(1, $this->wpdb->queryCalls);

        $sql = $this->wpdb->queryCalls[0];
        self::assertStringContainsStringIgnoringCase('ON DUPLICATE KEY UPDATE', $sql);
        self::assertStringContainsStringIgnoringCase('LAST_INSERT_ID', $sql);
        self::assertStringContainsStringIgnoringCase('hsp_aggregate_counters', $sql);
    }

    public function test_next_returns_one_for_first_aggregate(): void
    {
        $this->wpdb->nextVarResult = '1';

        $version = $this->counter->next('category', '7');

        self::assertSame(1, $version);
    }

    public function test_next_throws_on_query_failure(): void
    {
        $this->wpdb->failNextQuery = true;

        $this->expectException(OutboxWriteException::class);

        $this->counter->next('post', '1');
    }

    public function test_next_throws_when_last_insert_id_returns_null(): void
    {
        $this->wpdb->nextVarResult = null;

        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessageMatches('/LAST_INSERT_ID/i');

        $this->counter->next('post', '1');
    }

    public function test_next_uses_table_prefix(): void
    {
        $this->wpdb->prefix        = 'mysite_';
        $this->wpdb->nextVarResult = '1';

        $this->counter->next('post', '1');

        self::assertStringContainsString('mysite_hsp_aggregate_counters', $this->wpdb->queryCalls[0]);
    }
}
