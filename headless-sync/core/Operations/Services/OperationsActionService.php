<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Services;

use HSP\Core\Contracts\Operations\ActionResult;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Reconciliation\ReconciliationResult;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayResult;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;

/**
 * The Operations Console action seam — a THIN DELEGATOR for the only two permitted actions,
 * Replay and Reconcile (OPSC-S4; DECISION V (d)/(e)/(f); ADR-053).
 *
 * This is the action-side mirror of {@see OperationsService} (which fronts read-only data): the
 * wp-admin action boundary (ConsoleActionController) talks ONLY to this service, never to a
 * repair mechanism directly. This service in turn delegates EXCLUSIVELY to the ratified worker
 * strategies:
 *   - Replay  → ReplayWorkerStrategy (DECISION T entity/range re-emission; DECISION S DLQ replay
 *               is a separate CLI-only path, unchanged).
 *   - Reconcile → ReconciliationWorkerStrategy (DECISION U drift/incremental/full re-emission).
 *
 * It NEVER opens a second repair path and NEVER writes content.* / system.* projections directly
 * (DECISION V (d) / DECISION T point 5 / DECISION U point 2). It holds NO DatabaseConnectionInterface,
 * no adapter, no reader — only the two strategies and a StructuredLogger. The OPSC-S4 write-spy
 * proof (zero direct `content.*` / `system.*` writes on the action path, mirroring GATE-S3)
 * therefore holds BY CONSTRUCTION: no write primitive is reachable from this class. The only state
 * change is the strategies' re-emission through wp_hsp_outbox — exactly the organic-edit path.
 *
 * There is deliberately NO Flush Queue (DECISION V (e)) and NO Restart Workers (DECISION V (f))
 * method here — the action surface is closed to Replay + Reconcile. Worker status / heartbeat /
 * runbook links remain the read-only OPSC-S2/S3 surface, not an action.
 *
 * Audit (OPSC-S4 DoD — "capability + confirmation + audit enforced"): every executed action emits
 * one structured audit log line through the EXISTING observability path (StructuredLogger —
 * DECISION Q clause 2). No new persistence, table, or schema is introduced (DECISION V (c) /
 * DECISION Q). Capability + confirmation are enforced at the wp-admin boundary (DECISION V (b)),
 * not here — this service is WordPress-free and unit-testable without a WP runtime.
 *
 * Constructor injection only (ADR-012 / Rule 7); this class never calls Container::get().
 */
final class OperationsActionService
{
    /** The two — and only two — permitted action keys (DECISION V (d)/(e)/(f)). */
    public const ACTION_REPLAY    = 'replay';
    public const ACTION_RECONCILE = 'reconcile';

    public function __construct(
        private readonly ReplayWorkerStrategy         $replay,
        private readonly ReconciliationWorkerStrategy $reconciliation,
        private readonly StructuredLogger             $audit,
    ) {}

    /**
     * The permitted action keys, for the boundary/registry to validate against.
     *
     * @return string[]
     */
    public function supportedActions(): array
    {
        return [self::ACTION_REPLAY, self::ACTION_RECONCILE];
    }

    /**
     * Execute one operational action by key, delegating to the ratified service.
     *
     * @param string              $actionKey One of ACTION_REPLAY | ACTION_RECONCILE.
     * @param array<string,mixed> $params    Action parameters (sanitized at the boundary).
     *                                        replay:   ['mode'=>'entity',   'aggregate_type', 'aggregate_id']
     *                                               or ['mode'=>'range',    'from', 'to'  (UTC datetimes)]
     *                                        reconcile:['mode'=>'drift'|'incremental'|'full', 'dry_run'?bool]
     *
     * @throws \InvalidArgumentException on an unknown action key or invalid params. No Flush
     *         Queue / Restart Workers key is accepted (DECISION V (e)/(f)).
     */
    public function execute(string $actionKey, array $params = []): ActionResult
    {
        $result = match ($actionKey) {
            self::ACTION_REPLAY    => $this->runReplay($params),
            self::ACTION_RECONCILE => $this->runReconcile($params),
            default => throw new \InvalidArgumentException(
                "Unknown or unsupported action '{$actionKey}'. Only 'replay' and 'reconcile' "
                . 'are permitted (DECISION V (d)/(e)/(f)).'
            ),
        };

        // Audit through the existing observability path (DECISION Q clause 2) — no new persistence.
        $this->audit->metric('operations.action', [
            'action'  => $result->action,
            'ok'      => $result->ok,
            'count'   => $result->count,
            'summary' => $result->summary,
        ] + $result->detail);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Replay — delegates to ReplayWorkerStrategy (DECISION T)
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     */
    private function runReplay(array $params): ActionResult
    {
        $mode = isset($params['mode']) ? (string) $params['mode'] : '';

        $replayResult = match ($mode) {
            'entity' => $this->replay->replayEntity(
                $this->requireString($params, 'aggregate_type'),
                $this->requireString($params, 'aggregate_id'),
            ),
            'range' => $this->replay->replayRange(
                $this->parseUtc($this->requireString($params, 'from'), 'from'),
                $this->parseUtc($this->requireString($params, 'to'), 'to'),
            ),
            default => throw new \InvalidArgumentException(
                "Replay 'mode' must be 'entity' or 'range', got '{$mode}'."
            ),
        };

        return $this->replayResult($mode, $replayResult);
    }

    private function replayResult(string $mode, ReplayResult $r): ActionResult
    {
        $count = $r->count();

        return new ActionResult(
            action: self::ACTION_REPLAY,
            ok: true,
            summary: sprintf('Replay (%s): re-emitted %d event(s).', $mode, $count),
            count: $count,
            detail: [
                'mode'           => $mode,
                'correlation_id' => $r->correlationId,
                'causation_id'   => $r->causationId,
                'emitted'        => $r->emitted,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Reconcile — delegates to ReconciliationWorkerStrategy (DECISION U)
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     */
    private function runReconcile(array $params): ActionResult
    {
        $mode   = isset($params['mode']) ? (string) $params['mode'] : '';
        $dryRun = (bool) ($params['dry_run'] ?? false);

        $reconResult = match ($mode) {
            ReconciliationService::MODE_DRIFT       => $this->reconciliation->reconcileDrift($dryRun),
            ReconciliationService::MODE_INCREMENTAL => $this->reconciliation->reconcileIncremental($dryRun),
            ReconciliationService::MODE_FULL        => $this->reconciliation->reconcileFull($dryRun),
            default => throw new \InvalidArgumentException(
                "Reconcile 'mode' must be 'drift', 'incremental', or 'full', got '{$mode}'."
            ),
        };

        return $this->reconcileResult($reconResult);
    }

    private function reconcileResult(ReconciliationResult $r): ActionResult
    {
        $count = $r->repairedCount();

        $summary = $r->dryRun
            ? sprintf('Reconcile (%s, dry-run): %d aggregate(s) would be repaired.', $r->mode, $count)
            : sprintf('Reconcile (%s): repaired %d aggregate(s) via re-emission.', $r->mode, $count);

        return new ActionResult(
            action: self::ACTION_RECONCILE,
            ok: true,
            summary: $summary,
            count: $count,
            detail: [
                'mode'       => $r->mode,
                'dry_run'    => $r->dryRun,
                'scanned'    => $r->scanned,
                'suppressed' => $r->suppressed,
                'repaired'   => $r->repaired,
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Param helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     */
    private function requireString(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \InvalidArgumentException("Action parameter '{$key}' is required and must be a non-empty string.");
        }

        return $value;
    }

    private function parseUtc(string $value, string $label): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $e) {
            throw new \InvalidArgumentException(
                "Action parameter '{$label}' value '{$value}' is not a valid datetime: " . $e->getMessage()
            );
        }
    }
}
