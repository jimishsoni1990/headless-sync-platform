<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Adapters;

use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageAdapter.
 *
 * Query queue layout per persist() call:
 *   slot 0 — fetchExistingRow (pre-txn)
 *   slot 1 — lockAggregateVersion FOR UPDATE (inside txn)
 *
 * Execute layout per persist() call (non-suppressed):
 *   #1 lockAggregateVersion sentinel INSERT   (contains 'aggregate_versions')
 *   #2 upsertPage                             (contains 'content.pages')
 *   #3 insertProcessedEvent                   (contains 'processed_events')
 *   #4 upsertAggregateVersion GREATEST upsert (contains 'aggregate_versions')
 *
 * When projection is suppressed, #2 is absent; #1, #3, #4 still run.
 */
final class PageAdapterTest extends TestCase
{
    private FakeDbConnection $db;
    private PageAdapter      $adapter;
    private FakeAdapterEvent $event;

    protected function setUp(): void
    {
        $this->db      = new FakeDbConnection();
        $this->adapter = new PageAdapter($this->db);
        $this->event   = new FakeAdapterEvent(
            aggregateType:    'page',
            aggregateId:      '1',
            aggregateVersion: 2,
        );
    }

    // -------------------------------------------------------------------------
    // getCanonicalModelClass
    // -------------------------------------------------------------------------

    public function test_get_canonical_model_class_returns_canonical_page(): void
    {
        self::assertSame(CanonicalPage::class, $this->adapter->getCanonicalModelClass());
    }

    // -------------------------------------------------------------------------
    // persist() — happy path (new entity)
    // -------------------------------------------------------------------------

    public function test_persist_executes_three_ops_inside_a_transaction(): void
    {
        // slot 0: fetchExistingRow → no prior row; slot 1: FOR UPDATE → locked version 0
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);

        $this->adapter->persist($this->makePage(), $this->event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit',           $methods);
        self::assertNotContains('rollback',      $methods);

        self::assertSame(1, $this->db->countExecuteContaining('content.pages'),    'projection upsert');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'), 'processed_events insert');
        // aggregate_versions appears twice: sentinel INSERT + GREATEST upsert
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert');
    }

    public function test_persist_order_is_begin_then_ops_then_commit(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);

        $this->adapter->persist($this->makePage(), $this->event);

        $methods     = $this->db->loggedMethods();
        $beginIdx    = array_search('beginTransaction', $methods, true);
        $commitIdx   = array_search('commit', $methods, true);
        $executeIdxs = array_keys(array_filter($methods, fn($m) => $m === 'execute'));

        self::assertNotFalse($beginIdx);
        self::assertNotFalse($commitIdx);
        foreach ($executeIdxs as $idx) {
            self::assertGreaterThan($beginIdx, $idx, 'execute must come after BEGIN');
            self::assertLessThan($commitIdx,   $idx, 'execute must come before COMMIT');
        }
    }

    public function test_lock_execute_precedes_projection_upsert(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);

        $this->adapter->persist($this->makePage(), $this->event);

        $execLog = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'execute'
        ));

        // Sentinel INSERT is first execute; projection upsert is second.
        self::assertStringContainsString('aggregate_versions', $execLog[0]['sql'], 'sentinel INSERT first');
        self::assertStringContainsString('content.pages',      $execLog[1]['sql'], 'projection upsert second');
    }

    // -------------------------------------------------------------------------
    // Suppress path — MUST still record processed_events + aggregate_versions (Item 5)
    // -------------------------------------------------------------------------

    public function test_persist_suppress_still_records_events_and_advances_version(): void
    {
        $model = $this->makePage();

        // slot 0: matching checksum → checksum suppress; slot 1: FOR UPDATE → version 0 (no version conflict)
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-aaaaaaaaaaaa', 'checksum' => $model->getChecksum()]],
            [['latest_processed_version' => '0']],
        );

        $this->adapter->persist($model, $this->event);

        self::assertContains('beginTransaction', $this->db->loggedMethods(), 'transaction must open on suppress');
        self::assertContains('commit',           $this->db->loggedMethods(), 'transaction must commit on suppress');
        self::assertSame(0, $this->db->countExecuteContaining('content.pages'),  'no projection upsert on suppress');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'),    'processed_events recorded on suppress');
        // aggregate_versions: sentinel INSERT + GREATEST upsert (both run even on suppress)
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert on suppress');
    }

    // -------------------------------------------------------------------------
    // Version guard — atomic with projection write (Item 6 / concurrency fix)
    // -------------------------------------------------------------------------

    public function test_persist_version_guard_suppresses_projection_but_records_events(): void
    {
        $model = $this->makePage();

        // slot 0: different checksum (no checksum suppress); slot 1: FOR UPDATE locked version 5
        // Incoming event version = 2 < locked 5 → version guard fires
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-bbbbbbbbbbbb', 'checksum' => str_repeat('0', 64)]],
            [['latest_processed_version' => '5']],
        );

        $this->adapter->persist($model, $this->event); // event version 2 < locked 5

        self::assertSame(0, $this->db->countExecuteContaining('content.pages'), 'no projection upsert when version < locked');
        self::assertContains('beginTransaction', $this->db->loggedMethods());
        self::assertContains('commit',           $this->db->loggedMethods());
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'));
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert');
    }

    public function test_persist_version_guard_uses_locked_value_not_pre_txn_read(): void
    {
        // This test verifies the lock path is exercised (FOR UPDATE query is present in log).
        $this->db->queueQueryResults(
            [],                                        // slot 0: no prior row
            [['latest_processed_version' => '1']],    // slot 1: FOR UPDATE returns version 1
        );

        // Event version 2 >= locked 1 → must write.
        $this->adapter->persist($this->makePage(), $this->event);

        $lockQuery = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'query' && str_contains($e['sql'], 'FOR UPDATE')
        ));
        self::assertCount(1, $lockQuery, 'FOR UPDATE query must be present inside the transaction');
        self::assertSame(1, $this->db->countExecuteContaining('content.pages'), 'projection written when version >= locked');
    }

    public function test_persist_writes_projection_when_incoming_version_equals_locked(): void
    {
        // Locked version == incoming version (2 == 2) → NOT stale, should write.
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-cccccccccccc', 'checksum' => str_repeat('0', 64)]],
            [['latest_processed_version' => '2']],
        );

        $this->adapter->persist($this->makePage(), $this->event);

        self::assertSame(1, $this->db->countExecuteContaining('content.pages'), 'projection written when incoming == locked version');
    }

    public function test_persist_writes_when_checksum_differs_and_no_version_conflict(): void
    {
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-dddddddddddd', 'checksum' => str_repeat('0', 64)]],
            [['latest_processed_version' => '0']],
        );

        $this->adapter->persist($this->makePage(), $this->event);

        self::assertContains('beginTransaction', $this->db->loggedMethods());
        self::assertSame(1, $this->db->countExecuteContaining('content.pages'));
    }

    // -------------------------------------------------------------------------
    // Rollback on failure
    // -------------------------------------------------------------------------

    public function test_persist_rolls_back_and_rethrows_on_execute_failure(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);
        $this->db->failNextExecute(); // sentinel INSERT throws

        $this->expectException(DatabaseException::class);

        try {
            $this->adapter->persist($this->makePage(), $this->event);
        } finally {
            self::assertContains('rollback', $this->db->loggedMethods());
            self::assertNotContains('commit', $this->db->loggedMethods());
        }
    }

    // -------------------------------------------------------------------------
    // Wrong model type
    // -------------------------------------------------------------------------

    public function test_persist_throws_on_wrong_model_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $wrongModel = new CanonicalPost(
            postId: 1, title: 'T', content: 'C', excerpt: 'E',
            slug: 's', status: 'publish', author: 'admin',
            publishedAt: new \DateTimeImmutable(),
            modifiedAt:  new \DateTimeImmutable(),
            categoryIds: [], meta: [],
        );

        $this->adapter->persist($wrongModel, $this->event);
    }

    // -------------------------------------------------------------------------
    // aggregate_versions GREATEST guard
    // -------------------------------------------------------------------------

    public function test_aggregate_version_upsert_uses_greatest(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);
        $this->adapter->persist($this->makePage(), $this->event);

        // Filter for the GREATEST upsert specifically (not the sentinel INSERT).
        $greatestLog = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'execute'
                && str_contains($e['sql'], 'aggregate_versions')
                && str_contains($e['sql'], 'GREATEST')
        ));

        self::assertCount(1, $greatestLog, 'exactly one GREATEST upsert');
        self::assertStringContainsString('GREATEST', $greatestLog[0]['sql']);
    }

    public function test_sentinel_insert_uses_on_conflict_do_nothing(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);
        $this->adapter->persist($this->makePage(), $this->event);

        $sentinelLog = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'execute'
                && str_contains($e['sql'], 'aggregate_versions')
                && str_contains($e['sql'], 'ON CONFLICT')
                && ! str_contains($e['sql'], 'GREATEST')
        ));

        self::assertCount(1, $sentinelLog, 'sentinel INSERT with ON CONFLICT DO NOTHING');
        self::assertStringContainsString('DO NOTHING', $sentinelLog[0]['sql']);
    }

    // -------------------------------------------------------------------------
    // tombstone() — DECISION I (v1.10)
    // -------------------------------------------------------------------------

    public function test_tombstone_opens_transaction_and_commits(): void
    {
        $event = new FakeAdapterEvent(
            aggregateType: 'page',
            aggregateId:   '1',
        );

        $this->adapter->tombstone('page', '1', $event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit', $methods);
        self::assertNotContains('rollback', $methods);
    }

    public function test_tombstone_updates_deleted_at_on_pages_table(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'page', aggregateId: '1');
        $this->adapter->tombstone('page', '1', $event);

        self::assertSame(1, $this->db->countExecuteContaining('UPDATE content.pages'));
    }

    public function test_tombstone_records_processed_event(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'page', aggregateId: '1');
        $this->adapter->tombstone('page', '1', $event);

        self::assertSame(1, $this->db->countExecuteContaining('processed_events'));
    }

    public function test_tombstone_upserts_aggregate_version(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'page', aggregateId: '1');
        $this->adapter->tombstone('page', '1', $event);

        self::assertSame(1, $this->db->countExecuteContaining('aggregate_versions'));
    }

    public function test_tombstone_deleted_at_derived_from_source_updated_at(): void
    {
        $ts = new \DateTimeImmutable('2024-06-15 10:00:00', new \DateTimeZone('UTC'));
        $event = new FakeAdapterEvent(
            aggregateType:   'page',
            aggregateId:     '1',
            sourceUpdatedAt: $ts,
        );

        $this->adapter->tombstone('page', '1', $event);

        $updateCall = current(array_filter(
            $this->db->log,
            fn ($e) => $e['method'] === 'execute' && str_contains($e['sql'], 'UPDATE content.pages')
        ));
        self::assertNotFalse($updateCall);
        self::assertStringContainsString('2024-06-15', $updateCall['params'][0]);
    }

    public function test_tombstone_idempotent_on_missing_row(): void
    {
        // If the projection row doesn't exist, UPDATE affects 0 rows → still commits (no-op).
        $event = new FakeAdapterEvent(aggregateType: 'page', aggregateId: '999');
        $this->adapter->tombstone('page', '999', $event);

        self::assertContains('commit', $this->db->loggedMethods());
    }

    public function test_tombstone_rollback_on_failure(): void
    {
        $this->db->failNextExecute(); // UPDATE throws

        $event = new FakeAdapterEvent(aggregateType: 'page', aggregateId: '1');

        $this->expectException(\HSP\Core\Database\Exception\DatabaseException::class);
        try {
            $this->adapter->tombstone('page', '1', $event);
        } finally {
            self::assertContains('rollback', $this->db->loggedMethods());
        }
    }

    // -------------------------------------------------------------------------
    // bulkPersist() — Phase 1A stub (FLAG-P1AS4-3, architect ruling 2026-06-23)
    // -------------------------------------------------------------------------

    public function test_bulk_persist_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->adapter->bulkPersist([$this->makePage()]);
    }

    public function test_bulk_persist_writes_nothing_before_throwing(): void
    {
        try {
            $this->adapter->bulkPersist([$this->makePage()]);
        } catch (\LogicException) {
            // expected
        }

        self::assertNotContains('beginTransaction', $this->db->loggedMethods(), 'no transaction opened');
        self::assertEmpty(
            array_filter($this->db->log, fn($e) => $e['method'] === 'execute'),
            'no execute calls'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makePage(int $postId = 1, string $slug = 'hello-world'): CanonicalPage
    {
        return new CanonicalPage(
            postId:      $postId,
            title:       'Hello World',
            content:     '<p>Content</p>',
            slug:        $slug,
            status:      'publish',
            parentId:    0,
            menuOrder:   0,
            publishedAt: new \DateTimeImmutable('2024-01-01T00:00:00Z'),
            modifiedAt:  new \DateTimeImmutable('2024-06-01T00:00:00Z'),
            meta:        ['_custom' => 'value'],
        );
    }
}
