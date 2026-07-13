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
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
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
use HSP\Modules\Content\Subscribers\ContentSubscriber;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\EventProvider;
use HSP\Modules\Content\Replay\ContentReplayEmitter;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Integration\Replay\FakeWpdb;
use HSP\Tests\Integration\Replay\FakeWpStore;
use HSP\Tests\Integration\Replay\ReplayReadingLoader;
use PHPUnit\Framework\TestCase;

/**
 * GATE-S2 — Architecture Validation Gate: Scalability Validation.
 *
 * IMPLEMENTATION_PLAN.md §4 → Scalability Validation criteria (verbatim):
 *   1. Multiple concurrent worker processes claim jobs without collision (SKIP LOCKED verified)
 *   2. Queue growth handled without head-blocking
 *   3. Replay under load does not corrupt normal processing
 *
 * This is a GATE session: evidence only, no production code changes. Each criterion is
 * proven end-to-end against LIVE MySQL + LIVE PostgreSQL by assembling the real runtime
 * components (DatabaseQueueProvider / RelayWorkerStrategy → EventDispatcher →
 * EventWorkerStrategy → ContentSubscriber → content.* adapters, and the DECISION T
 * ReplayService for criterion 3). The only substitution is the WordPress state-reload
 * boundary (DECISION H / ADR-044) that a headless PHPUnit process cannot bootstrap.
 *
 * Genuine concurrency without multiple OS processes:
 *   A single PHPUnit process cannot fork worker daemons, but it CAN open multiple distinct
 *   PostgreSQL sessions (physical links via PGSQL_CONNECT_FORCE_NEW) and interleave their
 *   transactions by hand. Two sessions each holding a FOR UPDATE row lock at the SAME instant
 *   is exactly the concurrency SKIP LOCKED exists to survive — and it is deterministic here
 *   (no sleeps-as-proof): session A takes and HOLDS a lock, then session B runs the identical
 *   claim query and must skip A's locked row rather than block on it. A low statement_timeout
 *   on session B turns "blocks on A's row" into a hard, observable failure.
 *
 * Environment (self-skips only if a DB is genuinely absent — same contract as GATE-S1):
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class ScalabilityValidationTest extends TestCase
{
    private ?\mysqli $mysqli = null;
    private mixed    $pgConn = null;
    private PostgresDatabaseConnection $db;

    private string $prefix = 'test_gate_s2_';
    private string $outbox;
    private string $counters;

    /** Extra physical PG sessions opened by concurrency tests; closed in tearDown. */
    private array $extraPgConns = [];

    /** In-memory WordPress state the replay emitter/handlers read (DECISION H reload boundary). */
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
        foreach ($this->extraPgConns as $conn) {
            if ($conn !== null) {
                // Roll back any lock still held by a hand-driven session before closing.
                @pg_query($conn, 'ROLLBACK');
                @pg_close($conn);
            }
        }
        $this->extraPgConns = [];

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
    // Criterion 1 — Multiple concurrent worker processes claim jobs without
    //   collision (SKIP LOCKED verified).
    //
    //   §4: "Multiple concurrent worker processes claim jobs without collision
    //        (SKIP LOCKED verified)."
    //   OPEN-4: job claiming uses SELECT … FOR UPDATE SKIP LOCKED so concurrent
    //   claimants neither collide (double-claim) nor block on each other's rows.
    // =========================================================================

    /**
     * 1a — SKIP LOCKED, genuinely concurrent: two live PG sessions each hold a FOR UPDATE
     * lock at the same instant; neither claims the other's row and neither blocks.
     *
     * This is the load-bearing SKIP LOCKED proof. Session B runs with a low statement_timeout,
     * so if it were to block on session A's locked head row (the failure OPEN-4 rules out) it
     * would raise a lock_timeout instead of skipping — turning "no claimant blocks" into a
     * deterministic assertion rather than a sleep.
     */
    public function test_criterion1a_concurrent_sessions_skip_locked_rows_without_blocking_or_double_claiming(): void
    {
        // N ready jobs, all available now, distinct available_at so ordering is deterministic.
        $jobIds = $this->seedAvailableJobs(8);
        self::assertCount(8, $jobIds);

        // Two independent physical PG sessions — the stand-ins for two worker processes.
        $sessionA = $this->openExtraPgSession();
        $sessionB = $this->openExtraPgSession();

        // Session B must never wait on a lock: if SKIP LOCKED failed and B blocked on A's row,
        // this timeout fires and the test fails loudly instead of hanging.
        pg_query($sessionB, "SET statement_timeout = '2000'");

        // --- Session A claims the head row and HOLDS the lock (no commit yet). ---
        pg_query($sessionA, 'BEGIN');
        $rowA = pg_fetch_assoc(pg_query($sessionA, $this->claimSelectSql()));
        self::assertNotFalse($rowA, 'session A claimed a row');
        $claimedByA = (string) $rowA['id'];

        // --- Session B, WHILE A still holds its lock, runs the identical claim query. ---
        pg_query($sessionB, 'BEGIN');
        $resB = pg_query($sessionB, $this->claimSelectSql());
        self::assertNotFalse($resB, 'session B claim query did not error — it did NOT block on A\'s locked row');
        $rowB = pg_fetch_assoc($resB);
        self::assertNotFalse($rowB, 'session B claimed a row concurrently');
        $claimedByB = (string) $rowB['id'];

        // No collision: B got a DIFFERENT row than the one A has locked (SKIP LOCKED skipped it).
        self::assertNotSame($claimedByA, $claimedByB, 'session B skipped A\'s locked row — no double-claim');

        // Both A's and B's locks are held simultaneously right now. A THIRD independent session
        // proves both in-flight claims are genuinely concurrent (not serialized): it must skip
        // BOTH locked rows — a SELECT ... FOR UPDATE SKIP LOCKED only skips locks held by OTHER
        // transactions, so a third session is the correct observer here (re-running the query in
        // session B would re-select B's own already-locked row).
        $sessionC = $this->openExtraPgSession();
        pg_query($sessionC, "SET statement_timeout = '2000'"); // must not block either
        pg_query($sessionC, 'BEGIN');
        $resC = pg_query($sessionC, $this->claimSelectSql());
        self::assertNotFalse($resC, 'session C did not block on either held lock');
        $rowC = pg_fetch_assoc($resC);
        self::assertNotFalse($rowC, 'a third distinct job is still claimable while two rows are locked');
        self::assertNotContains(
            (string) $rowC['id'],
            [$claimedByA, $claimedByB],
            'the third session skipped BOTH already-locked rows — two claims are truly concurrent',
        );

        // Release all sessions cleanly (locks dropped; rows untouched — SELECT only).
        pg_query($sessionA, 'ROLLBACK');
        pg_query($sessionB, 'ROLLBACK');
        pg_query($sessionC, 'ROLLBACK');
    }

    /**
     * 1b — No double-claim through the real provider: N jobs, several distinct worker
     * identities on distinct physical connections, interleaved. Every job is claimed by
     * exactly one worker; the claimed set is the whole queue; nothing is claimed twice.
     */
    public function test_criterion1b_real_provider_claims_every_job_exactly_once_across_workers(): void
    {
        $n      = 12;
        $jobIds = $this->seedAvailableJobs($n);

        // Three "workers": each its own physical PG link + its own provider instance + id.
        $workers = [];
        for ($i = 0; $i < 3; $i++) {
            $conn = $this->openExtraPgSession();
            $workers[] = [
                'id'       => sprintf('01900000-0000-7000-8000-0000000000%02d', $i + 1),
                'provider' => new DatabaseQueueProvider(new PostgresDatabaseConnection($conn)),
            ];
        }

        // Round-robin claim until the queue is drained. Each claim() is a self-contained
        // FOR UPDATE SKIP LOCKED transaction (commits immediately) — exactly what a real
        // worker loop does per tick.
        $claimedBy = [];   // job_id => worker_id
        $guard     = 0;
        $emptyRun  = 0;
        while ($emptyRun < count($workers)) {
            $progressed = false;
            foreach ($workers as $w) {
                $job = $w['provider']->claim('content', $w['id']);
                if ($job === null) {
                    continue;
                }
                $progressed = true;
                $jobId = (string) $job['id'];
                self::assertArrayNotHasKey($jobId, $claimedBy, "job {$jobId} was claimed a second time — collision");
                $claimedBy[$jobId] = $w['id'];

                if (++$guard > 100) {
                    self::fail('claim loop did not terminate — possible double-claim storm');
                }
            }
            $emptyRun = $progressed ? 0 : $emptyRun + 1;
        }

        // Every seeded job claimed exactly once; the claimed set equals the whole queue.
        self::assertCount($n, $claimedBy, 'every job claimed exactly once — no collisions, none lost');
        sort($jobIds);
        $claimedIds = array_keys($claimedBy);
        sort($claimedIds);
        self::assertSame($jobIds, $claimedIds, 'the claimed set is exactly the seeded queue');

        // Work was genuinely spread across workers (not one worker draining everything before
        // the others ever ran) — at least two workers claimed at least one job each.
        $distinctWorkers = count(array_unique(array_values($claimedBy)));
        self::assertGreaterThanOrEqual(2, $distinctWorkers, '≥2 workers genuinely participated');

        // No PG-side double-claim: no job row is 'claimed' by two owners; all are claimed once.
        self::assertSame($n, $this->countJobsWithStatus('claimed'), 'all N rows are claimed, once each');
    }

    // =========================================================================
    // Criterion 2 — Queue growth handled without head-blocking.
    //   §4: "Queue growth handled without head-blocking."
    //   A stuck/long-running job at the HEAD of the queue must not prevent other ready
    //   jobs from being claimed and completed. SKIP LOCKED is what makes this hold: the
    //   head row's lock is skipped, not waited on.
    // =========================================================================

    public function test_criterion2_stuck_head_job_does_not_block_other_ready_jobs(): void
    {
        // A queue where the HEAD (earliest available_at) is the job that gets stuck.
        // 1 head job + 6 followers = 7 ready jobs.
        $head      = $this->seedAvailableJob(availableAtOffsetSeconds: -100); // earliest → head of queue
        $followers = [];
        for ($i = 0; $i < 6; $i++) {
            $followers[] = $this->seedAvailableJob(availableAtOffsetSeconds: -50 + $i);
        }

        // --- Worker A claims the HEAD and gets stuck on it (long visibility timeout: a
        //     long-running/hung job). Its FOR UPDATE lock is released on commit, but the row
        //     is now status='claimed' with a far-future visibility_timeout_at, so it is NOT
        //     re-claimable — it sits, stuck, at the head. ---
        $connA     = $this->openExtraPgSession();
        $providerA = new DatabaseQueueProvider(
            new PostgresDatabaseConnection($connA),
            ['visibility_timeout_seconds' => 36000], // 10h — effectively "stuck at the head"
        );
        $stuck = $providerA->claim('content', '01900000-0000-7000-8000-00000000aaaa');
        self::assertNotNull($stuck, 'worker A claimed a job');
        self::assertSame($head, (string) $stuck['id'], 'worker A is stuck on the HEAD of the queue');

        // --- Worker B, on its own connection, must claim and COMPLETE every follower while
        //     the head remains stuck. Head-of-line blocking would starve B entirely. ---
        $connB     = $this->openExtraPgSession();
        $providerB = new DatabaseQueueProvider(new PostgresDatabaseConnection($connB));
        $workerB   = '01900000-0000-7000-8000-00000000bbbb';

        $completed = [];
        $guard     = 0;
        while (($job = $providerB->claim('content', $workerB)) !== null) {
            $jobId = (string) $job['id'];
            self::assertNotSame($head, $jobId, 'worker B never claimed the stuck head row (its lease is held)');
            self::assertTrue($providerB->complete($jobId, $workerB), 'worker B completed the follower job');
            $completed[] = $jobId;
            if (++$guard > 50) {
                self::fail('worker B did not drain the followers — head-of-line blocking suspected');
            }
        }

        // Every follower was processed to completion despite the stuck head.
        sort($followers);
        sort($completed);
        self::assertSame($followers, $completed, 'all ready followers claimed + completed past the stuck head');
        self::assertSame(6, $this->countJobsWithStatus('completed'), 'six followers completed');

        // The head is exactly as A left it: still claimed, never touched by B (no collision,
        // no starvation of the rest of the queue behind it).
        self::assertSame('claimed', $this->jobStatusById($head), 'the stuck head remains claimed by worker A');
        self::assertSame(1, $this->countJobsWithStatus('claimed'), 'only the stuck head is still claimed');
    }

    // =========================================================================
    // Criterion 3 — Replay under load does not corrupt normal processing.
    //   §4: "Replay under load does not corrupt normal processing."
    //   Run replay (DECISION S/T paths) CONCURRENTLY with normal event processing, then
    //   assert: final projections correct for BOTH replayed and normally-processed
    //   aggregates; aggregate_versions remain monotonic; no duplicate processed_events.
    // =========================================================================

    public function test_criterion3_replay_interleaved_with_normal_processing_stays_correct(): void
    {
        // --- Group R (replay target): aggregates that were synced earlier, then their
        //     projections drifted/corrupted — an operator replays them. Seed a good baseline
        //     FIRST (and drain it), then corrupt, so replay has something to converge. This
        //     baseline drain happens before any normal-load capture so it consumes nothing
        //     but the replay group. ---
        $this->wp->putPost(930, 'publish', 'post', 'gate-replay-930');
        $this->wp->putPost(931, 'publish', 'page', 'gate-replay-931');
        $this->replayStrategy()->replayEntity('post', '930');
        $this->replayStrategy()->replayEntity('page', '931');
        $this->drainPipeline(); // establish baseline projections for the replay group
        pg_query($this->pgConn, "UPDATE content.posts SET slug='CORRUPT-930', checksum='deadbeef30' WHERE source_post_id=930");
        pg_query($this->pgConn, "UPDATE content.pages SET slug='CORRUPT-931', checksum='deadbeef31' WHERE source_post_id=931");
        $version930Before = $this->fetchAggregateVersion('post', '930');
        $version931Before = $this->fetchAggregateVersion('page', '931');

        // --- Group N (normal load): fresh content edits captured in the outbox AFTER the
        //     replay baseline, so they are the only thing pending when the interleave begins.
        //     4 posts + 2 pages. ---
        $this->wp->putPost(910, 'publish', 'post', 'gate-normal-910');
        $this->wp->putPost(911, 'publish', 'post', 'gate-normal-911');
        $this->wp->putPost(912, 'publish', 'post', 'gate-normal-912');
        $this->wp->putPost(913, 'publish', 'post', 'gate-normal-913');
        $this->wp->putPost(920, 'publish', 'page', 'gate-normal-920');
        $this->wp->putPost(921, 'publish', 'page', 'gate-normal-921');
        foreach (['910', '911', '912', '913'] as $id) {
            $this->insertOutboxRow(ContentEventTypes::POST_CREATED, 'post', $id, 1);
        }
        foreach (['920', '921'] as $id) {
            $this->insertOutboxRow(ContentEventTypes::PAGE_CREATED, 'page', $id, 1);
        }

        // --- INTERLEAVE replay emission with normal-load processing. ---
        // 1. Relay + dispatch the normal-load batch so it is sitting in the queue, mid-flight.
        $this->relayTick();
        $queue = new DatabaseQueueProvider($this->db);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();
        $strategy = new EventWorkerStrategy($queue, $this->makeReplayWiredEventRegistry(), $this->db, retryLimit: 10);
        $ctxId = '01900000-0000-7000-8000-00000000c3c3';

        // 2. Process PART of the normal load (2 of 6 jobs), leaving the rest queued.
        self::assertTrue($strategy->execute($this->ctx($ctxId)));
        self::assertTrue($strategy->execute($this->ctx($ctxId)));

        // 3. NOW fire replay for the corrupted group — its synthetic events enter the SAME
        //    outbox → relay → dispatch → queue while normal jobs are still in flight.
        $replayResult = $this->replayStrategy()->replayEntity('post', '930');
        $replayResult2 = $this->replayStrategy()->replayEntity('page', '931');
        self::assertSame('content.post.updated', $replayResult->emitted[0]['event_type']);
        self::assertSame('content.page.updated', $replayResult2->emitted[0]['event_type']);
        $this->relayTick();
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();

        // 4. Drain EVERYTHING remaining — normal followers and replay events, fully interleaved
        //    in one queue, one worker loop. This is the "under load" processing.
        $guard = 0;
        while ($strategy->execute($this->ctx($ctxId))) {
            if (++$guard > 200) {
                self::fail('interleaved drain did not terminate');
            }
        }

        // ---- Assertion set A: normal-load aggregates projected correctly (uncorrupted). ----
        self::assertSame('gate-normal-910', $this->fetchProjectionRow('content.posts', 'source_post_id', 910)['slug']);
        self::assertSame('gate-normal-911', $this->fetchProjectionRow('content.posts', 'source_post_id', 911)['slug']);
        self::assertSame('gate-normal-912', $this->fetchProjectionRow('content.posts', 'source_post_id', 912)['slug']);
        self::assertSame('gate-normal-913', $this->fetchProjectionRow('content.posts', 'source_post_id', 913)['slug']);
        self::assertSame('gate-normal-920', $this->fetchProjectionRow('content.pages', 'source_post_id', 920)['slug']);
        self::assertSame('gate-normal-921', $this->fetchProjectionRow('content.pages', 'source_post_id', 921)['slug']);

        // ---- Assertion set B: replayed aggregates converged back to correct WP state. ----
        self::assertSame('gate-replay-930', $this->fetchProjectionRow('content.posts', 'source_post_id', 930)['slug'], 'replayed post converged despite concurrent normal load');
        self::assertSame('gate-replay-931', $this->fetchProjectionRow('content.pages', 'source_post_id', 931)['slug'], 'replayed page converged despite concurrent normal load');

        // ---- Assertion set C: aggregate_versions strictly monotonic (never regressed). ----
        // Replay took a FRESH counter version (DECISION T) so the replayed aggregates advanced;
        // normal-load aggregates sit at exactly version 1. No aggregate is below its prior value.
        self::assertGreaterThan($version930Before, $this->fetchAggregateVersion('post', '930'), 'post 930 version advanced (replay), never regressed');
        self::assertGreaterThan($version931Before, $this->fetchAggregateVersion('page', '931'), 'page 931 version advanced (replay), never regressed');
        foreach (['910', '911', '912', '913'] as $id) {
            self::assertSame(1, $this->fetchAggregateVersion('post', $id), "normal post {$id} at version 1");
        }

        // ---- Assertion set D: no duplicate processed_events, no duplicate projection rows. ----
        // One projection row per aggregate (idempotent upsert on source id — replay did not fork rows).
        self::assertSame(5, $this->countRows('content.posts'), 'five post rows (4 normal + 1 replay group), no dupes');
        self::assertSame(3, $this->countRows('content.pages'), 'three page rows (2 normal + 1 replay group), no dupes');
        // processed_events is PK-keyed on event_id; assert zero duplicates directly against PG.
        self::assertSame(0, $this->duplicateProcessedEventCount(), 'no duplicate processed_events rows');
        // Every distinct event that reached a handler recorded exactly one processed_events row.
        self::assertSame(
            $this->countRows('system.processed_events'),
            $this->distinctProcessedEventCount(),
            'processed_events has one row per distinct event_id (no double-processing)',
        );
    }

    // =========================================================================
    // Queue seeding (PostgreSQL) — direct inserts into system.queue_jobs so the
    // concurrency tests control the exact queue shape (criteria 1 & 2 are about the
    // CLAIM protocol, not the relay/dispatch front of the pipeline).
    // =========================================================================

    /** @return string[] seeded job ids (event_id === job's own uuid for simplicity) */
    private function seedAvailableJobs(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->seedAvailableJob(availableAtOffsetSeconds: -($count - $i));
        }
        return $ids;
    }

    /** Insert one 'available' content job whose available_at is now + offset seconds. */
    private function seedAvailableJob(int $availableAtOffsetSeconds): string
    {
        $jobId   = $this->uuidv7();
        $eventId = $this->uuidv7();
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.queue_jobs
                 (id, event_id, queue_name, status, attempts, available_at)
             VALUES (\$1::uuid, \$2::uuid, 'content', 'available', 0,
                     NOW() + (\$3 * INTERVAL '1 second'))",
            [$jobId, $eventId, (string) $availableAtOffsetSeconds],
        );
        return $jobId;
    }

    /**
     * The exact claim SELECT from DatabaseQueueProvider::claim() (OPEN-4). Used by the
     * hand-interleaved concurrency proof so we exercise the SAME query the provider runs,
     * with the transaction boundary under the test's control.
     */
    private function claimSelectSql(): string
    {
        return "SELECT id, event_id, queue_name, status, attempts,
                       available_at, started_at, completed_at, last_error,
                       worker_id, visibility_timeout_at
                FROM   system.queue_jobs
                WHERE  queue_name = 'content'
                  AND  status     = 'available'
                  AND  available_at <= NOW()
                ORDER BY available_at ASC
                LIMIT  1
                FOR UPDATE SKIP LOCKED";
    }

    // =========================================================================
    // Replay assembly (criterion 3) — identical wiring to GATE-S1 / OPS-S2:
    // real emit path + real relay/dispatch/worker/adapters; only the WP reload
    // boundary ($this->wp) is substituted.
    // =========================================================================

    private function replayStrategy(): ReplayWorkerStrategy
    {
        $wpdb    = new FakeWpdb($this->mysqli, $this->prefix);
        $counter = new AggregateVersionCounter($wpdb);
        $writer  = new OutboxWriter($wpdb, $counter);
        $emitter = new ContentReplayEmitter(
            new EventProvider($writer),
            new ReplayReadingLoader($this->wp),
        );

        return new ReplayWorkerStrategy(new ReplayService($this->db, [$emitter]));
    }

    private function relayTick(): void
    {
        (new RelayWorkerStrategy(
            new MysqliOutboxConnection($this->mysqli),
            new PgsqlOutboxConnection($this->pgConn),
            $this->prefix,
            100,
        ))->tick();
    }

    /** Relay → dispatch → drain, worker reloading state from $this->wp (replay tests). */
    private function drainPipeline(): void
    {
        $this->relayTick();

        $queue = new DatabaseQueueProvider($this->db);
        (new EventDispatcher($this->db, $queue, 100))->dispatchBatch();

        $strategy = new EventWorkerStrategy($queue, $this->makeReplayWiredEventRegistry(), $this->db, retryLimit: 10);
        $guard = 0;
        while ($strategy->execute($this->ctx('01900000-0000-7000-8000-00000000fee1'))) {
            if (++$guard > 200) {
                self::fail('drainPipeline did not drain the queue');
            }
        }
    }

    /** 9-handler wiring; loaders read current state from $this->wp (FakeWpStore). */
    private function makeReplayWiredEventRegistry(): EventRegistry
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
    // Outbox (MySQL) seeding — mirrors GATE-S1.
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
    // PostgreSQL reads
    // =========================================================================

    private function countJobsWithStatus(string $status): int
    {
        $r = pg_query_params($this->pgConn, 'SELECT COUNT(*) AS c FROM system.queue_jobs WHERE status = $1', [$status]);
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    private function jobStatusById(string $jobId): ?string
    {
        $r = pg_query_params($this->pgConn, 'SELECT status FROM system.queue_jobs WHERE id = $1::uuid', [$jobId]);
        return (pg_fetch_assoc($r) ?: [])['status'] ?? null;
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

    /** Count event_ids that appear more than once in processed_events (must be 0 — PK-enforced). */
    private function duplicateProcessedEventCount(): int
    {
        $r = pg_query(
            $this->pgConn,
            'SELECT COUNT(*) AS c FROM (
                 SELECT event_id FROM system.processed_events GROUP BY event_id HAVING COUNT(*) > 1
             ) d',
        );
        return (int) (pg_fetch_assoc($r)['c'] ?? 0);
    }

    private function distinctProcessedEventCount(): int
    {
        $r = pg_query($this->pgConn, 'SELECT COUNT(DISTINCT event_id) AS c FROM system.processed_events');
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
    // Schema (mirrors GATE-S1 exactly — same frozen DDL shapes).
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
            CREATE TABLE content.pages (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT 'page',
                slug               VARCHAR(255) NOT NULL,
                title              TEXT         NOT NULL,
                content            TEXT         NOT NULL,
                status             VARCHAR(50)  NOT NULL,
                parent_id          BIGINT       NOT NULL DEFAULT 0,
                menu_order         INTEGER      NOT NULL DEFAULT 0,
                published_at       TIMESTAMPTZ  NOT NULL,
                updated_at         TIMESTAMPTZ  NOT NULL,
                deleted_at         TIMESTAMPTZ  NULL,
                checksum           VARCHAR(64)  NOT NULL,
                meta_jsonb         JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_pages PRIMARY KEY (id),
                CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.posts (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT 'post',
                slug               VARCHAR(255) NOT NULL,
                title              TEXT         NOT NULL,
                content            TEXT         NOT NULL,
                excerpt            TEXT         NOT NULL,
                status             VARCHAR(50)  NOT NULL,
                author             VARCHAR(255) NOT NULL,
                published_at       TIMESTAMPTZ  NOT NULL,
                updated_at         TIMESTAMPTZ  NOT NULL,
                deleted_at         TIMESTAMPTZ  NULL,
                checksum           VARCHAR(64)  NOT NULL,
                meta_jsonb         JSONB        NOT NULL DEFAULT '{}'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_posts PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.taxonomies (
                id              UUID         NOT NULL,
                source_term_id  BIGINT       NOT NULL,
                taxonomy_type   VARCHAR(50)  NOT NULL,
                slug            VARCHAR(255) NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT         NOT NULL DEFAULT '',
                parent_id       BIGINT       NOT NULL DEFAULT 0,
                post_count      INTEGER      NOT NULL DEFAULT 0,
                deleted_at      TIMESTAMPTZ  NULL,
                checksum        VARCHAR(64)  NOT NULL,
                created_at      TIMESTAMPTZ  NOT NULL,
                updated_at      TIMESTAMPTZ  NOT NULL,
                synced_at       TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_taxonomies PRIMARY KEY (id),
                CONSTRAINT uq_content_taxonomies_source_term_id UNIQUE (source_term_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.entity_taxonomies (
                entity_id   UUID NOT NULL,
                taxonomy_id UUID NOT NULL,
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
        $conn = $this->newPgSession();
        if ($conn === null) {
            $this->markTestSkipped('PostgreSQL env vars not set or connect failed.');
        }
        return $conn;
    }

    /**
     * Open an ADDITIONAL physical PostgreSQL session (distinct backend PID) for the
     * concurrency proofs. FORCE_NEW guarantees it is a separate link, not a reuse of the
     * setUp() handle — two of these holding row locks at once is genuine concurrency.
     * Registered for deterministic teardown.
     */
    private function openExtraPgSession(): mixed
    {
        $conn = $this->newPgSession();
        if ($conn === null) {
            self::fail('could not open an additional PostgreSQL session for the concurrency proof');
        }
        $this->extraPgConns[] = $conn;
        return $conn;
    }

    private function newPgSession(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_PGSQL_PORT') ?: 5432);
        $user = getenv('HSP_TEST_PGSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            return null;
        }

        $conn = @pg_connect(
            "host={$host} port={$port} dbname={$db} user={$user} password={$pass}",
            PGSQL_CONNECT_FORCE_NEW,
        );
        return $conn === false ? null : $conn;
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
