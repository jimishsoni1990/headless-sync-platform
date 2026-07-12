<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Queue;

use HSP\Core\Queue\DeadLetterRepository;
use HSP\Core\Queue\Exception\DeadLetterReplayException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeadLetterRepository replay lifecycle (DECISION S clause (b)).
 *
 * Proves the exact SQL step order against a fake connection:
 *   verify exists (FOR UPDATE) → verify not replayed → DELETE queue row by event_id
 *   → INSERT fresh job attempts=0 → stamp replayed_at.
 * Plus the missing-row and already-replayed guards (rollback + throw, no writes).
 */
final class DeadLetterRepositoryTest extends TestCase
{
    private const DLQ_ID   = '01900000-0000-7000-8000-000000000d10';
    private const EVENT_ID = '01900000-0000-7000-8000-000000000e10';

    public function test_replay_executes_the_full_lifecycle_in_order_and_commits(): void
    {
        $conn = new FakeQueueConnection();
        // query #1: SELECT … FOR UPDATE → DLQ row, not yet replayed.
        $conn->queryResultQueue[] = [['id' => self::DLQ_ID, 'event_id' => self::EVENT_ID, 'replayed_at' => null]];
        // query #2: resolveQueueName → prior queue row on 'content'.
        $conn->queryResultQueue[] = [['queue_name' => 'content']];

        $repo    = new DeadLetterRepository($conn);
        $eventId = $repo->replay(self::DLQ_ID);

        self::assertSame(self::EVENT_ID, $eventId);
        self::assertSame(1, $conn->beginCount);
        self::assertSame(1, $conn->commitCount);
        self::assertSame(0, $conn->rollbackCount);

        // Three execute() calls in order: DELETE → INSERT → UPDATE.
        self::assertCount(3, $conn->executeCalls);
        self::assertStringContainsString('DELETE FROM system.queue_jobs', $conn->executeCalls[0]['sql']);
        self::assertStringContainsString('INSERT INTO system.queue_jobs', $conn->executeCalls[1]['sql']);
        self::assertStringContainsString("'available', 0, NOW()", $conn->executeCalls[1]['sql']);
        self::assertStringContainsString('UPDATE system.dead_letter_jobs', $conn->executeCalls[2]['sql']);
        self::assertStringContainsString('replayed_at = NOW()', $conn->executeCalls[2]['sql']);

        // DELETE and INSERT both target the same event_id.
        self::assertSame(self::EVENT_ID, $conn->executeCalls[0]['params'][0]);
        self::assertSame(self::EVENT_ID, $conn->executeCalls[1]['params'][1]);
        self::assertSame('content', $conn->executeCalls[1]['params'][2]);
    }

    public function test_replay_of_missing_row_throws_and_writes_nothing(): void
    {
        $conn = new FakeQueueConnection();
        $conn->queryResultQueue[] = []; // SELECT FOR UPDATE → no row

        $repo = new DeadLetterRepository($conn);

        $this->expectException(DeadLetterReplayException::class);
        $this->expectExceptionMessage('does not exist');

        try {
            $repo->replay(self::DLQ_ID);
        } finally {
            self::assertSame(0, $conn->commitCount);
            self::assertSame(1, $conn->rollbackCount);
            self::assertCount(0, $conn->executeCalls, 'no queue/DLQ writes on missing row');
        }
    }

    public function test_double_replay_is_rejected_by_replayed_at_guard(): void
    {
        $conn = new FakeQueueConnection();
        // DLQ row already carries a replayed_at timestamp.
        $conn->queryResultQueue[] = [[
            'id'          => self::DLQ_ID,
            'event_id'    => self::EVENT_ID,
            'replayed_at' => '2026-07-11 10:00:00+00',
        ]];

        $repo = new DeadLetterRepository($conn);

        $this->expectException(DeadLetterReplayException::class);
        $this->expectExceptionMessage('already replayed');

        try {
            $repo->replay(self::DLQ_ID);
        } finally {
            self::assertSame(0, $conn->commitCount);
            self::assertSame(1, $conn->rollbackCount);
            self::assertCount(0, $conn->executeCalls, 'no writes on an already-replayed row');
        }
    }

    public function test_replay_defaults_queue_name_to_content_when_no_prior_row(): void
    {
        $conn = new FakeQueueConnection();
        $conn->queryResultQueue[] = [['id' => self::DLQ_ID, 'event_id' => self::EVENT_ID, 'replayed_at' => null]];
        $conn->queryResultQueue[] = []; // resolveQueueName → no prior queue row

        $repo = new DeadLetterRepository($conn);
        $repo->replay(self::DLQ_ID);

        self::assertSame('content', $conn->executeCalls[1]['params'][2], 'defaults to Phase 1A content partition');
    }
}
