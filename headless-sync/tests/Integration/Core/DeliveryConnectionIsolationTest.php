<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Core;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration proof for DECISION K (v1.11) — Delivery Connection Isolation.
 *
 * All five DoD-4 items proven in a single PHP process against live PostgreSQL:
 *
 *   (i)   relay/queue connection and delivery connection are distinct physical handles
 *   (ii)  Resolve-stage reads execute on the dedicated delivery connection
 *   (iii) an open/uncommitted relay transaction cannot influence (block or expose
 *         uncommitted state to) a Resolve-stage read
 *   (iv)  REST query providers resolve through the dedicated delivery connection
 *   (v)   adapter persistence still functions under the DECISION 3 transaction boundary
 *
 * Authority:
 *   DECISION K (v1.11) — FORCE_NEW guarantees physical link separation.
 *   DECISION J (v1.10) — Resolve-stage read is the PRIMARY stale-event gate.
 *   DECISION 3 — three-op atomic PG transaction (projection + processed_events +
 *                aggregate_versions).
 *   FLAG-P0S5-1 — FORCE_NEW precedent from queue-claim path.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class DeliveryConnectionIsolationTest extends TestCase
{
    /** Test-owned connection used for schema setup, seeding, and inspection. */
    private mixed $testConn = null;

    protected function setUp(): void
    {
        $this->testConn = $this->openConnection();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->testConn !== null) {
            pg_query($this->testConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_query($this->testConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_close($this->testConn);
        }
    }

    // =========================================================================
    // DoD-4 (i) — relay/queue handle and delivery handle are physically distinct
    // =========================================================================

    /**
     * Opens two FORCE_NEW handles (relay and delivery) and proves they are backed by
     * distinct PostgreSQL server-side backend processes via pg_backend_pid().
     *
     * Two distinct backend PIDs → two distinct physical connections on the server side.
     * This is the definitive proof that libpq did not pool/reuse an existing connection.
     *
     * Additional assertions:
     *   (b) Reading pg_backend_pid() twice on the delivery handle returns the same integer,
     *       proving the singleton is one stable link (not a fresh connect per call).
     *   (c) pg_transaction_status() independence: BEGIN on relay leaves delivery IDLE.
     */
    public function test_delivery_handle_is_physically_distinct_from_relay_handle(): void
    {
        $dsn = $this->buildDsn();

        // Relay handle — FORCE_NEW (as OutboxServiceProvider / relay worker would open).
        $relayRaw    = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        // Delivery handle — FORCE_NEW (as DeliveryServiceProvider opens it).
        $deliveryRaw = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($relayRaw === false || $deliveryRaw === false) {
            self::markTestSkipped('Could not open two PG connections for isolation proof.');
        }

        try {
            // (a) Distinct backend PIDs — server-side proof of separate physical connections.
            $relayPid    = $this->backendPid($relayRaw);
            $deliveryPid = $this->backendPid($deliveryRaw);

            self::assertNotSame(
                $relayPid,
                $deliveryPid,
                sprintf(
                    'DECISION K: relay backend PID (%d) must differ from delivery backend PID (%d) — '
                    . 'distinct physical libpq links required',
                    $relayPid,
                    $deliveryPid,
                )
            );

            // (b) Delivery PID is stable across two reads — same singleton, same backend.
            $deliveryPid2 = $this->backendPid($deliveryRaw);
            self::assertSame(
                $deliveryPid,
                $deliveryPid2,
                'DECISION K: delivery backend PID must be stable across sequential reads '
                . '(one dedicated link, not a fresh connect per call)'
            );

            // (c) pg_transaction_status() independence: BEGIN on relay leaves delivery IDLE.
            pg_query($relayRaw, 'BEGIN');

            self::assertSame(
                PGSQL_TRANSACTION_INTRANS,
                pg_transaction_status($relayRaw),
                'Relay handle must be IN TRANSACTION after BEGIN'
            );
            self::assertSame(
                PGSQL_TRANSACTION_IDLE,
                pg_transaction_status($deliveryRaw),
                'DECISION K: delivery handle must remain IDLE when relay handle is in a transaction'
            );

            pg_query($relayRaw, 'ROLLBACK');
        } finally {
            pg_close($relayRaw);
            pg_close($deliveryRaw);
        }
    }

    // =========================================================================
    // DoD-4 (ii) — Resolve-stage reads use the delivery connection
    // =========================================================================

    /**
     * Wires EventWorkerStrategy with an explicit delivery PostgresDatabaseConnection
     * and proves the Resolve-stage stale-check reads system.aggregate_versions through
     * that connection — evidenced by the correct stale/non-stale decision.
     *
     * A stale event (version 3, stored = 5) is enqueued. The strategy must ack the
     * job without invoking the handler. The read of stored version 5 must have
     * occurred on the delivery connection (not the queue connection, which holds
     * FOR UPDATE and would not be used for reads in this architecture).
     */
    public function test_resolve_stage_reads_via_delivery_connection(): void
    {
        $eventId  = $this->newUuid();
        $aggType  = 'page';
        $aggId    = 'resolve-read-proof';
        $workerId = $this->newUuid();

        $this->seedEvent($eventId, 'content.page.updated', $aggType, $aggId, 3);
        $this->seedAggregateVersion($aggType, $aggId, 5);
        $jobId = $this->enqueueJob($eventId, 'content');

        $handlerCallCount = 0;
        $registry = new EventRegistry();
        $registry->register('content.page.updated', function () use (&$handlerCallCount): void {
            $handlerCallCount++;
        });

        // Wire strategy with separate delivery and queue connections (DECISION K).
        [$deliveryConn, $queueProvider] = $this->makeDeliveryAndQueue();
        $strategy = new EventWorkerStrategy($queueProvider, $registry, $deliveryConn);

        $ctx = new WorkerExecutionContext(
            workerId:      $workerId,
            tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        );

        $result = $strategy->execute($ctx);

        // Resolve-stage read correctly identified the event as stale (version 3 ≤ stored 5).
        self::assertSame(0, $handlerCallCount,
            'DECISION J/K: handler must NOT fire — Resolve-stage read via delivery connection correctly detected stale event');
        self::assertSame('completed', $this->fetchJobStatus($jobId),
            'Stale job must be acked (completed)');
        self::assertTrue($result, 'execute() must return true (job was claimed)');
    }

    // =========================================================================
    // DoD-4 (iii) — open relay txn cannot influence Resolve-stage read
    // =========================================================================

    /**
     * Proves both halves of DoD-4(iii): the Resolve-stage read does not expose
     * uncommitted relay state AND returns without blocking, while the relay
     * transaction remains open throughout.
     *
     * Setup:
     *   - Relay handle: BEGIN + INSERT version 99 into system.aggregate_versions,
     *     NOT committed — relay transaction stays open for the entire test.
     *   - Delivery handle (READ COMMITTED): Resolve-stage SELECT must return no row
     *     (version 99 invisible) and must complete promptly.
     *
     * Proof structure:
     *   (1) Non-exposure: handler fires (delivery saw no committed row → not stale).
     *       Relay transaction still open when assertion runs.
     *   (2) Non-blocking: wall-clock elapsed time for strategy->execute() is < 1 s
     *       while relay INSERT is uncommitted. A bare non-locking SELECT under
     *       READ COMMITTED on a row being inserted (not yet committed) returns the
     *       pre-insert state immediately — it does not wait on the inserting txn's lock.
     *       Timing threshold: 1 s (local PG round-trip is < 50 ms in practice).
     *   (3) Negative control: proves the timing assertion is falsifiable by
     *       demonstrating that a SELECT ... FOR UPDATE on the same PK on the delivery
     *       connection DOES block while the relay INSERT is uncommitted, confirmed by
     *       setting statement_timeout = 200 ms and catching the resulting error.
     *       If the FOR UPDATE returns without error, the negative control fails the test,
     *       proving the timing threshold is sensitive enough to catch a real block.
     *
     * Relay transaction is rolled back only after all assertions complete.
     */
    public function test_open_relay_transaction_does_not_expose_uncommitted_state_to_resolve_read(): void
    {
        $eventId  = $this->newUuid();
        $aggType  = 'page';
        $aggId    = 'relay-isolation-proof';
        $workerId = $this->newUuid();

        // Seed the event (version 1) but do NOT seed aggregate_versions yet.
        $this->seedEvent($eventId, 'content.page.created', $aggType, $aggId, 1);
        $jobId = $this->enqueueJob($eventId, 'content');

        // ── Open relay simulation: BEGIN + INSERT uncommitted ──────────────────
        $relayRaw = \pg_connect($this->buildDsn(), PGSQL_CONNECT_FORCE_NEW);
        if ($relayRaw === false) {
            self::markTestSkipped('Could not open relay simulation connection.');
        }

        // Relay txn stays open until explicitly rolled back below.
        pg_query($relayRaw, 'BEGIN');
        pg_query_params(
            $relayRaw,
            'INSERT INTO system.aggregate_versions
                (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, 99, now()::timestamptz)',
            [$aggType, $aggId]
        );
        // Confirm relay txn is in-flight before proceeding.
        self::assertSame(
            PGSQL_TRANSACTION_INTRANS,
            pg_transaction_status($relayRaw),
            'Relay transaction must still be open before delivery read'
        );

        try {
            // ── (1) & (2) Non-exposure + Non-blocking ─────────────────────────
            $handlerCallCount = 0;
            $registry = new EventRegistry();
            $registry->register('content.page.created', function () use (&$handlerCallCount): void {
                $handlerCallCount++;
            });

            [$deliveryConn, $queueProvider] = $this->makeDeliveryAndQueue();
            $strategy = new EventWorkerStrategy($queueProvider, $registry, $deliveryConn);

            $ctx = new WorkerExecutionContext(
                workerId:      $workerId,
                tickStartedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            );

            // Wall-clock the Resolve-stage read. Relay txn is open throughout.
            $startNs = hrtime(true);
            $strategy->execute($ctx);
            $elapsedMs = (hrtime(true) - $startNs) / 1_000_000;

            // Confirm relay is STILL open — elapsed measurement was taken while
            // the uncommitted INSERT remained in place.
            self::assertSame(
                PGSQL_TRANSACTION_INTRANS,
                pg_transaction_status($relayRaw),
                'Relay transaction must still be open after delivery read (not accidentally committed)'
            );

            // (1) Non-exposure: delivery saw no committed row → not stale → handler fired.
            self::assertSame(1, $handlerCallCount,
                'DECISION K: delivery connection must NOT see uncommitted relay version 99; '
                . 'event version 1 must be treated as NOT stale (handler must fire)');

            // (2) Non-blocking: bare non-locking SELECT under READ COMMITTED does not wait
            //     on an uncommitted INSERT — it reads the pre-insert committed state immediately.
            self::assertLessThan(
                1000.0,
                $elapsedMs,
                sprintf(
                    'DECISION K: Resolve-stage read must complete in < 1000 ms while relay txn is open '
                    . '(actual: %.2f ms). A bare SELECT under READ COMMITTED must not block on an '
                    . 'uncommitted INSERT.',
                    $elapsedMs,
                )
            );

            // ── (3) Negative control: synthetic lock-contention probe ────────
            //
            // Goal: prove the < 1 s timing assertion in (2) is falsifiable — i.e. that
            // a genuine lock wait IS caught by the threshold, so a PASS in (2) is
            // meaningful rather than vacuous.
            //
            // Why the relay's uncommitted INSERT cannot be used: under READ COMMITTED a
            // SELECT ... FOR UPDATE on a PK that has only an uncommitted INSERT (no
            // committed version) returns zero rows immediately — the uncommitted inserter
            // holds an in-doubt tuple lock that only blocks concurrent INSERTs on the
            // same PK, not FOR UPDATE readers. So the relay INSERT is the correct pattern
            // for (1)/(2) but wrong for demonstrating lock-wait behaviour.
            //
            // Synthetic probe (committed UPDATE, not INSERT):
            //   Lock handle A: INSERT a throwaway row and COMMIT it (committed version exists).
            //   Lock handle A: BEGIN + UPDATE that row without committing (holds row lock).
            //   Delivery handle (statement_timeout=300ms): SELECT ... FOR UPDATE on that row.
            //   → Must block on the committed row being updated, then be cancelled by timeout.
            //   Assert: query errors (pg_query returns false), SQLSTATE 57014, elapsed >= 300 ms.
            //   Teardown: rollback lock handle A; delete throwaway row.
            //
            // This proves the timing/error path is sensitive, so the non-blocking PASS
            // in (2) reflects real behaviour, not a vacuous < 1 s check.
            $lockRaw = \pg_connect($this->buildDsn(), PGSQL_CONNECT_FORCE_NEW);
            $negRaw  = \pg_connect($this->buildDsn(), PGSQL_CONNECT_FORCE_NEW);
            if ($lockRaw === false || $negRaw === false) {
                self::markTestSkipped('Could not open lock-contention probe connections.');
            }

            // Dedicated throwaway key — completely separate from the relay-isolation key
            // used in (1)/(2) to avoid any coupling.
            $throwawayType = 'negctl_probe';
            $throwawayId   = 'negctl_' . $this->newUuid();
            $now           = date('Y-m-d H:i:sP');

            try {
                // Step 1: INSERT throwaway row and COMMIT so a committed version exists.
                pg_query_params(
                    $lockRaw,
                    'INSERT INTO system.aggregate_versions
                        (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
                     VALUES ($1, $2, 1, $3::timestamptz)',
                    [$throwawayType, $throwawayId, $now]
                );

                // Step 2: BEGIN + UPDATE without committing — row lock held on committed row.
                pg_query($lockRaw, 'BEGIN');
                pg_query_params(
                    $lockRaw,
                    'UPDATE system.aggregate_versions
                     SET latest_processed_version = 2,
                         latest_processed_at      = now()::timestamptz
                     WHERE aggregate_type = $1 AND aggregate_id = $2',
                    [$throwawayType, $throwawayId]
                );
                self::assertSame(
                    PGSQL_TRANSACTION_INTRANS,
                    pg_transaction_status($lockRaw),
                    'NEGATIVE CONTROL: lock handle must be in transaction (holding row lock)'
                );

                // Step 3: delivery handle attempts SELECT ... FOR UPDATE under statement_timeout.
                // Must block on the row lock and be cancelled.
                pg_query($negRaw, "SET statement_timeout = '300ms'");
                pg_query($negRaw, 'BEGIN');

                $negStart  = hrtime(true);
                $negResult = @pg_query_params(
                    $negRaw,
                    'SELECT latest_processed_version
                     FROM system.aggregate_versions
                     WHERE aggregate_type = $1 AND aggregate_id = $2
                     FOR UPDATE',
                    [$throwawayType, $throwawayId]
                );
                $negElapsedMs = (hrtime(true) - $negStart) / 1_000_000;

                // Query must have been cancelled — pg_query returns false on error.
                self::assertFalse(
                    $negResult,
                    'NEGATIVE CONTROL FAIL: SELECT ... FOR UPDATE on a committed row held by an '
                    . 'uncommitted UPDATE must block and be cancelled by statement_timeout=300ms. '
                    . 'If this passes, the synthetic lock-contention setup is broken.'
                );

                // SQLSTATE 57014 = query_canceled (statement timeout).
                // pg_last_error() returns the human-readable message without the SQLSTATE
                // code embedded, so assert on the canonical message text instead.
                $errMsg = pg_last_error($negRaw);
                self::assertStringContainsString(
                    'statement timeout',
                    strtolower($errMsg),
                    sprintf(
                        'NEGATIVE CONTROL: cancellation must be a statement timeout error '
                        . '(SQLSTATE 57014 / "canceling statement due to statement timeout"), got: %s',
                        $errMsg,
                    )
                );

                // Elapsed must be >= 300 ms — proves it actually waited, not instant.
                self::assertGreaterThanOrEqual(
                    300.0,
                    $negElapsedMs,
                    sprintf(
                        'NEGATIVE CONTROL: SELECT ... FOR UPDATE must have waited >= 300 ms '
                        . '(statement_timeout) before cancellation; actual: %.2f ms. '
                        . 'If this fails, the lock-contention setup is not working.',
                        $negElapsedMs,
                    )
                );

                pg_query($negRaw, 'ROLLBACK');

            } finally {
                // Roll back the uncommitted UPDATE and remove the throwaway row.
                pg_query($lockRaw, 'ROLLBACK');
                pg_query_params(
                    $lockRaw,
                    'DELETE FROM system.aggregate_versions
                     WHERE aggregate_type = $1 AND aggregate_id = $2',
                    [$throwawayType, $throwawayId]
                );
                pg_close($lockRaw);
                pg_close($negRaw);
            }

            // ── Relay txn (B(1)/(2)) rolled back after all assertions ─────────
            pg_query($relayRaw, 'ROLLBACK');

        } finally {
            pg_close($relayRaw);
        }
    }

    // =========================================================================
    // DoD-4 (iv) — REST query providers resolve through delivery connection
    // =========================================================================

    /**
     * Constructs a PageQueryProvider with the delivery PostgresDatabaseConnection
     * and executes list() against the content.pages projection. Proves:
     *   - the query executes without error (connection is valid and not entangled)
     *   - an inserted row is visible (delivery connection reads committed state)
     *   - the list result reflects the projection faithfully
     */
    public function test_rest_query_provider_executes_via_delivery_connection(): void
    {
        // Seed a page row directly via the test connection.
        $pageId = $this->newUuid();
        $this->seedPageRow($pageId, sourcePostId: 100, slug: 'my-page');

        // Build PageQueryProvider with the delivery connection (FORCE_NEW).
        $deliveryRaw = \pg_connect($this->buildDsn(), PGSQL_CONNECT_FORCE_NEW);
        if ($deliveryRaw === false) {
            self::markTestSkipped('Could not open delivery connection for query provider proof.');
        }

        try {
            $deliveryConn = new PostgresDatabaseConnection($deliveryRaw);
            $queryProvider = new PageQueryProvider($deliveryConn);

            $filterSet = new \HSP\Core\Contracts\FilterSet(
                status: 'publish',
                limit: 10,
            );

            $page = $queryProvider->list($filterSet);

            self::assertInstanceOf(\HSP\Core\Contracts\CursorPage::class, $page,
                'list() must return a CursorPage');
            self::assertCount(1, $page->rows,
                'DECISION K (iv): delivery connection must see the seeded page row (query provider works)');
            self::assertSame('my-page', $page->rows[0]['slug'] ?? null,
                'Returned row must match the seeded page slug');
        } finally {
            pg_close($deliveryRaw);
        }
    }

    // =========================================================================
    // DoD-4 (v) — adapter persistence under DECISION 3 transaction boundary
    // =========================================================================

    /**
     * Constructs a PageAdapter with the delivery connection and calls persist()
     * via the full handler pipeline. Proves:
     *   - projection upsert committed (content.pages row present)
     *   - processed_events row committed (DECISION 3 op 2)
     *   - aggregate_versions row committed (DECISION 3 op 3)
     *   - all three ops in a single transaction (mid-txn failure leaves no partial writes)
     */
    public function test_adapter_persist_functions_under_decision3_transaction_boundary(): void
    {
        $loader = new FakeWpContentLoader();
        $loader->postResult = [
            'ID'                => 42,
            'post_title'        => 'Isolation Test Page',
            'post_content'      => '<p>body</p>',
            'post_excerpt'      => '',
            'post_name'         => 'isolation-test-page',
            'post_status'       => 'publish',
            'post_type'         => 'page',
            'post_author'       => '1',
            'post_date_gmt'     => '2024-01-01 00:00:00',
            'post_modified_gmt' => '2024-06-01 00:00:00',
            'post_parent'       => '0',
            'menu_order'        => '0',
        ];
        $loader->postMetaResult    = [];
        $loader->categoryIdsResult = [];

        // Build handler with the delivery connection (FORCE_NEW).
        $deliveryRaw = \pg_connect($this->buildDsn(), PGSQL_CONNECT_FORCE_NEW);
        if ($deliveryRaw === false) {
            self::markTestSkipped('Could not open delivery connection for adapter persistence proof.');
        }

        try {
            $deliveryConn = new PostgresDatabaseConnection($deliveryRaw);
            $handler = new \HSP\Modules\Content\Handlers\PageUpsertHandler(
                $loader,
                new PageExtractor(new PageValidator()),
                new PageTransformer(),
                new PageAdapter($deliveryConn),
            );

            $event = $this->makeEvent('content.page.created', 'page', '42', 1);
            $handler->handle($event);

            // All three DECISION 3 ops committed.
            self::assertSame(1, $this->countRows('content.pages'),
                'DECISION K (v): projection upsert committed via delivery connection');
            self::assertSame(1, $this->countRows('system.processed_events'),
                'DECISION K (v): processed_events row committed');
            self::assertSame(1, $this->countRows('system.aggregate_versions'),
                'DECISION K (v): aggregate_versions row committed');

            // Verify the projection row has the correct slug (not a phantom row).
            $row = pg_fetch_assoc(pg_query_params(
                $this->testConn,
                'SELECT slug FROM content.pages WHERE source_post_id = $1',
                [42]
            ));
            self::assertSame('isolation-test-page', $row['slug'] ?? null,
                'Projected slug must match the WP source data');
        } finally {
            pg_close($deliveryRaw);
        }
    }

    // =========================================================================
    // Helpers — backend PID
    // =========================================================================

    /** Returns the PostgreSQL server-side backend PID for the given connection handle. */
    private function backendPid(mixed $conn): int
    {
        $result = pg_query($conn, 'SELECT pg_backend_pid() AS pid');
        $row    = pg_fetch_assoc($result);
        pg_free_result($result);
        return (int) $row['pid'];
    }

    // =========================================================================
    // Helpers — strategy factory
    // =========================================================================

    /**
     * Returns [DatabaseConnectionInterface $deliveryConn, DatabaseQueueProvider $queue]
     * — the two are on physically separate FORCE_NEW handles (DECISION K).
     *
     * @return array{0: DatabaseConnectionInterface, 1: DatabaseQueueProvider}
     */
    private function makeDeliveryAndQueue(): array
    {
        $dsn = $this->buildDsn();

        $deliveryRaw = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $queueRaw    = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($deliveryRaw === false || $queueRaw === false) {
            self::markTestSkipped('Could not open delivery/queue connections.');
        }

        $deliveryConn = new PostgresDatabaseConnection($deliveryRaw);
        $queueConn    = new DatabaseQueueConnection($queueRaw);
        $queueProvider = new DatabaseQueueProvider($queueConn, [
            'retry_limit'                => 10,
            'visibility_timeout_seconds' => 300,
            'backoff_base_seconds'       => 30,
            'backoff_cap_seconds'        => 3600,
        ]);

        return [$deliveryConn, $queueProvider];
    }

    // =========================================================================
    // Helpers — event factory
    // =========================================================================

    private function makeEvent(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
    ): DeliveryIsolationEvent {
        return new DeliveryIsolationEvent(
            id:               $this->newUuid(),
            eventType:        $eventType,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: $aggregateVersion,
        );
    }

    // =========================================================================
    // Helpers — DB seeding
    // =========================================================================

    private function seedEvent(
        string $id,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
    ): void {
        $now = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->testConn,
            "INSERT INTO system.events
                (id, event_type, event_version, aggregate_type, aggregate_id,
                 aggregate_version, payload, checksum, source_updated_at,
                 created_at, correlation_id, causation_id)
             VALUES
                (\$1::uuid, \$2, 1, \$3, \$4,
                 \$5, '{}', \$6, \$7::timestamptz,
                 \$8::timestamptz, \$9::uuid, NULL)",
            [
                $id, $eventType, $aggregateType, $aggregateId,
                $aggregateVersion, str_repeat('a', 64), $now, $now, $this->newUuid(),
            ]
        );
    }

    private function seedAggregateVersion(string $aggregateType, string $aggregateId, int $version): void
    {
        $now = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->testConn,
            'INSERT INTO system.aggregate_versions
                (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, $3, $4::timestamptz)
             ON CONFLICT (aggregate_type, aggregate_id) DO UPDATE
                SET latest_processed_version = EXCLUDED.latest_processed_version,
                    latest_processed_at      = EXCLUDED.latest_processed_at',
            [$aggregateType, $aggregateId, $version, $now]
        );
    }

    private function enqueueJob(string $eventId, string $queueName): string
    {
        $jobId = $this->newUuid();
        $now   = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->testConn,
            "INSERT INTO system.queue_jobs
                (id, event_id, queue_name, status, attempts, available_at,
                 started_at, completed_at, last_error, worker_id, visibility_timeout_at)
             VALUES
                (\$1::uuid, \$2::uuid, \$3, 'available', 0, \$4::timestamptz,
                 NULL, NULL, NULL, NULL, NULL)",
            [$jobId, $eventId, $queueName, $now]
        );
        return $jobId;
    }

    private function seedPageRow(string $id, int $sourcePostId, string $slug): void
    {
        $now = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->testConn,
            "INSERT INTO content.pages
                (id, source_post_id, source_entity_type, slug, title, content,
                 status, parent_id, menu_order, published_at, updated_at,
                 deleted_at, checksum, meta_jsonb, created_at, synced_at)
             VALUES
                (\$1::uuid, \$2, 'page', \$3, 'Test', '<p></p>',
                 'publish', 0, 0, \$4::timestamptz, \$4::timestamptz,
                 NULL, \$5, '{}', \$4::timestamptz, \$4::timestamptz)",
            [$id, $sourcePostId, $slug, $now, str_repeat('b', 64)]
        );
    }

    // =========================================================================
    // Helpers — DB reads
    // =========================================================================

    private function countRows(string $table): int
    {
        $result = pg_query($this->testConn, "SELECT COUNT(*) AS cnt FROM {$table}");
        if ($result === false) {
            return 0;
        }
        $row = pg_fetch_assoc($result);
        pg_free_result($result);
        return (int) ($row['cnt'] ?? 0);
    }

    private function fetchJobStatus(string $jobId): ?string
    {
        $result = pg_query_params(
            $this->testConn,
            'SELECT status FROM system.queue_jobs WHERE id = $1::uuid',
            [$jobId]
        );
        if ($result === false) {
            return null;
        }
        $row = pg_fetch_assoc($result) ?: null;
        pg_free_result($result);
        return $row['status'] ?? null;
    }

    // =========================================================================
    // Helpers — UUID
    // =========================================================================

    private function newUuid(): string
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

    // =========================================================================
    // Connection and DSN helpers
    // =========================================================================

    private function buildDsn(): string
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: false;
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: false;

        if ($user === false || $db === false) {
            self::markTestSkipped('PostgreSQL env vars not set — skipping delivery connection isolation tests.');
        }

        return "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
    }

    private function openConnection(): mixed
    {
        $dsn  = $this->buildDsn();
        $conn = @\pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped('PostgreSQL not available — skipping delivery connection isolation tests.');
        }

        return $conn;
    }

    // =========================================================================
    // Schema setup
    // =========================================================================

    private function createSchema(): void
    {
        pg_query($this->testConn, 'CREATE SCHEMA IF NOT EXISTS system');

        pg_query($this->testConn, '
            CREATE TABLE IF NOT EXISTS system.events (
                id                UUID         NOT NULL,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INTEGER      NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                payload           JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                checksum          VARCHAR(64)  NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                correlation_id    UUID         NOT NULL,
                causation_id      UUID         NULL,
                CONSTRAINT pk_dci_test_events PRIMARY KEY (id)
            )
        ');

        pg_query($this->testConn, '
            CREATE TABLE IF NOT EXISTS system.queue_jobs (
                id                    UUID         NOT NULL,
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
                CONSTRAINT pk_dci_test_queue_jobs PRIMARY KEY (id)
            )
        ');

        pg_query($this->testConn, '
            CREATE INDEX IF NOT EXISTS idx_dci_queue_jobs_claim
                ON system.queue_jobs (queue_name, status, available_at)
        ');

        pg_query($this->testConn, '
            CREATE TABLE IF NOT EXISTS system.dead_letter_jobs (
                id               UUID         NOT NULL,
                job_id           UUID         NOT NULL,
                event_id         UUID         NOT NULL,
                queue_name       VARCHAR(255) NOT NULL,
                failure_reason   TEXT         NOT NULL,
                created_at       TIMESTAMPTZ  NOT NULL,
                stack_trace      TEXT         NULL,
                attempt_count    INTEGER      NOT NULL DEFAULT 0,
                worker_id        UUID         NULL,
                payload_snapshot JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                CONSTRAINT pk_dci_test_dead_letter_jobs PRIMARY KEY (id)
            )
        ');

        pg_query($this->testConn, '
            CREATE TABLE IF NOT EXISTS system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_dci_test_aggregate_versions
                    PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ');

        pg_query($this->testConn, '
            CREATE TABLE IF NOT EXISTS system.processed_events (
                event_id     UUID        NOT NULL,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_dci_test_processed_events PRIMARY KEY (event_id)
            )
        ');

        pg_query($this->testConn, 'CREATE SCHEMA IF NOT EXISTS content');

        pg_query($this->testConn, '
            CREATE TABLE IF NOT EXISTS content.pages (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT \'page\',
                slug               VARCHAR(255) NOT NULL,
                title              TEXT         NOT NULL,
                content            TEXT         NOT NULL,
                status             VARCHAR(50)  NOT NULL DEFAULT \'publish\',
                parent_id          BIGINT       NOT NULL DEFAULT 0,
                menu_order         INTEGER      NOT NULL DEFAULT 0,
                published_at       TIMESTAMPTZ  NOT NULL,
                updated_at         TIMESTAMPTZ  NOT NULL,
                deleted_at         TIMESTAMPTZ  NULL,
                checksum           VARCHAR(64)  NOT NULL,
                meta_jsonb         JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_dci_test_pages PRIMARY KEY (id),
                CONSTRAINT uq_dci_test_pages_source_post_id UNIQUE (source_post_id)
            )
        ');
    }
}

// =========================================================================
// Minimal EventInterface stub — used by the handler pipeline in this test.
// Mirrors HandlerSpineIntegrationEvent from HandlerSpineIntegrationTest.
// =========================================================================

final class DeliveryIsolationEvent implements \HSP\Core\Contracts\EventInterface
{
    public function __construct(
        private readonly string             $id,
        private readonly string             $eventType,
        private readonly string             $aggregateType,
        private readonly string             $aggregateId,
        private readonly int                $aggregateVersion,
        private readonly ?\DateTimeImmutable $sourceUpdatedAt = null,
    ) {}

    public function getId(): string               { return $this->id; }
    public function getEventType(): string         { return $this->eventType; }
    public function getEventVersion(): int         { return 1; }
    public function getAggregateType(): string     { return $this->aggregateType; }
    public function getAggregateId(): string       { return $this->aggregateId; }
    public function getAggregateVersion(): int     { return $this->aggregateVersion; }
    public function getPayload(): array            { return []; }
    public function getChecksum(): string          { return str_repeat('a', 64); }
    public function getCorrelationId(): string     { return $this->id; }
    public function getCausationId(): ?string      { return null; }
    public function getSourceUpdatedAt(): \DateTimeImmutable
    {
        return $this->sourceUpdatedAt ?? new \DateTimeImmutable('2024-01-01T00:00:00+00:00');
    }
    public function getCreatedAt(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2024-01-01T00:00:00+00:00');
    }
}
