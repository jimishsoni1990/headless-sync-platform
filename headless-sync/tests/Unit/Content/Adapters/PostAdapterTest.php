<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Adapters;

use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PostAdapter.
 *
 * Query queue layout per persist() call:
 *   slot 0 — fetchExistingRow (pre-txn)
 *   slot 1 — lockAggregateVersion FOR UPDATE (inside txn)
 *   slot 2 — taxonomy UUID lookup (only when !$suppressProjection and categoryIds non-empty)
 *
 * Execute layout per persist() call (non-suppressed, 2 categories):
 *   #1 lockAggregateVersion sentinel INSERT  (contains 'aggregate_versions', no GREATEST)
 *   #2 upsertPost                            (contains 'content.posts')
 *   #3 DELETE FROM content.entity_taxonomies
 *   #4 INSERT INTO content.entity_taxonomies (catA)
 *   #5 INSERT INTO content.entity_taxonomies (catB)
 *   #6 insertProcessedEvent                  (contains 'processed_events')
 *   #7 upsertAggregateVersion GREATEST upsert (contains 'aggregate_versions', has GREATEST)
 *
 * When projection is suppressed, #2-#5 are absent; #1, #6, #7 still run.
 */
final class PostAdapterTest extends TestCase
{
    private FakeDbConnection $db;
    private PostAdapter      $adapter;
    private FakeAdapterEvent $event;

    protected function setUp(): void
    {
        $this->db      = new FakeDbConnection();
        $this->adapter = new PostAdapter($this->db);
        $this->event   = new FakeAdapterEvent(
            eventType:        'content.post.created',
            aggregateType:    'post',
            aggregateId:      '42',
            aggregateVersion: 3,
        );
    }

    public function test_get_canonical_model_class_returns_canonical_post(): void
    {
        self::assertSame(CanonicalPost::class, $this->adapter->getCanonicalModelClass());
    }

    // -------------------------------------------------------------------------
    // Happy path — three ops + entity_taxonomies rewrite
    // -------------------------------------------------------------------------

    public function test_persist_executes_all_ops_inside_a_transaction(): void
    {
        // slot 0: no prior row; slot 1: FOR UPDATE locked version 0; slot 2: taxonomy lookup
        $this->db->queueQueryResults(
            [],
            [['latest_processed_version' => '0']],
            [
                ['id' => '01900000-0000-7000-8000-tax000000003'],
                ['id' => '01900000-0000-7000-8000-tax000000007'],
            ],
        );

        $this->adapter->persist($this->makePost(categoryIds: [3, 7]), $this->event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit',           $methods);
        self::assertNotContains('rollback',      $methods);

        self::assertSame(1, $this->db->countExecuteContaining('content.posts'),                    'projection upsert');
        self::assertSame(1, $this->db->countExecuteContaining('DELETE FROM content.entity_taxonomies'), 'join delete');
        self::assertSame(2, $this->db->countExecuteContaining('INSERT INTO content.entity_taxonomies'), 'join inserts');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'),                 'processed_events insert');
        // aggregate_versions: sentinel INSERT + GREATEST upsert
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'),               'sentinel + GREATEST upsert');
    }

    public function test_persist_deletes_all_join_rows_then_reinserts(): void
    {
        $this->db->queueQueryResults(
            [],
            [['latest_processed_version' => '0']],
            [['id' => '01900000-0000-7000-8000-tax000000001']],
        );

        $this->adapter->persist($this->makePost(categoryIds: [1]), $this->event);

        $execLog = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'execute'
        ));

        $deleteIdx = null;
        $insertIdx = null;
        foreach ($execLog as $i => $entry) {
            if (str_contains($entry['sql'], 'DELETE FROM content.entity_taxonomies') && $deleteIdx === null) {
                $deleteIdx = $i;
            }
            if (str_contains($entry['sql'], 'INSERT INTO content.entity_taxonomies') && $insertIdx === null) {
                $insertIdx = $i;
            }
        }

        self::assertNotNull($deleteIdx, 'DELETE must be executed');
        self::assertNotNull($insertIdx, 'INSERT must be executed');
        self::assertLessThan($insertIdx, $deleteIdx, 'DELETE must precede INSERT');
    }

    public function test_persist_empty_category_set_deletes_all_join_rows_and_inserts_none(): void
    {
        $this->db->queueQueryResults(
            [],
            [['latest_processed_version' => '0']],
            // no taxonomy slot needed for empty categoryIds
        );

        $this->adapter->persist($this->makePost(categoryIds: []), $this->event);

        self::assertSame(1, $this->db->countExecuteContaining('DELETE FROM content.entity_taxonomies'), 'DELETE runs for empty set');
        self::assertSame(0, $this->db->countExecuteContaining('INSERT INTO content.entity_taxonomies'), 'no INSERT for empty set');
    }

    public function test_persist_omits_categories_not_yet_in_content_taxonomies(): void
    {
        $this->db->queueQueryResults(
            [],
            [['latest_processed_version' => '0']],
            [['id' => '01900000-0000-7000-8000-tax000000001']], // only 1 of 2 resolved
        );

        $this->adapter->persist($this->makePost(categoryIds: [1, 99]), $this->event);

        self::assertSame(1, $this->db->countExecuteContaining('INSERT INTO content.entity_taxonomies'), 'only 1 insert for resolved category');
    }

    // -------------------------------------------------------------------------
    // Suppress path — still records events, does NOT touch join table (Item 5)
    // -------------------------------------------------------------------------

    public function test_persist_suppress_records_events_but_skips_join_rewrite(): void
    {
        $model = $this->makePost(categoryIds: [5]);

        // slot 0: matching checksum; slot 1: FOR UPDATE locked version 0
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-aaaaaaaaaaaa', 'checksum' => $model->getChecksum()]],
            [['latest_processed_version' => '0']],
        );

        $this->adapter->persist($model, $this->event);

        self::assertContains('beginTransaction', $this->db->loggedMethods());
        self::assertContains('commit',           $this->db->loggedMethods());
        self::assertSame(0, $this->db->countExecuteContaining('content.posts'),                         'no projection upsert');
        self::assertSame(0, $this->db->countExecuteContaining('DELETE FROM content.entity_taxonomies'), 'no join delete on suppress');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'),                      'processed_events recorded');
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'),                    'sentinel + GREATEST upsert');
    }

    // -------------------------------------------------------------------------
    // Version guard — atomic with projection write (Item 6 / concurrency fix)
    // -------------------------------------------------------------------------

    public function test_persist_version_guard_suppresses_projection_and_join_but_records_events(): void
    {
        // slot 0: different checksum; slot 1: FOR UPDATE locked version 10 (incoming=3 < 10)
        $this->db->queueQueryResults(
            [['id' => '01900000-0000-7000-8000-bbbbbbbbbbbb', 'checksum' => str_repeat('0', 64)]],
            [['latest_processed_version' => '10']],
        );

        $this->adapter->persist($this->makePost(), $this->event);

        self::assertSame(0, $this->db->countExecuteContaining('content.posts'),                         'no projection on version guard');
        self::assertSame(0, $this->db->countExecuteContaining('DELETE FROM content.entity_taxonomies'), 'no join rewrite on version guard');
        self::assertSame(1, $this->db->countExecuteContaining('processed_events'));
        self::assertSame(2, $this->db->countExecuteContaining('aggregate_versions'), 'sentinel + GREATEST upsert');
    }

    public function test_persist_version_guard_uses_for_update_locked_value(): void
    {
        $this->db->queueQueryResults(
            [],
            [['latest_processed_version' => '1']], // locked=1, incoming=3 → write
        );

        $this->adapter->persist($this->makePost(categoryIds: []), $this->event);

        $lockQuery = array_values(array_filter(
            $this->db->log,
            fn($e) => $e['method'] === 'query' && str_contains($e['sql'], 'FOR UPDATE')
        ));
        self::assertCount(1, $lockQuery, 'FOR UPDATE query must be present');
        self::assertSame(1, $this->db->countExecuteContaining('content.posts'), 'projection written when version >= locked');
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
            $this->adapter->persist($this->makePost(), $this->event);
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

        $this->adapter->persist($this->makePost(categoryIds: []), $this->event);

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

        $this->adapter->persist($this->makePost(categoryIds: []), $this->event);

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
    // tombstone() — DECISION I (v1.10)
    // -------------------------------------------------------------------------

    public function test_tombstone_opens_transaction_and_commits(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'post', aggregateId: '42');
        $this->adapter->tombstone('post', '42', $event);

        $methods = $this->db->loggedMethods();
        self::assertContains('beginTransaction', $methods);
        self::assertContains('commit', $methods);
        self::assertNotContains('rollback', $methods);
    }

    public function test_tombstone_updates_deleted_at_on_posts_table(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'post', aggregateId: '42');
        $this->adapter->tombstone('post', '42', $event);

        self::assertSame(1, $this->db->countExecuteContaining('UPDATE content.posts'));
    }

    public function test_tombstone_records_processed_event(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'post', aggregateId: '42');
        $this->adapter->tombstone('post', '42', $event);

        self::assertSame(1, $this->db->countExecuteContaining('processed_events'));
    }

    public function test_tombstone_upserts_aggregate_version(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'post', aggregateId: '42');
        $this->adapter->tombstone('post', '42', $event);

        self::assertSame(1, $this->db->countExecuteContaining('aggregate_versions'));
    }

    public function test_tombstone_deleted_at_derived_from_source_updated_at(): void
    {
        $ts    = new \DateTimeImmutable('2024-06-15 10:00:00', new \DateTimeZone('UTC'));
        $event = new FakeAdapterEvent(
            aggregateType:   'post',
            aggregateId:     '42',
            sourceUpdatedAt: $ts,
        );

        $this->adapter->tombstone('post', '42', $event);

        $updateCall = current(array_filter(
            $this->db->log,
            fn ($e) => $e['method'] === 'execute' && str_contains($e['sql'], 'UPDATE content.posts')
        ));
        self::assertNotFalse($updateCall);
        self::assertStringContainsString('2024-06-15', $updateCall['params'][0]);
    }

    public function test_tombstone_idempotent_on_missing_row(): void
    {
        $event = new FakeAdapterEvent(aggregateType: 'post', aggregateId: '999');
        $this->adapter->tombstone('post', '999', $event);

        self::assertContains('commit', $this->db->loggedMethods());
    }

    public function test_tombstone_rollback_on_failure(): void
    {
        $this->db->failNextExecute();
        $event = new FakeAdapterEvent(aggregateType: 'post', aggregateId: '42');

        $this->expectException(\HSP\Core\Database\Exception\DatabaseException::class);
        try {
            $this->adapter->tombstone('post', '42', $event);
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
        $this->adapter->bulkPersist([$this->makePost()]);
    }

    public function test_bulk_persist_writes_nothing_before_throwing(): void
    {
        try {
            $this->adapter->bulkPersist([$this->makePost()]);
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

    /** @param list<int> $categoryIds */
    private function makePost(int $postId = 42, string $slug = 'my-post', array $categoryIds = [3, 7]): CanonicalPost
    {
        return new CanonicalPost(
            postId:      $postId,
            title:       'My Post',
            content:     '<p>Body</p>',
            excerpt:     'Short excerpt',
            slug:        $slug,
            status:      'publish',
            author:      'admin',
            publishedAt: new \DateTimeImmutable('2024-03-01T12:00:00Z'),
            modifiedAt:  new \DateTimeImmutable('2024-03-15T08:00:00Z'),
            categoryIds: $categoryIds,
            meta:        ['_reading_time' => '5'],
        );
    }
}
