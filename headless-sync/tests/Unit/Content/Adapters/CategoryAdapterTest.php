<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Adapters;

use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\CanonicalModels\CanonicalCategory;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CategoryAdapter.
 *
 * Query queue layout per persist() call:
 *   slot 0 — fetchExistingRow (pre-txn)
 *   slot 1 — lockAggregateVersion FOR UPDATE (inside txn)
 *
 * Execute layout per persist() call (non-suppressed):
 *   #1 lockAggregateVersion sentinel INSERT   (contains 'aggregate_versions', no GREATEST)
 *   #2 upsertTaxonomy                         (contains 'content.taxonomies')
 *   #3 insertProcessedEvent                   (contains 'processed_events')
 *   #4 upsertAggregateVersion GREATEST upsert (contains 'aggregate_versions', has GREATEST)
 *
 * When projection is suppressed, #2 is absent; #1, #3, #4 still run.
 */
final class CategoryAdapterTest extends TestCase
{
    private FakeDbConnection $db;
    private CategoryAdapter  $adapter;
    private FakeAdapterEvent $event;

    protected function setUp(): void
    {
        $this->db      = new FakeDbConnection();
        $this->adapter = new CategoryAdapter($this->db);
        $this->event   = new FakeAdapterEvent(
            eventType:        'content.category.created',
            aggregateType:    'category',
            aggregateId:      '5',
            aggregateVersion: 2,
        );
    }

    public function test_get_canonical_model_class_returns_canonical_category(): void
    {
        self::assertSame(CanonicalCategory::class, $this->adapter->getCanonicalModelClass());
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_persist_executes_three_ops_inside_a_transaction(): void
    {
        // slot 0: no prior row; slot 1: FOR UPDATE locked version 0
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);

        $this->adapter->persist($this->makeCategory(), $this->event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit',           $methods);
        self::assertNotContains('rollback',      $methods);

        self::assertSame(1, $this->db->countExecuteContaining('content.taxonomies'), 'projection upsert');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'),   'processed_events insert');
        // aggregate_versions: sentinel INSERT + GREATEST upsert
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert');
    }

    // -------------------------------------------------------------------------
    // Suppress path — must still record events (Item 5)
    // -------------------------------------------------------------------------

    public function test_persist_suppress_still_records_events_and_advances_version(): void
    {
        $model = $this->makeCategory();

        // slot 0: matching checksum; slot 1: FOR UPDATE locked version 0
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-aaaaaaaaaaaa', 'checksum' => $model->getChecksum()]],
            [['latest_processed_version' => '0']],
        );

        $this->adapter->persist($model, $this->event);

        self::assertContains('beginTransaction', $this->db->loggedMethods(), 'transaction must open on suppress');
        self::assertContains('commit',           $this->db->loggedMethods(), 'transaction must commit on suppress');
        self::assertSame(0, $this->db->countExecuteContaining('content.taxonomies'), 'no projection upsert on suppress');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'),   'processed_events recorded on suppress');
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert on suppress');
    }

    // -------------------------------------------------------------------------
    // Version guard — atomic with projection write (Item 6 / concurrency fix)
    // -------------------------------------------------------------------------

    public function test_persist_version_guard_suppresses_projection_but_records_events(): void
    {
        // slot 0: different checksum; slot 1: FOR UPDATE locked version 9 (incoming=2 < 9)
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-cccccccccccc', 'checksum' => str_repeat('1', 64)]],
            [['latest_processed_version' => '9']],
        );

        $this->adapter->persist($this->makeCategory(), $this->event);

        self::assertSame(0, $this->db->countExecuteContaining('content.taxonomies'), 'no projection on version guard');
        self::assertContains('beginTransaction', $this->db->loggedMethods());
        self::assertContains('commit',           $this->db->loggedMethods());
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'));
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert');
    }

    public function test_persist_version_guard_uses_for_update_locked_value(): void
    {
        $this->db->queueQueryResults(
            [],
            [['latest_processed_version' => '1']], // locked=1, incoming=2 → write
        );

        $this->adapter->persist($this->makeCategory(), $this->event);

        $lockQuery = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'query' && str_contains($e['sql'], 'FOR UPDATE')
        ));
        self::assertCount(1, $lockQuery, 'FOR UPDATE query must be present');
        self::assertSame(1, $this->db->countExecuteContaining('content.taxonomies'), 'projection written when version >= locked');
    }

    public function test_persist_writes_when_checksum_differs_and_no_version_conflict(): void
    {
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-dddddddddddd', 'checksum' => str_repeat('0', 64)]],
            [['latest_processed_version' => '0']],
        );

        $this->adapter->persist($this->makeCategory(), $this->event);

        self::assertSame(1, $this->db->countExecuteContaining('content.taxonomies'));
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
            $this->adapter->persist($this->makeCategory(), $this->event);
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

        $wrongModel = new CanonicalPage(
            postId: 1, title: 'T', content: 'C', slug: 's', status: 'publish',
            parentId: 0, menuOrder: 0,
            publishedAt: new \DateTimeImmutable(),
            modifiedAt:  new \DateTimeImmutable(),
            meta: [],
        );

        $this->adapter->persist($wrongModel, $this->event);
    }

    // -------------------------------------------------------------------------
    // aggregate_versions GREATEST guard
    // -------------------------------------------------------------------------

    public function test_aggregate_version_upsert_uses_greatest(): void
    {
        $this->db->queueQueryResults([], [['latest_processed_version' => '0']]);
        $this->adapter->persist($this->makeCategory(), $this->event);

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
        $this->adapter->persist($this->makeCategory(), $this->event);

        $sentinelLog = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'execute'
                && str_contains($e['sql'], 'aggregate_versions')
                && str_contains($e['sql'], 'ON CONFLICT')
                && ! str_contains($e['sql'], 'GREATEST')
        ));

        self::assertCount(1, $sentinelLog);
        self::assertStringContainsString('DO NOTHING', $sentinelLog[0]['sql']);
    }

    // -------------------------------------------------------------------------
    // bulkPersist() — Phase 1A stub (FLAG-P1AS4-3, architect ruling 2026-06-23)
    // -------------------------------------------------------------------------

    public function test_bulk_persist_throws_logic_exception(): void
    {
        $this->expectException(\LogicException::class);
        $this->adapter->bulkPersist([$this->makeCategory()]);
    }

    public function test_bulk_persist_writes_nothing_before_throwing(): void
    {
        try {
            $this->adapter->bulkPersist([$this->makeCategory()]);
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

    private function makeCategory(int $termId = 5, string $slug = 'tech'): CanonicalCategory
    {
        return new CanonicalCategory(
            termId:      $termId,
            name:        'Technology',
            slug:        $slug,
            description: 'All things tech',
            parentId:    0,
            count:       12,
        );
    }
}
