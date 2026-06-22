<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Queue;

use HSP\Core\Queue\Exception\QueueException;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DatabaseQueueProvider.
 *
 * Verified:
 *   enqueue()          — valid partition, invalid partition rejection, correct SQL shape
 *   claim()            — SKIP LOCKED pattern emitted, empty-queue returns null,
 *                        transaction wraps SELECT+UPDATE, returned row has incremented attempts
 *   complete()         — correct SQL shape, throws on 0 affected
 *   release()          — correct SQL shape with delay, throws on 0 affected
 *   deadLetter()       — DLQ INSERT + queue UPDATE in transaction, payload coercion (DECISION A)
 *   requeueTimedOut()  — correct SQL shape, partition filter present
 *   computeBackoffSeconds() — exponential growth, cap applied, range correct
 *   partition guard    — 'content', 'commerce', 'system' accepted; others rejected
 *
 * All tests use FakeQueueConnection — no real database.
 */
final class DatabaseQueueProviderTest extends TestCase
{
    private FakeQueueConnection   $conn;
    private DatabaseQueueProvider $provider;

    protected function setUp(): void
    {
        $this->conn     = new FakeQueueConnection();
        $this->provider = new DatabaseQueueProvider($this->conn, [
            'retry_limit'               => 10,
            'visibility_timeout_seconds' => 300,
            'backoff_base_seconds'       => 30,
            'backoff_cap_seconds'        => 3600,
        ]);
    }

    // -------------------------------------------------------------------------
    // enqueue()
    // -------------------------------------------------------------------------

    public function test_enqueue_returns_a_uuid_string(): void
    {
        $event = new FakeEvent();
        $jobId = $this->provider->enqueue($event, 'content');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $jobId,
            'enqueue() must return a UUIDv7'
        );
    }

    public function test_enqueue_emits_insert_with_correct_columns(): void
    {
        $event = new FakeEvent('event-uuid-test');
        $this->provider->enqueue($event, 'content');

        self::assertCount(1, $this->conn->executeCalls);
        $sql = $this->conn->executeCalls[0]['sql'];

        self::assertStringContainsString('INSERT INTO system.queue_jobs', $sql);
        self::assertStringContainsString("'available'", $sql);
        self::assertStringContainsString('event_id', $sql);
        self::assertStringContainsString('queue_name', $sql);
    }

    public function test_enqueue_passes_event_id_and_queue_name(): void
    {
        $event = new FakeEvent('my-event-uuid');
        $this->provider->enqueue($event, 'commerce');

        $params = $this->conn->executeCalls[0]['params'];
        self::assertSame('my-event-uuid', $params[1]);
        self::assertSame('commerce', $params[2]);
    }

    public function test_enqueue_rejects_invalid_partition(): void
    {
        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches('/Invalid queue partition/');

        $this->provider->enqueue(new FakeEvent(), 'orders');
    }

    // -------------------------------------------------------------------------
    // claim()
    // -------------------------------------------------------------------------

    public function test_claim_returns_null_when_queue_is_empty(): void
    {
        // queryResultQueue is empty → query() returns []
        $result = $this->provider->claim('content', 'worker-uuid-001');

        self::assertNull($result);
    }

    public function test_claim_wraps_select_and_update_in_a_transaction(): void
    {
        $this->conn->queryResultQueue[] = [
            ['id' => 'job-001', 'event_id' => 'ev-001', 'queue_name' => 'content',
             'status' => 'available', 'attempts' => '0', 'available_at' => '2026-01-01 00:00:00',
             'started_at' => null, 'completed_at' => null, 'last_error' => null,
             'worker_id' => null, 'visibility_timeout_at' => null],
        ];

        $this->provider->claim('content', 'worker-uuid-001');

        self::assertSame(1, $this->conn->beginCount, 'claim() must BEGIN a transaction');
        self::assertSame(1, $this->conn->commitCount, 'claim() must COMMIT');
        self::assertSame(0, $this->conn->rollbackCount);
    }

    public function test_claim_emits_skip_locked_in_select(): void
    {
        $this->conn->queryResultQueue[] = [];

        $this->provider->claim('content', 'worker-uuid-001');

        $sql = $this->conn->queryCalls[0]['sql'];
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $sql);
    }

    public function test_claim_filters_by_queue_name(): void
    {
        $this->conn->queryResultQueue[] = [];

        $this->provider->claim('commerce', 'worker-uuid-001');

        $params = $this->conn->queryCalls[0]['params'];
        self::assertSame('commerce', $params[0]);
    }

    public function test_claim_rolls_back_and_returns_null_on_empty_result(): void
    {
        $this->conn->queryResultQueue[] = [];

        $result = $this->provider->claim('system', 'worker-uuid-001');

        self::assertNull($result);
        self::assertSame(1, $this->conn->rollbackCount);
        self::assertSame(0, $this->conn->commitCount);
    }

    public function test_claim_issues_update_with_worker_id_and_timeout(): void
    {
        $this->conn->queryResultQueue[] = [
            ['id' => 'job-002', 'event_id' => 'ev-002', 'queue_name' => 'content',
             'status' => 'available', 'attempts' => '3', 'available_at' => '2026-01-01 00:00:00',
             'started_at' => null, 'completed_at' => null, 'last_error' => null,
             'worker_id' => null, 'visibility_timeout_at' => null],
        ];

        $this->provider->claim('content', 'worker-abc');

        $updateSql = $this->conn->executeCalls[0]['sql'];
        self::assertStringContainsString('visibility_timeout_at', $updateSql);
        self::assertStringContainsString("status                = 'claimed'", $updateSql);
        self::assertSame('worker-abc', $this->conn->executeCalls[0]['params'][0]);
    }

    public function test_claim_returns_row_with_incremented_attempt_count(): void
    {
        $this->conn->queryResultQueue[] = [
            ['id' => 'job-003', 'event_id' => 'ev-003', 'queue_name' => 'content',
             'status' => 'available', 'attempts' => '2', 'available_at' => '2026-01-01 00:00:00',
             'started_at' => null, 'completed_at' => null, 'last_error' => null,
             'worker_id' => null, 'visibility_timeout_at' => null],
        ];

        $row = $this->provider->claim('content', 'worker-xyz');

        self::assertNotNull($row);
        self::assertSame(3, $row['attempts'], 'Returned attempts must reflect post-increment value');
        self::assertSame('claimed', $row['status']);
        self::assertSame('worker-xyz', $row['worker_id']);
    }

    public function test_claim_rolls_back_and_throws_on_execute_failure(): void
    {
        $this->conn->queryResultQueue[] = [
            ['id' => 'job-err', 'event_id' => 'ev-err', 'queue_name' => 'content',
             'status' => 'available', 'attempts' => '0', 'available_at' => '2026-01-01 00:00:00',
             'started_at' => null, 'completed_at' => null, 'last_error' => null,
             'worker_id' => null, 'visibility_timeout_at' => null],
        ];
        $this->conn->failNextExecute = true;

        $this->expectException(QueueException::class);

        try {
            $this->provider->claim('content', 'worker-fail');
        } finally {
            self::assertSame(1, $this->conn->rollbackCount, 'Must rollback on failure');
        }
    }

    public function test_claim_rejects_invalid_partition(): void
    {
        $this->expectException(QueueException::class);
        $this->provider->claim('unknown', 'worker-001');
    }

    // -------------------------------------------------------------------------
    // complete()
    // -------------------------------------------------------------------------

    public function test_complete_emits_correct_update(): void
    {
        $this->provider->complete('job-uuid-done');

        $sql    = $this->conn->executeCalls[0]['sql'];
        $params = $this->conn->executeCalls[0]['params'];

        self::assertStringContainsString("status                = 'completed'", $sql);
        self::assertStringContainsString('visibility_timeout_at = NULL', $sql);
        self::assertSame('job-uuid-done', $params[0]);
    }

    public function test_complete_throws_when_no_rows_affected(): void
    {
        $this->conn->executeReturnValue = 0;

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches("/not found or not in 'claimed'/");

        $this->provider->complete('ghost-job');
    }

    // -------------------------------------------------------------------------
    // release()
    // -------------------------------------------------------------------------

    public function test_release_emits_update_with_delay(): void
    {
        $this->provider->release('job-uuid-rel', 120);

        $sql    = $this->conn->executeCalls[0]['sql'];
        $params = $this->conn->executeCalls[0]['params'];

        self::assertStringContainsString("status                = 'available'", $sql);
        self::assertStringContainsString('available_at', $sql);
        self::assertSame('120', $params[0]);
        self::assertSame('job-uuid-rel', $params[1]);
    }

    public function test_release_with_zero_delay(): void
    {
        $this->provider->release('job-uuid-now', 0);

        $params = $this->conn->executeCalls[0]['params'];
        self::assertSame('0', $params[0]);
    }

    public function test_release_throws_when_no_rows_affected(): void
    {
        $this->conn->executeReturnValue = 0;

        $this->expectException(QueueException::class);
        $this->provider->release('ghost-job', 30);
    }

    // -------------------------------------------------------------------------
    // deadLetter()
    // -------------------------------------------------------------------------

    public function test_dead_letter_inserts_into_dlq_and_updates_job(): void
    {
        // First query(): returns the job's event_id lookup
        $this->conn->queryResultQueue[] = [['event_id' => 'ev-dl-001']];

        $this->provider->deadLetter('job-dl', 'worker-dl', [
            'failure_reason'  => 'Processing failed',
            'stack_trace'     => 'trace...',
            'attempt_count'   => 10,
            'payload_snapshot' => ['title' => 'Hello'],
        ]);

        // Should have BEGIN + COMMIT
        self::assertSame(1, $this->conn->beginCount);
        self::assertSame(1, $this->conn->commitCount);

        // Two execute calls: INSERT + UPDATE
        self::assertCount(2, $this->conn->executeCalls);

        $insertSql = $this->conn->executeCalls[0]['sql'];
        $updateSql = $this->conn->executeCalls[1]['sql'];

        self::assertStringContainsString('INSERT INTO system.dead_letter_jobs', $insertSql);
        self::assertStringContainsString('payload_snapshot', $insertSql);
        self::assertStringContainsString('stack_trace', $insertSql);
        self::assertStringContainsString("status     = 'dead_lettered'", $updateSql);
    }

    public function test_dead_letter_throws_when_job_not_found(): void
    {
        // event_id lookup returns empty
        $this->conn->queryResultQueue[] = [];

        $this->expectException(QueueException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->provider->deadLetter('ghost', 'worker', [
            'failure_reason'   => 'x',
            'attempt_count'    => 1,
            'payload_snapshot' => null,
        ]);
    }

    public function test_dead_letter_wraps_null_payload_as_raw_json(): void
    {
        $this->conn->queryResultQueue[] = [['event_id' => 'ev-null']];

        $this->provider->deadLetter('job-null', 'worker-null', [
            'failure_reason'   => 'test',
            'attempt_count'    => 1,
            'payload_snapshot' => null,
        ]);

        $insertParams = $this->conn->executeCalls[0]['params'];
        // payload_snapshot is the 8th parameter (index 7)
        $payloadParam = $insertParams[7];
        self::assertSame('{"raw":"null"}', $payloadParam, 'null payload must be wrapped as {"raw":"null"}');
    }

    public function test_dead_letter_wraps_invalid_string_payload_as_raw_json(): void
    {
        $this->conn->queryResultQueue[] = [['event_id' => 'ev-raw']];

        $this->provider->deadLetter('job-raw', 'worker-raw', [
            'failure_reason'   => 'test',
            'attempt_count'    => 1,
            'payload_snapshot' => 'not valid json {{{',
        ]);

        $payloadParam = $this->conn->executeCalls[0]['params'][7];
        self::assertStringContainsString('"raw"', $payloadParam);
        self::assertStringContainsString('not valid json {{{', $payloadParam);
    }

    public function test_dead_letter_accepts_valid_json_string_unchanged(): void
    {
        $this->conn->queryResultQueue[] = [['event_id' => 'ev-json']];

        $json = '{"title":"Hello","id":42}';

        $this->provider->deadLetter('job-json', 'worker-json', [
            'failure_reason'   => 'test',
            'attempt_count'    => 1,
            'payload_snapshot' => $json,
        ]);

        $payloadParam = $this->conn->executeCalls[0]['params'][7];
        self::assertSame($json, $payloadParam);
    }

    public function test_dead_letter_encodes_array_payload_as_json(): void
    {
        $this->conn->queryResultQueue[] = [['event_id' => 'ev-arr']];

        $this->provider->deadLetter('job-arr', 'worker-arr', [
            'failure_reason'   => 'test',
            'attempt_count'    => 1,
            'payload_snapshot' => ['key' => 'value'],
        ]);

        $payloadParam = $this->conn->executeCalls[0]['params'][7];
        self::assertSame('{"key":"value"}', $payloadParam);
    }

    public function test_dead_letter_rolls_back_on_insert_failure(): void
    {
        $this->conn->queryResultQueue[] = [['event_id' => 'ev-fail']];
        $this->conn->failNextExecute    = true;

        $this->expectException(QueueException::class);

        try {
            $this->provider->deadLetter('job-fail', 'worker-fail', [
                'failure_reason'   => 'x',
                'attempt_count'    => 1,
                'payload_snapshot' => null,
            ]);
        } finally {
            self::assertSame(1, $this->conn->rollbackCount);
        }
    }

    // -------------------------------------------------------------------------
    // requeueTimedOut()
    // -------------------------------------------------------------------------

    public function test_requeue_timed_out_emits_update_with_partition_filter(): void
    {
        $this->conn->executeReturnValue = 3;

        $count = $this->provider->requeueTimedOut('content');

        self::assertSame(3, $count);

        $sql    = $this->conn->executeCalls[0]['sql'];
        $params = $this->conn->executeCalls[0]['params'];

        self::assertStringContainsString("'available'", $sql);
        self::assertStringContainsString("'claimed'", $sql);
        self::assertStringContainsString('visibility_timeout_at < NOW()', $sql);
        self::assertSame('content', $params[0]);
    }

    public function test_requeue_timed_out_rejects_invalid_partition(): void
    {
        $this->expectException(QueueException::class);
        $this->provider->requeueTimedOut('invalid');
    }

    // -------------------------------------------------------------------------
    // computeBackoffSeconds() — ADR-022
    // -------------------------------------------------------------------------

    public function test_backoff_attempt_1_equals_base(): void
    {
        // attempt=1 → raw = 30 * 2^0 = 30; capped at 30; result ∈ [30, 37]
        $delay = $this->provider->computeBackoffSeconds(1);
        self::assertGreaterThanOrEqual(30, $delay);
        self::assertLessThanOrEqual(38, $delay); // 30 + 25% = 37.5
    }

    public function test_backoff_doubles_each_attempt(): void
    {
        $d1 = $this->provider->computeBackoffSeconds(1);
        $d2 = $this->provider->computeBackoffSeconds(2);
        $d3 = $this->provider->computeBackoffSeconds(3);

        // Strip the jitter upper-bound check: d2 >= d1 (not guaranteed but very likely over 3 attempts)
        // Use ranges: attempt=2 → [60, 75]; attempt=3 → [120, 150]
        self::assertGreaterThanOrEqual(60, $d2, 'attempt 2 lower bound');
        self::assertLessThanOrEqual(76, $d2, 'attempt 2 upper bound (60 + 25%)');
        self::assertGreaterThanOrEqual(120, $d3, 'attempt 3 lower bound');
        self::assertLessThanOrEqual(151, $d3, 'attempt 3 upper bound (120 + 25%)');
    }

    public function test_backoff_is_capped_at_configured_cap(): void
    {
        // attempt=8 → raw = 30 * 2^7 = 3840 → capped to 3600; result ∈ [3600, 3600 * 1.25]
        $delay = $this->provider->computeBackoffSeconds(8);
        self::assertGreaterThanOrEqual(3600, $delay);
        self::assertLessThanOrEqual(4501, $delay); // 3600 + 25%
    }

    public function test_backoff_for_zero_or_negative_attempt_is_zero(): void
    {
        self::assertSame(0, $this->provider->computeBackoffSeconds(0));
        self::assertSame(0, $this->provider->computeBackoffSeconds(-1));
    }

    public function test_retry_limit_is_configurable(): void
    {
        $provider = new DatabaseQueueProvider($this->conn, ['retry_limit' => 5]);
        self::assertSame(5, $provider->getRetryLimit());
    }

    public function test_default_retry_limit_is_10(): void
    {
        $provider = new DatabaseQueueProvider(new FakeQueueConnection());
        self::assertSame(10, $provider->getRetryLimit());
    }

    // -------------------------------------------------------------------------
    // Partition guard
    // -------------------------------------------------------------------------

    /** @dataProvider validPartitionProvider */
    public function test_valid_partitions_are_accepted(string $partition): void
    {
        // enqueue() should not throw for valid partitions
        $this->provider->enqueue(new FakeEvent(), $partition);
        self::assertTrue(true); // reached without exception
    }

    public static function validPartitionProvider(): array
    {
        return [['content'], ['commerce'], ['system']];
    }
}
