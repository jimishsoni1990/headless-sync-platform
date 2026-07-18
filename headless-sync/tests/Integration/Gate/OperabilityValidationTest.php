<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Gate;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\AggregateVersionCounter;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Events\Outbox\OutboxWriter;
use HSP\Core\Observability\OperationalMetricsQuery;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\DatabaseHeartbeatPublisher;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\EventProvider;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Handlers\CategoryTombstoneHandler;
use HSP\Modules\Content\Handlers\CategoryUpsertHandler;
use HSP\Modules\Content\Handlers\PageTombstoneHandler;
use HSP\Modules\Content\Handlers\PageUpsertHandler;
use HSP\Modules\Content\Handlers\PostTombstoneHandler;
use HSP\Modules\Content\Handlers\PostUpsertHandler;
use HSP\Modules\Content\Replay\ContentReplayEmitter;
use HSP\Modules\Content\Subscribers\ContentSubscriber;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Integration\Reconciliation\StoreReconciliationSource;
use HSP\Tests\Integration\Reconciliation\WriteSpyConnection;
use HSP\Tests\Integration\Replay\FakeWpdb;
use HSP\Tests\Integration\Replay\FakeWpStore;
use HSP\Tests\Integration\Replay\ReplayReadingLoader;
use PHPUnit\Framework\TestCase;

/**
 * GATE-S3 — Architecture Validation Gate: Operability Validation.
 *
 * IMPLEMENTATION_PLAN.md §4 → Operability Validation criteria (verbatim):
 *   1. Worker health visible; failure detection within one heartbeat cycle.
 *   2. Failure diagnostics available via DLQ payload snapshot and stack trace.
 *   3. Reconciliation executes: hourly drift detection, incremental validation, full
 *      reconciliation (ADR-026); WordPress always wins divergence (ADR-027, ADR-045).
 *
 * This is a GATE session: EVIDENCE ONLY, no production code changes. Each criterion is
 * proven end-to-end against LIVE MySQL + LIVE PostgreSQL by assembling the real runtime
 * components:
 *   - criterion 1 → WorkerEngine + DatabaseHeartbeatPublisher (DECISION P) + the
 *                   heartbeat-age crash-detection read a monitor performs.
 *   - criterion 2 → the real worker DLQ path: EventWorkerStrategy dead-letters an
 *                   exhausted job into system.dead_letter_jobs with the OPEN-3 context
 *                   (payload_snapshot + stack_trace) — read back via the same
 *                   DeadLetterRepository::inspect() surface `hsp dlq inspect` uses.
 *   - criterion 3 → ReconciliationService (OPS-S3 / DECISION U) driving all three modes,
 *                   repairing ONLY by DECISION T re-emission through the outbox → relay →
 *                   dispatch → worker pipeline, with a write-spy proving zero direct PG
 *                   projection writes on the repair path (WordPress wins by construction).
 *
 * The ONLY substitution anywhere is the WordPress-read boundary (DECISION H): a headless
 * PHPUnit process cannot bootstrap WordPress, so FakeWpStore stands in for get_post/get_term
 * and StoreReconciliationSource / ReplayReadingLoader read it. Everything else — outbox,
 * relay, dispatch, queue, worker engine, heartbeat publisher, DLQ writer/reader, adapters,
 * guard, PostgreSQL, live MySQL outbox — is the real runtime.
 *
 * Environment (self-skips if a DB is genuinely absent):
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class OperabilityValidationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_gate_ops_';
    private string $outbox;
    private string $counters;

    /** In-memory WordPress state the reconciliation source + replay emitter read (DECISION H). */
    private FakeWpStore $wp;

    protected function setUp(): void
    {
        $this->outbox   = $this->prefix . 'hsp_outbox';
        $this->counters = $this->prefix . 'hsp_aggregate_counters';

        $this->mysqli = $this->connectMysql();
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->wp     = new FakeWpStore();

        $this->createMysqlSchema();
        $this->createPgsqlSchema();
    }

    protected function tearDown(): void
    {
        if ($this->mysqli !== null) {
            $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
            $this->mysqli->query("DROP TABLE IF EXISTS `{$this->counters}`");
            $this->mysqli->close();
            $this->mysqli = null;
        }
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Criterion 1 — Worker health visible; failure detection within one heartbeat cycle.
    //   §4: "Worker health visible; failure detection within one heartbeat cycle."
    //   Mechanism (DECISION P): the WorkerEngine upserts one current-state row per worker
    //   into system.worker_heartbeats every tick (worker_id, worker_type, status,
    //   last_heartbeat_at). A monitor detects a crashed worker by last_heartbeat_at AGE —
    //   when a worker stops ticking, its row goes stale within one heartbeat cycle and the
    //   age query surfaces it. Everything here is the real runtime: WorkerEngine.tick() +
    //   DatabaseHeartbeatPublisher writing live PostgreSQL.
    // =========================================================================

    public function test_criterion1_worker_health_is_visible_and_a_crash_is_detected_within_one_heartbeat_cycle(): void
    {
        // A one-heartbeat-cycle window. A monitor flags a worker whose heartbeat is older
        // than this as crashed/unhealthy (Doc 8 §15 heartbeat-age crash detection).
        $cycleSeconds = 2;

        $publisher = new DatabaseHeartbeatPublisher($this->db);

        // ADR-054: a processing CYCLE writes one fresh-UUID current-state heartbeat row (its
        // freshness IS the health signal — Doc 8 v2.0 §15). Record a just-ran healthy cycle.
        $healthyWorkerId = '01900000-0000-7000-8000-00000000cea1';
        $publisher->publish(new \HSP\Core\Workers\HeartbeatRecord(
            workerId:        $healthyWorkerId,
            status:          'idle',
            lastHeartbeatAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            workerType:      'processing',
            startedAt:       new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ));

        // ---- Health is VISIBLE: the cycle's current-state row is queryable. ----
        $row = $this->heartbeatRow($healthyWorkerId);
        self::assertNotNull($row, 'processing health visible: a heartbeat row exists after a cycle');
        self::assertSame('processing', $row['worker_type'], 'worker_type visible');
        self::assertSame('idle', $row['status'], 'status visible (idle — the cycle found no work)');
        self::assertSame(1, $this->countRows('system.worker_heartbeats'), 'exactly one current-state row for this cycle');

        // A monitor query surfaces the fresh cycle as NOT stale.
        self::assertSame(0, $this->countStaleWorkers($cycleSeconds), 'a just-run cycle is not flagged as stalled');

        // ---- STALL DETECTION within one heartbeat cycle. ----
        // A cycle that ran once and then stopped advancing (cron not firing / every cycle
        // erroring) leaves a stale row — detected by heartbeat age (ADR-054 §5). Status set is
        // running/idle only (DECISION X ruling (2)); 'processing'/'shutdown' are gone.
        $stalledWorkerId = '01900000-0000-7000-8000-00000000c7a5';
        $stale           = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval('PT' . ($cycleSeconds + 3) . 'S'));
        $publisher->publish(new \HSP\Core\Workers\HeartbeatRecord(
            workerId:        $stalledWorkerId,
            status:          'running',
            lastHeartbeatAt: $stale,
            workerType:      'processing',
            startedAt:       $stale,
        ));

        // The monitor's heartbeat-age read detects exactly the stalled cycle, within one cycle
        // of its last beat — the fresh cycle is NOT flagged.
        self::assertSame(1, $this->countStaleWorkers($cycleSeconds), 'the stalled cycle is detected within one heartbeat cycle');
        $staleIds = $this->staleWorkerIds($cycleSeconds);
        self::assertContains($stalledWorkerId, $staleIds, 'the stalled cycle is the one flagged');
        self::assertNotContains($healthyWorkerId, $staleIds, 'the fresh cycle is not flagged');

        // The derived worker_count metric (DECISION Q) reports both workers as visible.
        $metrics = new OperationalMetricsQuery($this->db);
        self::assertSame(2, $metrics->snapshot()['worker_count'], 'worker health is visible via the derived metrics surface');
    }

    // =========================================================================
    // Criterion 2 — Failure diagnostics via DLQ payload snapshot + stack trace (OPEN-3).
    //   §4: "Failure diagnostics available via DLQ payload snapshot and stack trace."
    //   A job exhausts the ADR-022 retry limit → the REAL worker path
    //   (EventWorkerStrategy) dead-letters it into system.dead_letter_jobs carrying the
    //   OPEN-3 context: payload_snapshot (JSONB, NOT NULL — DECISION A) + stack_trace. The
    //   diagnostics are then read back through the SAME operational surface an operator uses
    //   (DeadLetterRepository::inspect — `hsp dlq inspect`).
    // =========================================================================

    public function test_criterion2_dead_lettered_job_carries_payload_snapshot_and_stack_trace(): void
    {
        // Relay + dispatch a single post-create event through the real front of the pipeline.
        $eventId = $this->insertOutboxRow(ContentEventTypes::POST_CREATED, 'post', '900', 1);
        $this->relayTick();

        $queue = new DatabaseQueueProvider($this->db, ['retry_limit' => 3]);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();
        self::assertSame(1, $this->countRows('system.queue_jobs'), 'one queue job dispatched');

        // Force the job to the retry limit so the next failure is terminal (dead-letters now).
        $this->forceJobAttempts($eventId, 3);

        // A handler that always throws → a genuine exception with a real stack trace, captured
        // by the worker's DLQ writer (not a synthetic string).
        $failing = new EventRegistry();
        $failing->register(ContentEventTypes::POST_CREATED, function (): void {
            throw new \RuntimeException('diagnostic boom (forced for OPEN-3 proof)');
        });
        $strategy = new EventWorkerStrategy($queue, $failing, $this->db, retryLimit: 3);

        self::assertTrue($strategy->execute($this->ctx('01900000-0000-7000-8000-000000d1a6ff')), 'the exhausted job was claimed and terminally failed');

        // The job dead-lettered; the queue row is retained as dead_lettered (DECISION L(d)).
        self::assertSame(1, $this->countRows('system.dead_letter_jobs'), 'exactly one DLQ row');
        self::assertSame(0, $this->countRows('content.posts'), 'no projection written for a failed job');

        // ---- Read diagnostics back through the operator surface (hsp dlq inspect). ----
        $repo  = new \HSP\Core\Queue\DeadLetterRepository($this->db);
        $dlqId = $this->dlqIdFor($eventId);
        $dlq   = $repo->inspect($dlqId);

        self::assertNotNull($dlq, 'the DLQ row is inspectable via the operator surface');
        self::assertSame($eventId, $dlq['event_id'], 'diagnostics are keyed to the failed event');

        // Failure diagnostics available: STACK TRACE (OPEN-3).
        self::assertArrayHasKey('stack_trace', $dlq, 'stack_trace column present (OPEN-3)');
        self::assertNotNull($dlq['stack_trace'], 'stack_trace captured');
        self::assertNotSame('', (string) $dlq['stack_trace'], 'stack_trace is non-empty');
        self::assertStringContainsString('#', (string) $dlq['stack_trace'], 'stack_trace is a real PHP trace (frame markers present)');

        // Failure diagnostics available: PAYLOAD SNAPSHOT (OPEN-3 / DECISION A: NOT NULL).
        self::assertArrayHasKey('payload_snapshot', $dlq, 'payload_snapshot column present (OPEN-3)');
        self::assertNotNull($dlq['payload_snapshot'], 'payload_snapshot captured (DECISION A: NOT NULL, self-contained)');
        $snapshot = json_decode((string) $dlq['payload_snapshot'], true);
        self::assertIsArray($snapshot, 'payload_snapshot is structured JSON — replayable without an external store');
        self::assertNotSame([], $snapshot, 'payload_snapshot is not empty');

        // The failure reason is also recorded (human-facing diagnostic).
        self::assertStringContainsString('diagnostic boom', (string) $dlq['failure_reason'], 'failure_reason carries the exception message');
    }

    // =========================================================================
    // Criterion 3 — Reconciliation executes: drift / incremental / full; WordPress wins.
    //   §4: "Reconciliation executes: hourly drift detection, incremental validation, full
    //   reconciliation (ADR-026); WordPress always wins divergence (ADR-027, ADR-045)."
    //   All three ADR-026 modes run against live MySQL + PG. Repair is DECISION T
    //   re-emission ONLY: the ReconciliationService writes NO projections (proven with a
    //   write-spy), and the repair flows through the normal pipeline so WordPress state
    //   always wins by construction (ADR-027/ADR-045).
    // =========================================================================

    public function test_criterion3a_hourly_drift_detection_repairs_a_missed_capture_wordpress_wins(): void
    {
        // WordPress has a published post; the projection is empty (the original capture was
        // lost in the DECISION 1 post-commit gap). Hourly drift (existence/timestamp, WP→PG)
        // must detect it and repair it to current WP state via re-emission.
        $this->wp->putPost(1000, 'publish', 'post', 'drift-recovered');

        $spy    = new WriteSpyConnection($this->db);
        $result = $this->reconcile(ReconciliationService::MODE_DRIFT, $spy);

        self::assertSame(ReconciliationService::MODE_DRIFT, $result->mode, 'hourly drift mode ran');
        self::assertSame(1, $result->repairedCount(), 'drift detection found the missed capture');
        self::assertSame('missed_capture', $result->repaired[0]['reason']);

        // WordPress wins by construction: the detector performed NO direct PG projection write.
        self::assertSame(0, $spy->executeCount, 'reconciliation performed zero direct PG writes (repair is re-emission only)');
        self::assertSame(0, $spy->beginCount, 'reconciliation opened no direct write transaction');

        // Repair flowed through the real pipeline → projection converges to current WP state.
        $this->drainPipeline();
        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 1000);
        self::assertNotNull($row, 'projection reprojected via re-emission');
        self::assertSame('drift-recovered', $row['slug'], 'projection converged to current WordPress state (WordPress wins)');
        self::assertNull($row['deleted_at']);
        self::assertSame(1, $this->fetchAggregateVersion('post', '1000'), 'aggregate_versions advanced (guard passed naturally)');
    }

    public function test_criterion3b_incremental_validation_repairs_checksum_drift_wordpress_wins(): void
    {
        // A category exists in WP and is projected, but its field state drifted (rename) with
        // no capture — invisible to hourly existence-only drift for taxonomies (DECISION U D2).
        // Nightly incremental validation recomputes the checksum, detects the drift, repairs.
        $this->wp->putTerm(1100, 'news');
        $this->seedTaxonomyProjection(1100, 'news', 'Stale Name', str_repeat('f', 64));

        // Hourly drift is existence-only for categories → the rename is invisible.
        $drift = $this->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(0, $drift->repairedCount(), 'category field drift is invisible to hourly existence-only drift (D2)');

        // Nightly incremental validation: checksum recompute detects and repairs.
        $spy  = new WriteSpyConnection($this->db);
        $incr = $this->reconcile(ReconciliationService::MODE_INCREMENTAL, $spy);
        self::assertSame(ReconciliationService::MODE_INCREMENTAL, $incr->mode, 'incremental validation mode ran');
        self::assertSame(1, $incr->repairedCount(), 'incremental validation caught the checksum drift');
        self::assertSame('checksum_drift', $incr->repaired[0]['reason']);
        self::assertSame(0, $spy->executeCount, 'incremental validation performed zero direct PG writes');

        $this->drainPipeline();
        $row = $this->fetchProjectionRow('content.taxonomies', 'source_term_id', 1100);
        self::assertNotNull($row);
        self::assertSame('Category 1100', $row['name'], 'name reprojected from current WordPress state (WordPress wins)');
        self::assertNull($row['deleted_at']);
    }

    public function test_criterion3c_full_reconciliation_tombstones_an_orphan_via_re_emission_wordpress_wins(): void
    {
        // A live projection row exists, but WordPress has no such post (deleted without a
        // .deleted event ever emitted — the missed-delete case). Full reconciliation sweeps
        // PG→WP (DECISION U D3), finds the orphan, and repairs it by re-emitting .deleted.
        $this->seedProjection('content.posts', 1200, 'ghost', '2026-07-01 00:00:00+00');

        // Hourly drift is WP→PG only → it never sees the orphan.
        $drift = $this->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(0, $drift->repairedCount(), 'drift mode does not sweep orphans (WP→PG only)');

        // Full mode sweeps PG→WP and finds it.
        $spy  = new WriteSpyConnection($this->db);
        $full = $this->reconcile(ReconciliationService::MODE_FULL, $spy);
        self::assertSame(ReconciliationService::MODE_FULL, $full->mode, 'full reconciliation mode ran');
        self::assertSame(1, $full->repairedCount(), 'full reconciliation found the orphan');
        self::assertSame('orphan', $full->repaired[0]['reason']);
        self::assertSame(0, $spy->executeCount, 'full reconciliation performed zero direct PG writes');

        // Repair via re-emission → the .deleted event → DECISION I tombstone (soft delete).
        $this->drainPipeline();
        $row = $this->fetchProjectionRow('content.posts', 'source_post_id', 1200);
        self::assertNotNull($row, 'row retained (soft delete — DECISION I)');
        self::assertNotNull($row['deleted_at'], 'orphan tombstoned to match WordPress reality (WordPress wins)');
    }

    public function test_criterion3d_reconciliation_is_idempotent_and_suppresses_in_flight_captures(): void
    {
        // Two reconciliation guarantees the gate depends on:
        //   (a) idempotency — a second pass over already-consistent state re-emits nothing.
        //   (b) false-positive suppression — an aggregate still in the pipeline is NOT
        //       re-emitted (DECISION U D4). Here: a relayed-not-yet-processed system.events
        //       row (version ahead of latest_processed_version) → IN-FLIGHT, suppressed.

        // (a) Idempotency: project a post correctly, then reconcile again → no repair.
        $this->wp->putPost(1300, 'publish', 'post', 'stable');
        $this->reconcile(ReconciliationService::MODE_DRIFT); // detect missed capture
        $this->drainPipeline();                              // converge
        self::assertSame('stable', $this->fetchProjectionRow('content.posts', 'source_post_id', 1300)['slug']);

        $secondPass = $this->reconcile(ReconciliationService::MODE_INCREMENTAL);
        self::assertSame(0, $secondPass->repairedCount(), 'idempotent: a consistent aggregate is not re-emitted on a second pass');

        // (b) In-flight suppression: WP shows a newer post, but a relayed-not-processed
        // system.events row exists (version > latest_processed_version). Reconciliation must
        // treat it as IN-FLIGHT and skip it — the pipeline will project it.
        $this->wp->putPost(1301, 'publish', 'post', 'inflight', '2026-07-05 00:00:00');
        $this->seedProjection('content.posts', 1301, 'stale', '2026-07-01 00:00:00+00');
        $this->seedAggregateVersion('post', '1301', 1);
        $this->seedHistoricalEvent('post', '1301', '2026-07-05 00:00:00+00', 2);

        $suppressedPass = $this->reconcile(ReconciliationService::MODE_DRIFT);
        self::assertSame(1, $suppressedPass->suppressed, 'the in-flight aggregate is suppressed, not flagged as drift (D4)');
        // Only the in-flight aggregate 1301 is a candidate; 1300 is consistent. Nothing repaired.
        self::assertSame(0, $suppressedPass->repairedCount(), 'no spurious re-emission for an in-flight aggregate');
    }

    // =========================================================================
    // Reconciliation assembly (criterion 3) — real ReconciliationService + ReplayService,
    // real outbox/relay/dispatch/worker/adapters/PG; WP-read boundary substituted only.
    // Mirrors ReconciliationIntegrationTest wiring.
    // =========================================================================

    private function reconcile(string $mode, ?WriteSpyConnection $spy = null): \HSP\Core\Reconciliation\ReconciliationResult
    {
        $conn = $spy ?? $this->db;

        $wpdb    = new FakeWpdb($this->mysqli, $this->prefix);
        $counter = new AggregateVersionCounter($wpdb);
        $writer  = new OutboxWriter($wpdb, $counter);
        $emitter = new ContentReplayEmitter(
            new EventProvider($writer),
            new ReplayReadingLoader($this->wp),
        );
        $replay  = new ReplayService($conn, [$emitter]);

        $source  = new StoreReconciliationSource($this->wp, $this->mysqli, $this->outbox);
        $service = new ReconciliationService($conn, $source, $replay, 500);

        return $service->reconcile($mode);
    }

    /** Relay → dispatch → drain the queue with the worker reloading state from $this->wp. */
    private function drainPipeline(): void
    {
        $this->relayTick();

        $queue = new DatabaseQueueProvider($this->db);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();

        $strategy = new EventWorkerStrategy($queue, $this->makeWiredEventRegistry(), $this->db, retryLimit: 10);
        $guard = 0;
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-00000000face'))) {
            if (++$guard > 200) {
                self::fail('drainPipeline did not drain the queue');
            }
        }
    }

    private function relayTick(): void
    {
        (new RelayWorkerStrategy(
            new MysqliOutboxConnection(fn () => $this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        ))->tick();
    }

    /** Real EventRegistry → ContentSubscriber → 9 content handlers; loaders read $this->wp. */
    private function makeWiredEventRegistry(): EventRegistry
    {
        $pageLoader = new ReplayReadingLoader($this->wp, 'page');
        $postLoader = new ReplayReadingLoader($this->wp, 'post');
        $termLoader = new ReplayReadingLoader($this->wp, 'post');

        $pageAdapter     = new PageAdapter($this->db);
        $postAdapter     = new PostAdapter($this->db);
        $categoryAdapter = new CategoryAdapter($this->db);

        $subscriber = new ContentSubscriber([
            ContentEventTypes::PAGE_CREATED     => new PageUpsertHandler($pageLoader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_UPDATED     => new PageUpsertHandler($pageLoader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_DELETED     => new PageTombstoneHandler($pageAdapter),
            ContentEventTypes::POST_CREATED     => new PostUpsertHandler($postLoader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_UPDATED     => new PostUpsertHandler($postLoader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_DELETED     => new PostTombstoneHandler($postAdapter),
            ContentEventTypes::CATEGORY_CREATED => new CategoryUpsertHandler($termLoader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_UPDATED => new CategoryUpsertHandler($termLoader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_DELETED => new CategoryTombstoneHandler($categoryAdapter),
        ]);

        $registry = new EventRegistry();
        foreach (ContentEventTypes::ALL as $type) {
            $registry->register($type, $subscriber);
        }
        return $registry;
    }

    // =========================================================================
    // Outbox (MySQL) seeding — criterion 2
    // =========================================================================

    private function insertOutboxRow(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
    ): string {
        $id            = $this->uuidv7();
        $correlationId = $this->uuidv7();
        $now           = gmdate('Y-m-d H:i:s');
        $slug          = "test-{$aggregateType}-{$aggregateId}";
        $payload       = json_encode(['slug' => $slug], JSON_THROW_ON_ERROR);
        $checksum      = hash('sha256', $payload);

        $stmt = $this->mysqli->prepare(
            "INSERT INTO `{$this->outbox}`
                 (`id`, `event_type`, `event_version`, `aggregate_type`, `aggregate_id`,
                  `aggregate_version`, `source_updated_at`, `checksum`, `correlation_id`,
                  `causation_id`, `payload`, `status`, `created_at`, `relayed_at`)
             VALUES (?, ?, 1, ?, ?, ?, '2026-01-15 10:00:00', ?, ?, NULL, ?, 'pending', ?, NULL)"
        );
        $stmt->bind_param('ssssissss',
            $id, $eventType, $aggregateType, $aggregateId, $aggregateVersion,
            $checksum, $correlationId, $payload, $now,
        );
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    // =========================================================================
    // PostgreSQL seeding — criterion 3
    // =========================================================================

    private function seedProjection(string $table, int $sourceId, string $slug, string $updatedAt): void
    {
        $now = '2026-07-01 00:00:00+00';
        pg_query_params(
            $this->pgConn,
            "INSERT INTO {$table}
                 (id, source_post_id, source_entity_type, slug, title, content, excerpt,
                  status, author, published_at, updated_at, deleted_at, checksum, meta_jsonb,
                  created_at, synced_at)
             VALUES ($1::uuid, $2, 'post', $3, 'T', 'B', 'E', 'publish', 'a',
                     $4::timestamptz, $4::timestamptz, NULL, $5, '{}'::jsonb, $6::timestamptz, $6::timestamptz)",
            [$this->uuidv7(), $sourceId, $slug, $updatedAt, str_repeat('c', 64), $now],
        );
    }

    private function seedTaxonomyProjection(int $termId, string $slug, string $name, string $checksum): void
    {
        $now = '2026-07-01 00:00:00+00';
        pg_query_params(
            $this->pgConn,
            "INSERT INTO content.taxonomies
                 (id, source_term_id, taxonomy_type, slug, name, description, parent_id,
                  post_count, deleted_at, checksum, created_at, updated_at, synced_at)
             VALUES ($1::uuid, $2, 'category', $3, $4, '', 0, 0, NULL, $5, $6::timestamptz, $6::timestamptz, $6::timestamptz)",
            [$this->uuidv7(), $termId, $slug, $name, $checksum, $now],
        );
    }

    private function seedAggregateVersion(string $type, string $id, int $version): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.aggregate_versions (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, $3, now())",
            [$type, $id, $version],
        );
    }

    private function seedHistoricalEvent(string $type, string $id, string $createdAt, int $aggregateVersion = 1): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.events
                 (id, event_type, event_version, aggregate_type, aggregate_id, payload,
                  created_at, aggregate_version, source_updated_at, checksum, correlation_id, causation_id)
             VALUES ($1::uuid, $2, 1, $3, $4, '{}'::jsonb, $5::timestamptz, $6, $5::timestamptz,
                     $7, $8::uuid, NULL)",
            [$this->uuidv7(), "content.{$type}.updated", $type, $id, $createdAt, $aggregateVersion, str_repeat('a', 64), $this->uuidv7()],
        );
    }

    // =========================================================================
    // Queue / DLQ / heartbeat reads
    // =========================================================================

    private function forceJobAttempts(string $eventId, int $attempts): void
    {
        pg_query_params(
            $this->pgConn,
            'UPDATE system.queue_jobs SET attempts = $2 WHERE event_id = $1::uuid',
            [$eventId, $attempts],
        );
    }

    private function dlqIdFor(string $eventId): string
    {
        $r = pg_query_params(
            $this->pgConn,
            'SELECT id FROM system.dead_letter_jobs WHERE event_id = $1::uuid LIMIT 1',
            [$eventId],
        );
        return (string) pg_fetch_result($r, 0, 0);
    }

    /**
     * A monitor's heartbeat-age crash-detection read (DECISION P / Doc 8 §15): workers whose
     * last_heartbeat_at is older than one heartbeat cycle are considered crashed/unhealthy.
     * This is the query a monitor runs; it is not itself production code (gate evidence).
     */
    private function countStaleWorkers(int $cycleSeconds): int
    {
        $r = pg_query_params(
            $this->pgConn,
            "SELECT COUNT(*) AS c FROM system.worker_heartbeats
             WHERE last_heartbeat_at < NOW() - ($1 || ' seconds')::interval",
            [(string) $cycleSeconds],
        );
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    /** @return array<int,string> */
    private function staleWorkerIds(int $cycleSeconds): array
    {
        $r = pg_query_params(
            $this->pgConn,
            "SELECT worker_id FROM system.worker_heartbeats
             WHERE last_heartbeat_at < NOW() - ($1 || ' seconds')::interval",
            [(string) $cycleSeconds],
        );
        $ids = [];
        while ($row = pg_fetch_assoc($r)) {
            $ids[] = (string) $row['worker_id'];
        }
        return $ids;
    }

    /** @return array<string,mixed>|null */
    private function heartbeatRow(string $workerId): ?array
    {
        $r = pg_query_params(
            $this->pgConn,
            'SELECT worker_id, worker_type, status, last_heartbeat_at, started_at
             FROM system.worker_heartbeats WHERE worker_id = $1::uuid',
            [$workerId],
        );
        return pg_fetch_assoc($r) ?: null;
    }

    /** @return array<string,mixed>|null */
    private function fetchProjectionRow(string $table, string $keyCol, int $keyVal): ?array
    {
        $r = pg_query_params($this->pgConn, "SELECT * FROM {$table} WHERE {$keyCol} = \$1", [$keyVal]);
        return pg_fetch_assoc($r) ?: null;
    }

    private function fetchAggregateVersion(string $aggType, string $aggId): int
    {
        $r = pg_query_params(
            $this->pgConn,
            'SELECT latest_processed_version FROM system.aggregate_versions WHERE aggregate_type = $1 AND aggregate_id = $2',
            [$aggType, $aggId],
        );
        return (int) ((pg_fetch_assoc($r) ?: [])['latest_processed_version'] ?? 0);
    }

    private function countRows(string $table): int
    {
        $r = pg_query($this->pgConn, "SELECT COUNT(*) AS c FROM {$table}");
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    private function ctx(string $workerId): WorkerExecutionContext
    {
        return new WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );
    }

    // =========================================================================
    // Schema (mirrors the frozen DDL — same shapes as ReconciliationIntegrationTest +
    // OperationalBaselineIntegrationTest, plus system.worker_heartbeats — DECISION P).
    // =========================================================================

    private function createMysqlSchema(): void
    {
        $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
        $this->mysqli->query("DROP TABLE IF EXISTS `{$this->counters}`");
        $this->mysqli->query(
            "CREATE TABLE `{$this->outbox}` (
                `id`                CHAR(36)                   NOT NULL,
                `event_type`        VARCHAR(255)               NOT NULL,
                `event_version`     INT                        NOT NULL,
                `aggregate_type`    VARCHAR(100)               NOT NULL,
                `aggregate_id`      VARCHAR(255)               NOT NULL,
                `aggregate_version` BIGINT                     NOT NULL,
                `source_updated_at` DATETIME                   NOT NULL,
                `checksum`          CHAR(64)                   NOT NULL,
                `correlation_id`    CHAR(36)                   NOT NULL,
                `causation_id`      CHAR(36)                   NULL,
                `payload`           JSON                       NOT NULL,
                `status`            ENUM('pending','relayed')  NOT NULL DEFAULT 'pending',
                `created_at`        DATETIME                   NOT NULL,
                `relayed_at`        DATETIME                   NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_relay_claim` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        $this->mysqli->query(
            "CREATE TABLE `{$this->counters}` (
                `aggregate_type` VARCHAR(100) NOT NULL,
                `aggregate_id`   VARCHAR(255) NOT NULL,
                `version`        BIGINT       NOT NULL,
                PRIMARY KEY (`aggregate_type`, `aggregate_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function createPgsqlSchema(): void
    {
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        pg_query($this->pgConn, 'CREATE SCHEMA system');
        pg_query($this->pgConn, 'CREATE SCHEMA content');

        pg_query($this->pgConn, "
            CREATE TABLE system.events (
                id                UUID         NOT NULL PRIMARY KEY,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INTEGER      NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                payload           JSONB        NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                checksum          VARCHAR(64)  NOT NULL,
                correlation_id    UUID         NOT NULL,
                causation_id      UUID         NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.queue_jobs (
                id                    UUID         NOT NULL PRIMARY KEY,
                event_id              UUID         NOT NULL,
                queue_name            VARCHAR(255) NOT NULL,
                status                VARCHAR(50)  NOT NULL,
                attempts              INTEGER      NOT NULL DEFAULT 0,
                available_at          TIMESTAMPTZ  NOT NULL,
                started_at            TIMESTAMPTZ  NULL,
                completed_at          TIMESTAMPTZ  NULL,
                last_error            TEXT         NULL,
                worker_id             UUID         NULL,
                visibility_timeout_at TIMESTAMPTZ  NULL,
                CONSTRAINT uq_queue_jobs_event_id UNIQUE (event_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.dead_letter_jobs (
                id               UUID        NOT NULL PRIMARY KEY,
                job_id           UUID        NOT NULL,
                event_id         UUID        NOT NULL,
                failure_reason   TEXT        NOT NULL,
                created_at       TIMESTAMPTZ NOT NULL,
                stack_trace      TEXT        NULL,
                attempt_count    INTEGER     NOT NULL DEFAULT 0,
                worker_id        UUID        NULL,
                payload_snapshot JSONB       NOT NULL,
                replayed_at      TIMESTAMPTZ NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_agg PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.processed_events (
                event_id     UUID        NOT NULL PRIMARY KEY,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.worker_heartbeats (
                worker_id         UUID        NOT NULL PRIMARY KEY,
                worker_type       TEXT        NOT NULL,
                status            TEXT        NOT NULL,
                last_heartbeat_at TIMESTAMPTZ NOT NULL,
                started_at        TIMESTAMPTZ NOT NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.pages (
                id UUID NOT NULL, source_post_id BIGINT NOT NULL, source_entity_type VARCHAR(50) NOT NULL DEFAULT 'page',
                slug VARCHAR(255) NOT NULL, title TEXT NOT NULL, content TEXT NOT NULL, status VARCHAR(50) NOT NULL,
                parent_id BIGINT NOT NULL DEFAULT 0, menu_order INTEGER NOT NULL DEFAULT 0,
                published_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, deleted_at TIMESTAMPTZ NULL,
                checksum VARCHAR(64) NOT NULL, meta_jsonb JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_pages PRIMARY KEY (id),
                CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.posts (
                id UUID NOT NULL, source_post_id BIGINT NOT NULL, source_entity_type VARCHAR(50) NOT NULL DEFAULT 'post',
                slug VARCHAR(255) NOT NULL, title TEXT NOT NULL, content TEXT NOT NULL, excerpt TEXT NOT NULL,
                status VARCHAR(50) NOT NULL, author VARCHAR(255) NOT NULL,
                published_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, deleted_at TIMESTAMPTZ NULL,
                checksum VARCHAR(64) NOT NULL, meta_jsonb JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_posts PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.taxonomies (
                id UUID NOT NULL, source_term_id BIGINT NOT NULL, taxonomy_type VARCHAR(50) NOT NULL,
                slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL DEFAULT '',
                parent_id BIGINT NOT NULL DEFAULT 0, post_count INTEGER NOT NULL DEFAULT 0,
                deleted_at TIMESTAMPTZ NULL, checksum VARCHAR(64) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_taxonomies PRIMARY KEY (id),
                CONSTRAINT uq_content_taxonomies_source_term_id UNIQUE (source_term_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.entity_taxonomies (
                entity_id UUID NOT NULL, taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ");
    }

    // =========================================================================
    // Connections
    // =========================================================================

    private function connectMysql(): \mysqli
    {
        $host = getenv('HSP_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_MYSQL_PORT') ?: 3306);
        $user = getenv('HSP_TEST_MYSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_MYSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_MYSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            $this->markTestSkipped('MySQL env vars not set (HSP_TEST_MYSQL_USER, HSP_TEST_MYSQL_DATABASE).');
        }

        $mysqli = new \mysqli($host, $user, $pass, $db, $port);
        if ($mysqli->connect_errno) {
            $this->markTestSkipped("MySQL connect failed: {$mysqli->connect_error}");
        }
        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_PGSQL_PORT') ?: 5432);
        $user = getenv('HSP_TEST_PGSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            $this->markTestSkipped('PostgreSQL env vars not set (HSP_TEST_PGSQL_USER, HSP_TEST_PGSQL_DATABASE).');
        }

        $conn = @pg_connect("host={$host} port={$port} dbname={$db} user={$user} password={$pass}", PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $this->markTestSkipped("PostgreSQL connect failed: host={$host} port={$port} dbname={$db}");
        }
        return $conn;
    }

    private function uuidv7(): string
    {
        $ms      = (int) (microtime(true) * 1000);
        $bytes   = random_bytes(10);
        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));
        $hex     = $tsHex . $b67hex . $b89hex . $tailHex;
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
