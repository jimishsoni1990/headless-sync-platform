<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Reconciliation;

use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * ReconciliationService — DECISION U detector + repair orchestration.
 *
 * Verifies all drift classes (missed create, missed update, checksum drift, orphan,
 * tombstoned-ok, equal-timestamp non-drift), suppression (outbox-pending, events-pending),
 * mode direction/windowing (orphan sweep full-only; checksum incremental/full), the
 * taxonomy existence-only path, and that repair is re-emission ONLY (never a direct PG
 * write). Uses fakes — no WordPress, no PG.
 */
final class ReconciliationServiceTest extends TestCase
{
    private FakeReconConnection $conn;
    private FakeReconciliationSource $source;
    private FakeReplayEmitter $emitter;
    private ReplayService $replay;

    protected function setUp(): void
    {
        $this->conn    = new FakeReconConnection();
        $this->source  = new FakeReconciliationSource();
        $this->emitter = new FakeReplayEmitter();
        // Real ReplayService with a spyable emitter. Its own connection is unused by
        // entity replay (only date-range reads system.events), so a stub fake is fine.
        $this->replay  = new ReplayService(new FakeDbConnection(), [$this->emitter]);
    }

    private function service(int $pageSize = 500): ReconciliationService
    {
        return new ReconciliationService($this->conn, $this->source, $this->replay, $pageSize);
    }

    private function ts(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    // ---- consistency (no drift) ----

    public function testConsistentProjectionIsNotRepaired(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-01T00:00:00Z'));
        // Projection updated_at equals WP modified → not stale.
        $this->conn->projectionRows['post:10'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(0, $result->repairedCount());
        self::assertSame(1, $result->scanned);
        self::assertCount(0, $this->emitter->calls);
    }

    public function testEqualTimestampIsNotDrift(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-01T12:00:00Z'));
        $this->conn->projectionRows['post:10'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 12:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(0, $result->repairedCount());
    }

    // ---- missed create / update ----

    public function testMissedCreateDetectedAndReEmitted(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-01T00:00:00Z'));
        // No projection row at all.
        $this->conn->projectionRows['post:10'] = null;

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(1, $result->repairedCount());
        self::assertSame('missed_capture', $result->repaired[0]['reason']);
        self::assertSame('post', $result->repaired[0]['aggregate_type']);
        self::assertSame('10', $result->repaired[0]['aggregate_id']);
        // Repair happened via re-emission.
        self::assertCount(1, $this->emitter->calls);
        self::assertSame('post', $this->emitter->calls[0]['type']);
    }

    public function testMissedUpdateDetectedWhenWpNewerThanProjection(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-02T00:00:00Z'));
        $this->conn->projectionRows['post:10'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(1, $result->repairedCount());
        self::assertSame('missed_capture', $result->repaired[0]['reason']);
        self::assertCount(1, $this->emitter->calls);
    }

    public function testSoftDeletedProjectionForLiveWpIsMissedCapture(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-01T00:00:00Z'));
        $this->conn->projectionRows['post:10'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-05 00:00:00+00', 'deleted_at' => '2026-07-04 00:00:00+00',
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(1, $result->repairedCount());
        self::assertSame('missed_capture', $result->repaired[0]['reason']);
    }

    // ---- checksum drift (incremental/full only) ----

    public function testChecksumDriftInvisibleToHourlyButCaughtByIncremental(): void
    {
        $this->source->withType('category');
        // Category: no modified timestamp (existence-only hourly). Current checksum differs.
        $this->source->addLive('category', '7', true, null, checksum: str_repeat('b', 64));
        $this->conn->projectionRows['category:7'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        // Hourly drift: existence-only, no checksum → not detected.
        $drift = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(0, $drift->repairedCount(), 'category rename is invisible to hourly drift');

        // Nightly incremental: checksum recompute catches it.
        $this->emitter->calls = [];
        $incr = $this->service()->reconcile(ReconciliationService::MODE_INCREMENTAL);
        self::assertSame(1, $incr->repairedCount());
        self::assertSame('checksum_drift', $incr->repaired[0]['reason']);
        self::assertCount(1, $this->emitter->calls);
    }

    public function testMatchingChecksumIsNotDrift(): void
    {
        $this->source->withType('category');
        $this->source->addLive('category', '7', true, null, checksum: str_repeat('a', 64));
        $this->conn->projectionRows['category:7'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_INCREMENTAL);
        self::assertSame(0, $result->repairedCount());
    }

    // ---- orphan sweep (full only) ----

    public function testOrphanDetectedInFullModeOnly(): void
    {
        $this->source->withType('post');
        // WP has NO post 99, but a live projection row exists → orphan.
        $this->conn->orphanCandidates['post'] = ['99'];
        $this->conn->projectionRows['post:99'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        // Drift (hourly) is WP→PG only — never scans orphans.
        $drift = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(0, $drift->repairedCount());
        self::assertCount(0, $this->emitter->calls);

        // Full mode: orphan sweep detects and re-emits (→ .deleted tombstone via emitter).
        $full = $this->service()->reconcile(ReconciliationService::MODE_FULL);
        self::assertSame(1, $full->repairedCount());
        self::assertSame('orphan', $full->repaired[0]['reason']);
        self::assertSame('99', $full->repaired[0]['aggregate_id']);
        self::assertCount(1, $this->emitter->calls);
    }

    public function testLiveWpProjectionIsNotAnOrphan(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '50', true, $this->ts('2026-07-01T00:00:00Z'));
        $this->conn->orphanCandidates['post'] = ['50'];
        $this->conn->projectionRows['post:50'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_FULL);
        // Forward pass sees it consistent; orphan pass sees it live → no repair.
        self::assertSame(0, $result->repairedCount());
    }

    // ---- suppression (DECISION U D4) ----

    public function testPendingOutboxSuppressesRepair(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-02T00:00:00Z'));
        $this->source->pendingOutbox['post:10'] = true; // capture not yet relayed
        $this->conn->projectionRows['post:10'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(0, $result->repairedCount());
        self::assertSame(1, $result->suppressed);
        self::assertCount(0, $this->emitter->calls, 'in-flight aggregate must not be re-emitted');
    }

    public function testPendingEventsSuppressesRepair(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-02T00:00:00Z'));
        $this->conn->eventsPending['post:10'] = true; // relayed, not yet processed
        $this->conn->projectionRows['post:10'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);

        self::assertSame(0, $result->repairedCount());
        self::assertSame(1, $result->suppressed);
        self::assertCount(0, $this->emitter->calls);
    }

    public function testGenuineDriftWithNoInFlightIsRepaired(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-02T00:00:00Z'));
        // neither outbox-pending nor events-pending
        $this->conn->projectionRows['post:10'] = [
            'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
        ];

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(1, $result->repairedCount());
        self::assertSame(0, $result->suppressed);
    }

    // ---- WordPress-wins by construction: NO direct PG writes ----

    public function testServiceNeverExecutesDirectPgWrites(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-02T00:00:00Z'));
        $this->conn->projectionRows['post:10'] = null; // missed create → repair

        $this->service()->reconcile(ReconciliationService::MODE_FULL);

        self::assertNotContains('execute', $this->conn->loggedMethods());
        self::assertNotContains('beginTransaction', $this->conn->loggedMethods());
    }

    // ---- dry run ----

    public function testDryRunDetectsButDoesNotReEmit(): void
    {
        $this->source->withType('post');
        $this->source->addLive('post', '10', true, $this->ts('2026-07-02T00:00:00Z'));
        $this->conn->projectionRows['post:10'] = null;

        $result = $this->service()->reconcile(ReconciliationService::MODE_DRIFT, dryRun: true);

        self::assertTrue($result->dryRun);
        self::assertSame(1, $result->repairedCount(), 'dry run reports detected count');
        self::assertSame('missed_capture', $result->repaired[0]['reason']);
        self::assertCount(0, $this->emitter->calls, 'dry run must not re-emit');
    }

    // ---- mode + paging ----

    public function testUnknownModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service()->reconcile('weekly-ish');
    }

    public function testPagingWalksTheWholeCorpus(): void
    {
        $this->source->withType('post');
        // 3 live consistent posts; page size 1 forces multiple pages.
        foreach (['1', '2', '3'] as $id) {
            $this->source->addLive('post', $id, true, $this->ts('2026-07-01T00:00:00Z'));
            $this->conn->projectionRows["post:{$id}"] = [
                'checksum' => str_repeat('a', 64), 'updated_at' => '2026-07-01 00:00:00+00', 'deleted_at' => null,
            ];
        }

        $result = $this->service(pageSize: 1)->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(3, $result->scanned, 'all three aggregates scanned across pages');
        self::assertSame(0, $result->repairedCount());
    }
}
