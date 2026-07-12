<?php

declare(strict_types=1);

namespace HSP\Core\Cli;

use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Queue\DeadLetterRepository;
use HSP\Core\Queue\Exception\DeadLetterReplayException;

/**
 * WP-CLI surface for the dead-letter queue: `hsp dlq list | inspect <id> | replay <id>`.
 *
 * Authority: DECISION S (v1.16) — WP-CLI is the ONLY operational surface for DLQ
 * inspection and replay (clause (d): no admin UI in OPS-S1, sidestepping the still-TBD
 * WPCS/coding-standard decision at the WP admin boundary).
 *
 * Design (ADR-012, minimal WP coupling):
 *   The command depends only on DeadLetterRepository (constructor-injected). Each
 *   subcommand returns a plain array/string result; the WP-CLI registration shim
 *   (headless-sync.php) formats it via WP_CLI helpers. This keeps the replay lifecycle
 *   testable without a WP-CLI runtime and keeps WP-specific formatting out of core logic.
 */
final class DlqCommand
{
    public function __construct(
        private readonly DeadLetterRepository $repository,
        private readonly StructuredLogger $logger,
    ) {}

    /**
     * `hsp dlq list [--limit=<n>]` — most recent DLQ rows.
     *
     * @return array<int, array<string,mixed>>
     */
    public function list(int $limit = 50): array
    {
        return $this->repository->list($limit);
    }

    /**
     * `hsp dlq inspect <id>` — full detail for one DLQ row.
     *
     * @return array<string,mixed>|null null if the row is not found.
     */
    public function inspect(string $dlqId): ?array
    {
        return $this->repository->inspect($dlqId);
    }

    /**
     * `hsp dlq replay <id>` — re-enqueue a dead-lettered event (DECISION S lifecycle).
     *
     * On success, emits the `replay` runtime counter as a structured log event
     * (DECISION Q clause 2). Replay runs via WP-CLI, outside the WorkerEngine tick loop,
     * so the emission lives here rather than in the engine — this is the only place a
     * successful replay is observed.
     *
     * @return string The re-enqueued event_id.
     * @throws DeadLetterReplayException on missing / already-replayed row or failure.
     */
    public function replay(string $dlqId): string
    {
        $eventId = $this->repository->replay($dlqId);

        // DECISION Q: runtime counters emitted as structured log events. A successful
        // replay increments the `replay` counter (count 1 per invocation, in-process).
        $this->logger->metric('dlq.replay', [
            'dlq_id'   => $dlqId,
            'event_id' => $eventId,
            'replay'   => 1,
        ]);

        return $eventId;
    }
}
