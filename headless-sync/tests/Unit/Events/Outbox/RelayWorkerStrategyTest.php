<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events\Outbox;

use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;
use HSP\Core\Database\Exception\DatabaseException;
use PHPUnit\Framework\TestCase;

// Load the split fake classes — defined in FakeOutboxConnection.php.
require_once __DIR__ . '/FakeOutboxConnection.php';

/**
 * Unit tests for RelayWorkerStrategy.
 *
 * Verifies:
 *   - OPEN-4 claim protocol: SELECT FOR UPDATE SKIP LOCKED inside a MySQL transaction
 *   - OPEN-6 v1.3 relay fidelity: id and created_at preserved; ON CONFLICT DO NOTHING
 *   - Single-transaction design: BEGIN … (PG insert + MySQL mark-relayed per row) … COMMIT
 *   - No intermediate 'relaying' status — ENUM('pending','relayed') only
 *   - DECISION 1: PG insert executed independently; no shared cross-DB transaction
 *   - DECISION E v1.6: MySQL capture path on FakeMysqlOutboxConnection;
 *                       PG delivery path on FakePgsqlOutboxConnection
 *
 * All tests use split fakes — no real database.
 */
final class RelayWorkerStrategyTest extends TestCase
{
    private FakeMysqlOutboxConnection $mysql;
    private FakePgsqlOutboxConnection $pgsql;
    private RelayWorkerStrategy       $relay;

    protected function setUp(): void
    {
        $this->mysql = new FakeMysqlOutboxConnection();
        $this->pgsql = new FakePgsqlOutboxConnection();
        $this->relay = new RelayWorkerStrategy($this->mysql, $this->pgsql, 'wp_', 10);
    }

    private function makeRow(string $id = 'abc-123'): array
    {
        return [
            'id'                => $id,
            'event_type'        => 'content.post.created',
            'event_version'     => '1',
            'aggregate_type'    => 'post',
            'aggregate_id'      => '42',
            'aggregate_version' => '7',
            'source_updated_at' => '2026-01-15 10:00:00',
            'checksum'          => str_repeat('a', 64),
            'correlation_id'    => 'corr-uuid-0001',
            'causation_id'      => null,
            'payload'           => '{"title":"Hello"}',
            'created_at'        => '2026-01-15 09:59:50',
        ];
    }

    // -------------------------------------------------------------------------
    // tick() — empty queue
    // -------------------------------------------------------------------------

    public function test_tick_returns_false_when_queue_empty(): void
    {
        $this->mysql->nextQueryRows = [];

        self::assertFalse($this->relay->tick());
    }

    public function test_tick_does_not_touch_pgsql_when_queue_empty(): void
    {
        $this->mysql->nextQueryRows = [];

        $this->relay->tick();

        self::assertEmpty($this->pgsql->executeCalls);
    }

    public function test_tick_rolls_back_and_returns_false_on_empty_queue(): void
    {
        $this->mysql->nextQueryRows = [];

        $this->relay->tick();

        // Empty queue: BEGIN was called, rollback used to release the txn cleanly.
        self::assertSame(1, $this->mysql->beginCount);
        self::assertSame(1, $this->mysql->rollbackCount);
        self::assertSame(0, $this->mysql->commitCount);
    }

    // -------------------------------------------------------------------------
    // tick() — one row relayed
    // -------------------------------------------------------------------------

    public function test_tick_returns_true_when_row_relayed(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow()];

        self::assertTrue($this->relay->tick());
    }

    public function test_tick_wraps_entire_batch_in_one_mysql_transaction(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow()];

        $this->relay->tick();

        self::assertSame(1, $this->mysql->beginCount,  'BEGIN must be called once');
        self::assertSame(1, $this->mysql->commitCount, 'COMMIT must be called once');
        self::assertSame(0, $this->mysql->rollbackCount);
    }

    public function test_tick_issues_skip_locked_claim_query(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow()];

        $this->relay->tick();

        $claimSql = $this->mysql->queryCalls[0]['sql'];
        self::assertStringContainsStringIgnoringCase('FOR UPDATE', $claimSql);
        self::assertStringContainsStringIgnoringCase('SKIP LOCKED', $claimSql);
        self::assertStringContainsStringIgnoringCase("'pending'", $claimSql);
        self::assertStringContainsStringIgnoringCase('wp_hsp_outbox', $claimSql);
    }

    public function test_tick_does_not_use_relaying_intermediate_status(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow()];

        $this->relay->tick();

        $allMysqlSql = array_merge(
            array_column($this->mysql->queryCalls, 'sql'),
            array_column($this->mysql->executeCalls, 'sql'),
        );

        foreach ($allMysqlSql as $sql) {
            self::assertStringNotContainsStringIgnoringCase(
                'relaying',
                $sql,
                "No 'relaying' intermediate status should appear — ENUM is ('pending','relayed') only",
            );
        }
    }

    public function test_tick_inserts_row_into_system_events_with_on_conflict_do_nothing(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow()];

        $this->relay->tick();

        self::assertNotEmpty($this->pgsql->executeCalls);
        $pgSql = $this->pgsql->executeCalls[0]['sql'];
        self::assertStringContainsStringIgnoringCase('system.events', $pgSql);
        self::assertStringContainsStringIgnoringCase('ON CONFLICT', $pgSql);
        self::assertStringContainsStringIgnoringCase('DO NOTHING', $pgSql);
    }

    public function test_tick_preserves_event_id_on_relay(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow('original-event-id')];

        $this->relay->tick();

        $pgParams = $this->pgsql->executeCalls[0]['params'];
        self::assertSame('original-event-id', $pgParams[0], 'event_id must be preserved (OPEN-6 v1.3)');
    }

    public function test_tick_preserves_created_at_on_relay(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow()];

        $this->relay->tick();

        $pgParams  = $this->pgsql->executeCalls[0]['params'];
        $lastParam = end($pgParams);
        self::assertStringContainsString('2026-01-15 09:59:50', $lastParam);
    }

    public function test_tick_marks_row_relayed_inside_mysql_transaction(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow('evt-abc')];

        $this->relay->tick();

        // The mark-relayed UPDATE must appear in MySQL executeCalls (inside the txn),
        // and the txn must have been committed (not rolled back) afterwards.
        $relayedCall = null;
        foreach ($this->mysql->executeCalls as $call) {
            if (stripos($call['sql'], "'relayed'") !== false) {
                $relayedCall = $call;
                break;
            }
        }

        self::assertNotNull($relayedCall, "Mark-relayed UPDATE must appear in MySQL executeCalls");
        self::assertContains('evt-abc', $relayedCall['params']);
        self::assertSame(1, $this->mysql->commitCount, 'MySQL txn must have been committed');
    }

    public function test_tick_mark_relayed_happens_before_commit(): void
    {
        // The FakeMysqlOutboxConnection records calls in order. We verify that the
        // mark-relayed execute call appears before the commit counter increments.
        $mysql = new class extends FakeMysqlOutboxConnection {
            public array $executeCallsAtCommit = [];
            public function commit(): void {
                $this->executeCallsAtCommit = $this->executeCalls;
                parent::commit();
            }
        };

        $relay = new RelayWorkerStrategy($mysql, $this->pgsql, 'wp_', 10);
        $mysql->nextQueryRows = [$this->makeRow('evt-order')];

        $relay->tick();

        self::assertNotEmpty(
            $mysql->executeCallsAtCommit,
            'mark-relayed UPDATE must have been issued before MySQL COMMIT',
        );

        $hasRelayed = false;
        foreach ($mysql->executeCallsAtCommit as $call) {
            if (stripos($call['sql'], "'relayed'") !== false) {
                $hasRelayed = true;
                break;
            }
        }
        self::assertTrue($hasRelayed, "mark-relayed SQL must appear before COMMIT");
    }

    public function test_tick_sets_relayed_at_as_utc_datetime(): void
    {
        $this->mysql->nextQueryRows = [$this->makeRow()];

        $this->relay->tick();

        $relayedCall = null;
        foreach ($this->mysql->executeCalls as $call) {
            if (stripos($call['sql'], "'relayed'") !== false) {
                $relayedCall = $call;
                break;
            }
        }

        self::assertNotNull($relayedCall);
        $relayedAt = $relayedCall['params'][0];
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            $relayedAt,
            'relayed_at must be a Y-m-d H:i:s UTC string',
        );
    }

    public function test_tick_handles_null_causation_id(): void
    {
        $row                 = $this->makeRow();
        $row['causation_id'] = null;
        $this->mysql->nextQueryRows = [$row];

        $this->relay->tick();

        $pgParams = $this->pgsql->executeCalls[0]['params'];
        self::assertNull($pgParams[9]);
    }

    public function test_tick_handles_empty_string_causation_id_as_null(): void
    {
        $row                 = $this->makeRow();
        $row['causation_id'] = '';
        $this->mysql->nextQueryRows = [$row];

        $this->relay->tick();

        $pgParams = $this->pgsql->executeCalls[0]['params'];
        self::assertNull($pgParams[9]);
    }

    public function test_tick_relays_multiple_rows_in_one_transaction(): void
    {
        $this->mysql->nextQueryRows = [
            $this->makeRow('row-1'),
            $this->makeRow('row-2'),
            $this->makeRow('row-3'),
        ];

        $this->relay->tick();

        self::assertCount(3, $this->pgsql->executeCalls, 'Three PG inserts expected');
        self::assertSame(1, $this->mysql->commitCount,   'Only one MySQL COMMIT for the batch');

        // All three rows must be marked relayed.
        $relayedIds = [];
        foreach ($this->mysql->executeCalls as $call) {
            if (stripos($call['sql'], "'relayed'") !== false) {
                $relayedIds[] = end($call['params']); // id is last param
            }
        }
        self::assertEqualsCanonicalizing(['row-1', 'row-2', 'row-3'], $relayedIds);
    }

    // -------------------------------------------------------------------------
    // Rollback on failure
    // -------------------------------------------------------------------------

    public function test_tick_rolls_back_on_claim_query_failure(): void
    {
        $this->mysql->failNextQuery = true;

        $this->expectException(OutboxWriteException::class);

        try {
            $this->relay->tick();
        } finally {
            self::assertSame(1, $this->mysql->rollbackCount, 'Must rollback on claim failure');
            self::assertSame(0, $this->mysql->commitCount);
        }
    }

    public function test_tick_rolls_back_on_pg_insert_failure(): void
    {
        $this->mysql->nextQueryRows   = [$this->makeRow()];
        $this->pgsql->failNextExecute = true;

        $this->expectException(OutboxWriteException::class);

        try {
            $this->relay->tick();
        } finally {
            self::assertSame(1, $this->mysql->rollbackCount, 'Must rollback when PG insert fails');
            self::assertSame(0, $this->mysql->commitCount);
        }
    }

    // -------------------------------------------------------------------------
    // Worker identity
    // -------------------------------------------------------------------------

    public function test_worker_id_is_uuidv7(): void
    {
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $this->relay->getWorkerId(),
        );
    }

    public function test_queue_names_contains_relay(): void
    {
        self::assertContains('relay', $this->relay->getQueueNames());
    }
}
