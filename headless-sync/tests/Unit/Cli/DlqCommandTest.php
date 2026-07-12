<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Cli;

use HSP\Core\Cli\DlqCommand;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Queue\DeadLetterRepository;
use HSP\Core\Queue\Exception\DeadLetterReplayException;
use HSP\Tests\Unit\Queue\FakeQueueConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DlqCommand — the WP-CLI-independent core of `hsp dlq …` (DECISION S).
 *
 * DlqCommand delegates to DeadLetterRepository; these tests verify the delegation, that
 * replay surfaces DeadLetterReplayException to the caller (WP-CLI shell formats it), and
 * that a successful replay emits the `replay` runtime counter as a structured log event
 * (DECISION Q clause 2 — replay runs via WP-CLI, outside the WorkerEngine tick loop).
 */
final class DlqCommandTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    private function makeCommand(FakeQueueConnection $conn): DlqCommand
    {
        $this->logLines = [];
        $logger = new StructuredLogger(function (string $line): void {
            $this->logLines[] = $line;
        });

        return new DlqCommand(new DeadLetterRepository($conn), $logger);
    }

    public function test_list_returns_repository_rows(): void
    {
        $conn = new FakeQueueConnection();
        $conn->queryResultQueue[] = [['id' => 'a', 'event_id' => 'e'], ['id' => 'b', 'event_id' => 'f']];

        $rows = $this->makeCommand($conn)->list(10);

        self::assertCount(2, $rows);
        self::assertStringContainsString('LIMIT', $conn->queryCalls[0]['sql']);
    }

    public function test_inspect_returns_null_when_not_found(): void
    {
        $conn = new FakeQueueConnection();
        $conn->queryResultQueue[] = [];

        self::assertNull($this->makeCommand($conn)->inspect('missing-id'));
    }

    public function test_replay_returns_event_id_on_success(): void
    {
        $conn = new FakeQueueConnection();
        $conn->queryResultQueue[] = [['id' => 'd1', 'event_id' => 'ev1', 'replayed_at' => null]];
        $conn->queryResultQueue[] = [['queue_name' => 'content']];

        self::assertSame('ev1', $this->makeCommand($conn)->replay('d1'));
    }

    public function test_successful_replay_emits_structured_log_line(): void
    {
        $conn = new FakeQueueConnection();
        $conn->queryResultQueue[] = [['id' => 'd1', 'event_id' => 'ev1', 'replayed_at' => null]];
        $conn->queryResultQueue[] = [['queue_name' => 'content']];

        $this->makeCommand($conn)->replay('d1');

        self::assertCount(1, $this->logLines, 'exactly one structured log line on success');
        $decoded = json_decode($this->logLines[0], true);

        self::assertSame('metric', $decoded['hsp']);
        self::assertSame('dlq.replay', $decoded['event'], 'event name identifies the replay counter');
        self::assertSame('d1', $decoded['dlq_id']);
        self::assertSame('ev1', $decoded['event_id'], 'event_id present in the log line');
        self::assertSame(1, $decoded['replay'], 'replay runtime counter value');
        self::assertArrayHasKey('ts', $decoded, 'timestamp present in the log line');
    }

    public function test_failed_replay_emits_no_structured_log_line(): void
    {
        $conn = new FakeQueueConnection();
        // Already-replayed row → guard throws before any emission.
        $conn->queryResultQueue[] = [['id' => 'd1', 'event_id' => 'ev1', 'replayed_at' => '2026-07-11 10:00:00+00']];

        $cmd = $this->makeCommand($conn);

        try {
            $cmd->replay('d1');
            self::fail('expected DeadLetterReplayException');
        } catch (DeadLetterReplayException) {
            // expected
        }

        self::assertSame([], $this->logLines, 'no replay counter emitted on a rejected replay');
    }

    public function test_replay_propagates_already_replayed_exception(): void
    {
        $conn = new FakeQueueConnection();
        $conn->queryResultQueue[] = [['id' => 'd1', 'event_id' => 'ev1', 'replayed_at' => '2026-07-11 10:00:00+00']];

        $this->expectException(DeadLetterReplayException::class);
        $this->makeCommand($conn)->replay('d1');
    }
}
