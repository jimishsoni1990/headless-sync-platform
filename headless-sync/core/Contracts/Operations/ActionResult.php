<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable outcome of one Operations Console operational action (OPSC-S4; DECISION V (d)).
 *
 * Returned by the OperationsActionService after it delegates a Replay or Reconcile action to
 * the ratified worker strategy (DECISION T/S replay, DECISION U reconciliation). The action
 * path is a THIN DELEGATOR: it opens no second repair path and writes no `content.*` / `system.*`
 * projection directly — repair is re-emission only, exactly as for organic edits. This DTO
 * therefore describes what the delegated service reported (a summary + a count), never a
 * direct write the console performed.
 *
 * Fields:
 *   $action  — the action key that ran ('replay' | 'reconcile').
 *   $ok      — whether the delegated operation completed without error.
 *   $summary — a short human-readable summary for the Response Viewer / audit line.
 *   $count   — the operation's headline count (replay: synthetic events emitted;
 *              reconcile: aggregates repaired via re-emission).
 *   $detail  — plain JSON-friendly detail from the delegated result (correlation id, mode,
 *              scanned/suppressed counts, per-aggregate rows) for traceability. No object
 *              or infrastructure handle ever appears here.
 *
 * @psalm-immutable
 */
final class ActionResult
{
    /**
     * @param array<string,mixed> $detail
     */
    public function __construct(
        public readonly string $action,
        public readonly bool   $ok,
        public readonly string $summary,
        public readonly int    $count = 0,
        public readonly array  $detail = [],
    ) {}
}
