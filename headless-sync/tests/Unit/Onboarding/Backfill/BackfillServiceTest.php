<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding\Backfill;

use HSP\Core\Onboarding\Backfill\BackfillBlockedException;
use HSP\Core\Onboarding\Backfill\BackfillGate;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Onboarding\Backfill\BackfillService;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Tests\Integration\Reconciliation\WriteSpyConnection;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconciliationSource;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for BackfillService — the ONB-S2 thin delegator (DECISION W (b)/(c)).
 *
 * Proves:
 *   - start() delegates to ReconciliationService::reconcileFull() re-emission (repair is emitted,
 *     never a direct PG write) — a WriteSpyConnection wrapping the reconciliation read connection
 *     records ZERO executes/transactions on the backfill path (mirrors GATE-S3 / DECISION V (d)).
 *   - a blocked gate (stale/absent heartbeat or missing migrations) throws BackfillBlockedException
 *     and emits NOTHING (no re-emission when a hard prerequisite is unmet).
 */
final class BackfillServiceTest extends TestCase
{
    private const ALL_MIGRATIONS = [
        '0002_create_system_events', '0003_create_system_queue_jobs',
        '0005_create_system_aggregate_versions', '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
        '0002_create_content_pages', '0003_create_content_posts', '0004_create_content_taxonomies',
    ];

    public function test_start_reemits_through_reconcile_full_with_zero_direct_writes(): void
    {
        $emitter = new FakeReplayEmitter();
        $spy     = new WriteSpyConnection(new FakeReconConnection());

        // A missed-capture drift so full reconciliation actually re-emits (proves the path, not a
        // no-op). WP has a live post with no projection row → missed_capture → re-emit.
        $source = new FakeReconciliationSource();
        $source->withType('post');
        $source->addLive('post', '10', true, new \DateTimeImmutable('2026-07-01T00:00:00Z'));

        $reconciliation = new ReconciliationService(
            $spy,
            $source,
            new ReplayService(new FakeDbConnection(), [$emitter]),
        );

        $service = new BackfillService($this->readyGate(), $reconciliation);
        $result  = $service->start();

        // Re-emission happened...
        self::assertGreaterThanOrEqual(1, $result->repairedCount());
        self::assertNotEmpty($emitter->calls);
        // ...and NOT ONE direct PG write occurred on the backfill path (write-spy proof).
        self::assertSame(0, $spy->executeCount, 'backfill must not write projections directly');
        self::assertSame(0, $spy->beginCount, 'backfill must not open a write transaction');
    }

    public function test_start_is_blocked_and_emits_nothing_when_the_gate_is_not_ready(): void
    {
        $emitter        = new FakeReplayEmitter();
        $spy            = new WriteSpyConnection(new FakeReconConnection());
        $reconciliation = new ReconciliationService(
            $spy,
            new FakeReconciliationSource(),
            new ReplayService(new FakeDbConnection(), [$emitter]),
        );

        // Stale heartbeat → gate blocks.
        $service = new BackfillService($this->staleGate(), $reconciliation);

        try {
            $service->start();
            self::fail('expected BackfillBlockedException');
        } catch (BackfillBlockedException $e) {
            self::assertFalse($e->summary()['ready']);
        }

        self::assertSame([], $emitter->calls, 'nothing may be re-emitted when the gate blocks');
        self::assertSame(0, $spy->executeCount);
    }

    // --- gate builders ------------------------------------------------------

    private function readyGate(): BackfillGate
    {
        return $this->gate(heartbeatAge: 5.0, migrations: self::ALL_MIGRATIONS);
    }

    private function staleGate(): BackfillGate
    {
        return $this->gate(heartbeatAge: 999.0, migrations: self::ALL_MIGRATIONS);
    }

    /** @param list<string> $migrations */
    private function gate(float $heartbeatAge, array $migrations): BackfillGate
    {
        $reader = new BackfillReader(fn (): ScriptedConnection => (new ScriptedConnection())
            ->on('system.worker_heartbeats', [['age' => $heartbeatAge]]));

        $rows  = array_map(static fn (string $n) => ['migration_name' => $n], $migrations);
        $probe = new OnboardingConnectionProbe(
            fn (): ScriptedConnection => (new ScriptedConnection())->on('system.schema_versions', $rows),
        );

        return new BackfillGate($reader, new MigrationsAppliedCheck($probe), 60);
    }
}
