<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Services;

use HSP\Core\Contracts\Operations\ActionResult;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Operations\Services\OperationsActionService;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Tests\Unit\Operations\Fakes\RecordingReplayEmitter;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReconciliationSource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OperationsActionService — the OPSC-S4 thin-delegator action seam
 * (DECISION V (d)/(e)/(f); DECISION T/S/U; ADR-053).
 *
 * The service is exercised over REAL ReplayService / ReconciliationService built on a write-spy
 * DatabaseConnectionInterface (ScriptedReaderConnection throws on any execute/begin/commit) and a
 * RecordingReplayEmitter. This proves the two core DoD properties directly:
 *   1. Replay + Reconcile actions invoke the ratified services ONLY (re-emission through the
 *      emitter is the sole repair path) — the recording emitter captures every emit.
 *   2. ZERO direct content.* / system.* writes on the action path — the connection's write
 *      methods are never called (writeAttempts stays 0), mirroring the GATE-S3 write-spy.
 * Plus: the audit line is emitted through the existing StructuredLogger path (no new persistence),
 * unknown / Flush-Queue / Restart-Workers keys are rejected, and params are validated.
 */
final class OperationsActionServiceTest extends TestCase
{
    private ScriptedReaderConnection $conn;
    private RecordingReplayEmitter $emitter;
    /** @var list<string> */
    private array $auditLines = [];
    private OperationsActionService $service;

    protected function setUp(): void
    {
        $this->conn    = new ScriptedReaderConnection();
        $this->emitter = new RecordingReplayEmitter();

        $replayService = new ReplayService($this->conn, [$this->emitter]);
        $replayStrategy = new ReplayWorkerStrategy($replayService);

        // Reconciliation over the same write-spy connection + a scripted WP source reporting one
        // live/public post with no projection row → a detected missed capture, repaired by
        // re-emission (through the SAME ReplayService → recording emitter).
        $source = new ScriptedReconciliationSource('post', ['101']);
        $reconService  = new ReconciliationService($this->conn, $source, $replayService);
        $reconStrategy = new ReconciliationWorkerStrategy($reconService);

        $this->auditLines = [];
        $audit = new StructuredLogger(function (string $line): void {
            $this->auditLines[] = $line;
        });

        $this->service = new OperationsActionService($replayStrategy, $reconStrategy, $audit);
    }

    // --- Replay delegation ----------------------------------------------------

    public function test_replay_entity_delegates_to_the_replay_service_and_re_emits(): void
    {
        $result = $this->service->execute(OperationsActionService::ACTION_REPLAY, [
            'mode'           => 'entity',
            'aggregate_type' => 'post',
            'aggregate_id'   => '42',
        ]);

        self::assertInstanceOf(ActionResult::class, $result);
        self::assertSame('replay', $result->action);
        self::assertTrue($result->ok);
        self::assertSame(1, $result->count);

        // The ONLY repair path fired: exactly one re-emission for the target aggregate.
        self::assertCount(1, $this->emitter->emitted);
        self::assertSame('post', $this->emitter->emitted[0]['aggregate_type']);
        self::assertSame('42', $this->emitter->emitted[0]['aggregate_id']);
    }

    /**
     * Replay reproduces current WordPress state, so an absent aggregate emits a tombstone — right
     * for repairing a missed delete, and identical to what a mistyped aggregate_id produces. The
     * plain "re-emitted 1 event(s)" summary read as success in both cases, telling an operator who
     * fat-fingered an id in the console's Replay form that the entity had been replayed. The
     * emission stays (blocking it would break the legitimate repair); the report must distinguish.
     */
    public function test_replay_summary_names_tombstones_so_a_mistyped_id_is_visible(): void
    {
        $this->emitter->absentAggregateIds = ['99999'];

        $result = $this->service->execute(OperationsActionService::ACTION_REPLAY, [
            'mode'           => 'entity',
            'aggregate_type' => 'post',
            'aggregate_id'   => '99999',
        ]);

        self::assertTrue($result->ok, 'a tombstone is a valid outcome, not a failure');
        self::assertSame(1, $result->count);
        self::assertStringContainsString('1 of them is a deletion', $result->summary);
        self::assertStringContainsString('absent from WordPress', $result->summary);
    }

    /** A normal reprojection says nothing about deletions — the clause is not boilerplate. */
    public function test_replay_summary_omits_the_tombstone_clause_for_a_live_aggregate(): void
    {
        $result = $this->service->execute(OperationsActionService::ACTION_REPLAY, [
            'mode'           => 'entity',
            'aggregate_type' => 'post',
            'aggregate_id'   => '42',
        ]);

        self::assertStringNotContainsString('deletion', $result->summary);
    }

    public function test_replay_range_delegates_to_the_replay_service(): void
    {
        // system.events discovery returns two distinct aggregates in the window.
        $this->conn->on('DISTINCT aggregate_type, aggregate_id', [
            ['aggregate_type' => 'post', 'aggregate_id' => '1'],
            ['aggregate_type' => 'page', 'aggregate_id' => '2'],
        ]);

        $result = $this->service->execute(OperationsActionService::ACTION_REPLAY, [
            'mode' => 'range',
            'from' => '2026-07-01T00:00:00Z',
            'to'   => '2026-07-16T00:00:00Z',
        ]);

        self::assertSame(2, $result->count);
        self::assertCount(2, $this->emitter->emitted);
    }

    // --- Reconcile delegation -------------------------------------------------

    public function test_reconcile_drift_delegates_and_repairs_via_re_emission(): void
    {
        // No projection row for post 101 → missed capture → repair by re-emission.
        $result = $this->service->execute(OperationsActionService::ACTION_RECONCILE, [
            'mode' => 'drift',
        ]);

        self::assertSame('reconcile', $result->action);
        self::assertTrue($result->ok);
        self::assertSame(1, $result->count);
        self::assertSame('drift', $result->detail['mode']);

        // Repair rode the re-emission path (recording emitter), not a direct write.
        self::assertCount(1, $this->emitter->emitted);
        self::assertSame('101', $this->emitter->emitted[0]['aggregate_id']);
    }

    public function test_reconcile_dry_run_detects_without_re_emitting(): void
    {
        $result = $this->service->execute(OperationsActionService::ACTION_RECONCILE, [
            'mode'    => 'drift',
            'dry_run' => true,
        ]);

        self::assertSame(1, $result->count);          // detected
        self::assertCount(0, $this->emitter->emitted); // but NOT re-emitted
        self::assertTrue($result->detail['dry_run']);
    }

    // --- The write-spy proof (the GATE-S3 mirror) -----------------------------

    public function test_no_direct_projection_writes_occur_on_any_action_path(): void
    {
        $this->conn->on('DISTINCT aggregate_type, aggregate_id', [
            ['aggregate_type' => 'post', 'aggregate_id' => '7'],
        ]);

        $this->service->execute(OperationsActionService::ACTION_REPLAY, [
            'mode' => 'entity', 'aggregate_type' => 'post', 'aggregate_id' => '7',
        ]);
        $this->service->execute(OperationsActionService::ACTION_REPLAY, [
            'mode' => 'range', 'from' => '2026-07-01T00:00:00Z', 'to' => '2026-07-16T00:00:00Z',
        ]);
        $this->service->execute(OperationsActionService::ACTION_RECONCILE, ['mode' => 'drift']);
        $this->service->execute(OperationsActionService::ACTION_RECONCILE, ['mode' => 'incremental']);
        $this->service->execute(OperationsActionService::ACTION_RECONCILE, ['mode' => 'full']);

        // The write-spy connection throws on execute/beginTransaction/commit; if any action path
        // attempted a direct content.* / system.* write it would have thrown. Zero attempts.
        self::assertSame(0, $this->conn->writeAttempts);
    }

    // --- Audit (existing observability path — no new persistence) -------------

    public function test_every_executed_action_emits_one_structured_audit_line(): void
    {
        $this->service->execute(OperationsActionService::ACTION_REPLAY, [
            'mode' => 'entity', 'aggregate_type' => 'post', 'aggregate_id' => '9',
        ]);

        self::assertCount(1, $this->auditLines);
        $decoded = json_decode($this->auditLines[0], true);
        self::assertIsArray($decoded);
        self::assertSame('operations.action', $decoded['event']);
        self::assertSame('replay', $decoded['action']);
        self::assertTrue($decoded['ok']);
    }

    // --- Rejections: unknown / Flush Queue / Restart Workers ------------------

    public function test_unknown_action_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute('teleport', []);
    }

    public function test_flush_queue_action_is_rejected_decision_v_e(): void
    {
        // No Flush Queue action exists (DECISION V (e)); the service refuses the key.
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute('flush_queue', []);
    }

    public function test_restart_workers_action_is_rejected_decision_v_f(): void
    {
        // No Restart Workers action exists (DECISION V (f)); the service refuses the key.
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute('restart_workers', []);
    }

    public function test_only_replay_and_reconcile_are_supported(): void
    {
        self::assertSame(['replay', 'reconcile'], $this->service->supportedActions());
    }

    // --- Param validation -----------------------------------------------------

    public function test_replay_entity_requires_aggregate_identity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(OperationsActionService::ACTION_REPLAY, ['mode' => 'entity']);
    }

    public function test_reconcile_requires_a_valid_mode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->execute(OperationsActionService::ACTION_RECONCILE, ['mode' => 'wut']);
    }
}
