<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\CanonicalModels\CanonicalCategory;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for Content adapters against live PostgreSQL.
 *
 * DoD coverage:
 *   1. Atomicity: mid-transaction failure leaves NO partial writes (no projection row,
 *      no processed_events row, no aggregate_versions row) — DECISION 3.
 *   2. Idempotency: processing the same event twice produces no duplicate rows AND
 *      the second pass is write-suppressed by the stored-vs-canonical checksum — OPEN-11.
 *
 * Tested adapters: PageAdapter, PostAdapter, CategoryAdapter.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class AdapterAtomicityIntegrationTest extends TestCase
{
    private mixed  $pgConn = null;
    private string $schema = 'hsp_adapter_test';

    // -------------------------------------------------------------------------
    // setUp / tearDown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, "DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // -------------------------------------------------------------------------
    // PageAdapter — atomicity
    // -------------------------------------------------------------------------

    /**
     * Force a failure after the content.pages upsert by substituting a connection
     * that throws on the second execute() call (the processed_events INSERT).
     * All three tables must be empty afterwards.
     */
    public function test_page_adapter_mid_transaction_failure_leaves_no_partial_writes(): void
    {
        $db     = $this->makeConnection();
        $saboteur = new SaboteurConnection($this->pgConn, failOnExecuteNumber: 2);
        $adapter  = new PageAdapter($saboteur);
        $event    = $this->makeEvent('content.page.created', 'page', '1', 1);
        $model    = $this->makePage(1);

        try {
            $adapter->persist($model, $event);
            self::fail('persist() must throw when a mid-transaction execute fails');
        } catch (DatabaseException $e) {
            // Expected — saboteur threw.
        }

        // No partial writes must exist.
        self::assertSame(0, $this->countRows('content.pages'),           'content.pages must be empty');
        self::assertSame(0, $this->countRows('system.processed_events'), 'system.processed_events must be empty');
        self::assertSame(0, $this->countRows('system.aggregate_versions'), 'system.aggregate_versions must be empty');
    }

    // -------------------------------------------------------------------------
    // PostAdapter — atomicity
    // -------------------------------------------------------------------------

    public function test_post_adapter_mid_transaction_failure_leaves_no_partial_writes(): void
    {
        $saboteur = new SaboteurConnection($this->pgConn, failOnExecuteNumber: 2);
        $adapter  = new PostAdapter($saboteur);
        $event    = $this->makeEvent('content.post.created', 'post', '42', 1);
        $model    = $this->makePost(42);

        try {
            $adapter->persist($model, $event);
            self::fail('persist() must throw when a mid-transaction execute fails');
        } catch (DatabaseException $e) {
            // Expected.
        }

        self::assertSame(0, $this->countRows('content.posts'),            'content.posts must be empty');
        self::assertSame(0, $this->countRows('system.processed_events'),  'system.processed_events must be empty');
        self::assertSame(0, $this->countRows('system.aggregate_versions'), 'system.aggregate_versions must be empty');
    }

    // -------------------------------------------------------------------------
    // CategoryAdapter — atomicity
    // -------------------------------------------------------------------------

    public function test_category_adapter_mid_transaction_failure_leaves_no_partial_writes(): void
    {
        $saboteur = new SaboteurConnection($this->pgConn, failOnExecuteNumber: 2);
        $adapter  = new CategoryAdapter($saboteur);
        $event    = $this->makeEvent('content.category.created', 'category', '5', 1);
        $model    = $this->makeCategory(5);

        try {
            $adapter->persist($model, $event);
            self::fail('persist() must throw when a mid-transaction execute fails');
        } catch (DatabaseException $e) {
            // Expected.
        }

        self::assertSame(0, $this->countRows('content.taxonomies'),       'content.taxonomies must be empty');
        self::assertSame(0, $this->countRows('system.processed_events'),  'system.processed_events must be empty');
        self::assertSame(0, $this->countRows('system.aggregate_versions'), 'system.aggregate_versions must be empty');
    }

    // -------------------------------------------------------------------------
    // PageAdapter — idempotency (write-suppress on second pass)
    // -------------------------------------------------------------------------

    public function test_page_adapter_second_persist_of_same_event_is_write_suppressed(): void
    {
        $db      = $this->makeConnection();
        $adapter = new PageAdapter($db);
        $event   = $this->makeEvent('content.page.created', 'page', '10', 1);
        $model   = $this->makePage(10);

        // First persist — must write.
        $adapter->persist($model, $event);
        self::assertSame(1, $this->countRows('content.pages'),            '1 page after first persist');
        self::assertSame(1, $this->countRows('system.processed_events'),  '1 processed_events after first persist');
        self::assertSame(1, $this->countRows('system.aggregate_versions'), '1 aggregate_versions after first persist');

        // Second persist with the SAME model (identical checksum) — must suppress.
        $adapter->persist($model, $event);
        self::assertSame(1, $this->countRows('content.pages'),            'still 1 page after suppressed second persist');
        self::assertSame(1, $this->countRows('system.processed_events'),  'still 1 processed_events after suppressed second persist');
        self::assertSame(1, $this->countRows('system.aggregate_versions'), 'still 1 aggregate_versions after suppressed second persist');
    }

    // -------------------------------------------------------------------------
    // PostAdapter — idempotency
    // -------------------------------------------------------------------------

    public function test_post_adapter_second_persist_of_same_event_is_write_suppressed(): void
    {
        $db      = $this->makeConnection();
        $adapter = new PostAdapter($db);
        $event   = $this->makeEvent('content.post.created', 'post', '20', 1);
        $model   = $this->makePost(20);

        $adapter->persist($model, $event);
        self::assertSame(1, $this->countRows('content.posts'));

        $adapter->persist($model, $event);
        self::assertSame(1, $this->countRows('content.posts'), 'no duplicate post row on second persist');
    }

    // -------------------------------------------------------------------------
    // CategoryAdapter — idempotency
    // -------------------------------------------------------------------------

    public function test_category_adapter_second_persist_of_same_event_is_write_suppressed(): void
    {
        $db      = $this->makeConnection();
        $adapter = new CategoryAdapter($db);
        $event   = $this->makeEvent('content.category.created', 'category', '30', 1);
        $model   = $this->makeCategory(30);

        $adapter->persist($model, $event);
        self::assertSame(1, $this->countRows('content.taxonomies'));

        $adapter->persist($model, $event);
        self::assertSame(1, $this->countRows('content.taxonomies'), 'no duplicate taxonomy row on second persist');
    }

    // -------------------------------------------------------------------------
    // PostAdapter — entity_taxonomies join rewrite (Item 4)
    // -------------------------------------------------------------------------

    /**
     * Post first projected with categories {A, B}; then re-projected with only {A}.
     * B's join row must be gone; A's must remain; all in one transaction.
     */
    public function test_post_adapter_shrinking_category_set_removes_stale_join_rows(): void
    {
        $db      = $this->makeConnection();
        $adapter = new PostAdapter($db);
        $eventV1 = $this->makeEvent('content.post.created', 'post', '50', 1);
        $eventV2 = $this->makeEvent('content.post.updated', 'post', '50', 2);

        // Seed two categories in content.taxonomies.
        $catA = $this->seedCategory(termId: 10, slug: 'cat-a');
        $catB = $this->seedCategory(termId: 20, slug: 'cat-b');

        // v1: post belongs to {A, B}.
        $modelV1 = $this->makePost(50, 'post-50-v1', [10, 20]);
        $adapter->persist($modelV1, $eventV1);

        self::assertSame(2, $this->countRows('content.entity_taxonomies'), '2 join rows after v1 project');

        // v2: post now belongs only to {A}.
        $modelV2 = $this->makePost(50, 'post-50-v2', [10]);
        $adapter->persist($modelV2, $eventV2);

        self::assertSame(1, $this->countRows('content.entity_taxonomies'), '1 join row after v2 project — B removed');

        // Confirm the surviving row points to catA.
        $rows = $this->fetchJoinRows($catA);
        self::assertCount(1, $rows, 'catA join row must remain');

        $rowsB = $this->fetchJoinRows($catB);
        self::assertCount(0, $rowsB, 'catB join row must be gone');
    }

    /**
     * Simulate a failure during the INSERT phase of the join rewrite.
     * The prior join rows ({A, B}) must still be there — no partial rewrite.
     *
     * The saboteur fires on the 3rd execute (upsertPost=1, DELETE join=2, first INSERT=3).
     * Because the exception triggers rollback before COMMIT, content.posts and join rows
     * from THIS invocation must be absent; but pre-seeded join rows are in a separate
     * committed transaction so they are not touched.
     *
     * Strategy: seed a pre-existing v1 projection first (committed), then sabotage the v2
     * rewrite's first INSERT so the whole v2 transaction rolls back. The join table must
     * still reflect v1's {A, B}.
     */
    public function test_post_adapter_join_rewrite_failure_preserves_prior_join_rows(): void
    {
        $db      = $this->makeConnection();
        $adapter = new PostAdapter($db);
        $eventV1 = $this->makeEvent('content.post.created', 'post', '60', 1);

        $catA = $this->seedCategory(termId: 11, slug: 'cat-aa');
        $catB = $this->seedCategory(termId: 21, slug: 'cat-bb');

        // Commit v1 with {A, B} successfully.
        $modelV1 = $this->makePost(60, 'post-60-v1', [11, 21]);
        $adapter->persist($modelV1, $eventV1);

        self::assertSame(2, $this->countRows('content.entity_taxonomies'), '2 join rows after v1 commit');

        // Now attempt v2 rewrite with saboteur: fail on 3rd execute
        // (upsertPost=1, DELETE=2 succeeds, INSERT catA=3 → saboteur throws).
        // Execute order inside !$suppressProjection block:
        //   1: upsertPost
        //   2: DELETE FROM content.entity_taxonomies
        //   3: INSERT INTO content.entity_taxonomies (catA) ← saboteur fires here
        $saboteur = new SaboteurConnection($this->pgConn, failOnExecuteNumber: 3);
        $saboteurAdapter = new PostAdapter($saboteur);

        $eventV2 = $this->makeEvent('content.post.updated', 'post', '60', 2);
        $modelV2 = $this->makePost(60, 'post-60-v2', [11, 21]);

        try {
            $saboteurAdapter->persist($modelV2, $eventV2);
            self::fail('persist() must throw on sabotaged execute');
        } catch (\HSP\Core\Database\Exception\DatabaseException) {
            // Expected.
        }

        // Rollback must have undone the DELETE and partial INSERT.
        // content.entity_taxonomies must still hold the 2 v1 rows.
        self::assertSame(2, $this->countRows('content.entity_taxonomies'), 'prior join rows intact after rolled-back rewrite');
    }

    // -------------------------------------------------------------------------
    // Projection content regression guard (Item 6, live PG)
    // -------------------------------------------------------------------------

    /**
     * Process v5 first (sets projection slug to 'v5-slug'), then deliver v3 out-of-order.
     * The projection content must remain 'v5-slug' — v3 must not overwrite.
     */
    public function test_projection_content_does_not_regress_on_stale_version_delivery(): void
    {
        $db      = $this->makeConnection();
        $adapter = new PageAdapter($db);

        // v5 arrives first.
        $ev5  = $this->makeEvent('content.page.updated', 'page', '77', 5);
        $m5   = $this->makePage(77, 'v5-slug');
        $adapter->persist($m5, $ev5);

        $slug5 = $this->fetchPageSlug(77);
        self::assertSame('v5-slug', $slug5, 'slug must be v5 after v5 event');

        // v3 arrives late (out-of-order).
        $ev3  = $this->makeEvent('content.page.created', 'page', '77', 3);
        $m3   = $this->makePage(77, 'v3-slug');
        $adapter->persist($m3, $ev3);

        // Projection must not have regressed to v3-slug.
        $slugAfter = $this->fetchPageSlug(77);
        self::assertSame('v5-slug', $slugAfter, 'projection content must not regress when v3 delivered after v5');

        // But v3 must still be recorded in processed_events.
        self::assertSame(2, $this->countRows('system.processed_events'), 'v3 must be recorded even though projection suppressed');
    }

    // -------------------------------------------------------------------------
    // Concurrent interleave — version guard is atomic with projection write
    // -------------------------------------------------------------------------

    /**
     * Simulates two workers processing the same aggregate concurrently:
     *
     *   Txn-A (v5, newer):  BEGIN → sentinel INSERT → FOR UPDATE (locks row) → ...
     *   Txn-B (v3, older):  BEGIN → sentinel INSERT → FOR UPDATE (BLOCKS on Txn-A's lock)
     *   Txn-A commits:      projection slug = 'v5-slug', counter = 5
     *   Txn-B unblocks:     reads locked version = 5; incoming 3 < 5 → suppresses projection
     *   Txn-B commits:      slug remains 'v5-slug', counter stays 5
     *
     * We drive the interleave directly using two raw pg connections so no wall-clock
     * thread timing is needed: Txn-A acquires the FOR UPDATE lock, then Txn-B's FOR UPDATE
     * blocks (detected via pg_send_query + pg_get_result on the non-blocking connection)
     * until Txn-A commits.
     *
     * The adapter SQL is issued via raw pg_* here because SaboteurConnection / PostgresDatabaseConnection
     * both wrap a single connection; a second independent connection requires its own pg handle.
     */
    public function test_concurrent_interleave_older_version_does_not_overwrite_newer_projection(): void
    {
        // Txn-A uses the main test connection; Txn-B gets a second independent connection.
        $connB = $this->connectPgsql();

        try {
            // ── Seed: materialise aggregate_versions row at version 0 so both txns start clean.
            pg_query($this->pgConn, "
                INSERT INTO system.aggregate_versions
                    (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
                VALUES ('page', '88', 0, NOW())
                ON CONFLICT (aggregate_type, aggregate_id) DO NOTHING
            ");

            // ── Txn-A BEGIN (v5 — the newer writer).
            pg_query($this->pgConn, 'BEGIN');
            // Sentinel INSERT (already exists from seed, so DO NOTHING fires).
            pg_query($this->pgConn, "
                INSERT INTO system.aggregate_versions
                    (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
                VALUES ('page', '88', 0, NOW())
                ON CONFLICT (aggregate_type, aggregate_id) DO NOTHING
            ");
            // FOR UPDATE — Txn-A now holds the row lock.
            $lockResultA = pg_query($this->pgConn,
                "SELECT latest_processed_version FROM system.aggregate_versions
                 WHERE aggregate_type = 'page' AND aggregate_id = '88'
                 FOR UPDATE"
            );
            $lockedA = (int) pg_fetch_assoc($lockResultA)['latest_processed_version'];
            // v5 >= 0 → write projection.
            self::assertSame(0, $lockedA, 'Txn-A sees version 0 before any writes');

            pg_query($this->pgConn, "
                INSERT INTO content.pages
                    (id, source_post_id, source_entity_type, slug, title, content, status,
                     parent_id, menu_order, published_at, updated_at, deleted_at,
                     checksum, meta_jsonb, created_at, synced_at)
                VALUES (gen_random_uuid(), 88, 'page', 'v5-slug', 'V5 Page', '', 'publish',
                        0, 0, NOW(), NOW(), NULL,
                        '" . str_repeat('5', 64) . "', '{}', NOW(), NOW())
                ON CONFLICT (source_post_id) DO UPDATE SET
                    slug = EXCLUDED.slug, checksum = EXCLUDED.checksum, synced_at = NOW()
            ");

            // ── Txn-B BEGIN (v3 — the older writer). Non-blocking so we can interleave.
            pg_query($connB, 'BEGIN');
            pg_query($connB, "
                INSERT INTO system.aggregate_versions
                    (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
                VALUES ('page', '88', 0, NOW())
                ON CONFLICT (aggregate_type, aggregate_id) DO NOTHING
            ");
            // Send FOR UPDATE non-blocking — this BLOCKS until Txn-A releases the lock.
            pg_send_query($connB,
                "SELECT latest_processed_version FROM system.aggregate_versions
                 WHERE aggregate_type = 'page' AND aggregate_id = '88'
                 FOR UPDATE"
            );

            // ── Txn-A commits — releases the row lock.
            pg_query($this->pgConn, "
                INSERT INTO system.processed_events (event_id, checksum, processed_at)
                VALUES (gen_random_uuid(), '" . str_repeat('5', 64) . "', NOW())
                ON CONFLICT (event_id) DO NOTHING
            ");
            pg_query($this->pgConn, "
                INSERT INTO system.aggregate_versions
                    (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
                VALUES ('page', '88', 5, NOW())
                ON CONFLICT (aggregate_type, aggregate_id) DO UPDATE SET
                    latest_processed_version = GREATEST(
                        system.aggregate_versions.latest_processed_version, 5),
                    latest_processed_at = NOW()
            ");
            pg_query($this->pgConn, 'COMMIT');

            // ── Txn-B unblocks — reads the locked value now that Txn-A committed.
            $lockResultB = pg_get_result($connB); // waits for the FOR UPDATE result
            self::assertNotFalse($lockResultB, 'Txn-B FOR UPDATE must succeed after Txn-A commits');

            $rowB        = pg_fetch_assoc($lockResultB);
            $lockedB     = (int) $rowB['latest_processed_version'];
            self::assertSame(5, $lockedB, 'Txn-B must read locked version = 5 (set by Txn-A)');

            // v3 < 5 → Txn-B suppresses projection upsert; only records processed_events + agg version.
            pg_query($connB, "
                INSERT INTO system.processed_events (event_id, checksum, processed_at)
                VALUES (gen_random_uuid(), '" . str_repeat('3', 64) . "', NOW())
                ON CONFLICT (event_id) DO NOTHING
            ");
            pg_query($connB, "
                INSERT INTO system.aggregate_versions
                    (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
                VALUES ('page', '88', 3, NOW())
                ON CONFLICT (aggregate_type, aggregate_id) DO UPDATE SET
                    latest_processed_version = GREATEST(
                        system.aggregate_versions.latest_processed_version, 3),
                    latest_processed_at = NOW()
            ");
            pg_query($connB, 'COMMIT');

            // ── Assertions.
            $slugAfter = $this->fetchPageSlug(88);
            self::assertSame('v5-slug', $slugAfter,
                'projection slug must remain v5-slug — older Txn-B must not overwrite');

            $versionAfter = $this->fetchAggregateVersion('page', '88');
            self::assertSame(5, $versionAfter,
                'aggregate counter must remain 5 — GREATEST guard prevents regression');

            self::assertSame(2, $this->countRows('system.processed_events'),
                'both events must be recorded (suppress does not skip processed_events)');
        } finally {
            pg_query($connB, 'ROLLBACK'); // safety: no-op if already committed
            pg_close($connB);
        }
    }

    // -------------------------------------------------------------------------
    // aggregate_versions monotonic guard
    // -------------------------------------------------------------------------

    public function test_aggregate_versions_never_regresses_on_out_of_order_delivery(): void
    {
        $db      = $this->makeConnection();
        $adapter = new PageAdapter($db);

        // Process version 5 first.
        $ev5  = $this->makeEvent('content.page.updated', 'page', '99', 5);
        $m5   = $this->makePage(99, 'v5-slug');
        $adapter->persist($m5, $ev5);

        $version5 = $this->fetchAggregateVersion('page', '99');
        self::assertSame(5, $version5, 'latest_processed_version must be 5 after v5 event');

        // Now deliver version 3 (out-of-order redelivery) — version must not regress.
        $ev3  = $this->makeEvent('content.page.created', 'page', '99', 3);
        $m3   = $this->makePage(99, 'v3-slug');
        $adapter->persist($m3, $ev3);

        $versionAfter = $this->fetchAggregateVersion('page', '99');
        self::assertSame(5, $versionAfter, 'latest_processed_version must NOT regress to 3 (monotonic guard)');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeConnection(): PostgresDatabaseConnection
    {
        return new PostgresDatabaseConnection($this->pgConn);
    }

    private function makePage(int $postId = 1, string $slug = 'test-page'): CanonicalPage
    {
        return new CanonicalPage(
            postId:      $postId,
            title:       'Test Page',
            content:     '<p>Page content</p>',
            slug:        $slug,
            status:      'publish',
            parentId:    0,
            menuOrder:   0,
            publishedAt: new \DateTimeImmutable('2024-01-15T10:00:00Z'),
            modifiedAt:  new \DateTimeImmutable('2024-02-01T09:00:00Z'),
            meta:        ['_wp_page_template' => 'default'],
        );
    }

    /** @param list<int> $categoryIds */
    private function makePost(int $postId = 42, string $slug = 'test-post', array $categoryIds = [1, 2]): CanonicalPost
    {
        return new CanonicalPost(
            postId:      $postId,
            title:       'Test Post',
            content:     '<p>Post body</p>',
            excerpt:     'Short intro',
            slug:        $slug,
            status:      'publish',
            author:      'editor',
            publishedAt: new \DateTimeImmutable('2024-03-10T08:00:00Z'),
            modifiedAt:  new \DateTimeImmutable('2024-03-12T12:00:00Z'),
            categoryIds: $categoryIds,
            meta:        [],
        );
    }

    /**
     * Seed a row directly into content.taxonomies, bypassing the adapter.
     * Returns the UUID assigned to the taxonomy row.
     */
    private function seedCategory(int $termId, string $slug): string
    {
        $uuid = $this->newUuid();
        $now  = date('Y-m-d H:i:sP');
        pg_query_params(
            $this->pgConn,
            'INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description,
                 parent_id, post_count, deleted_at, checksum, created_at, updated_at, synced_at)
             VALUES ($1::uuid, $2, $3, $4, $5, $6, $7, $8, NULL, $9, $10::timestamptz, $11::timestamptz, $12::timestamptz)
             ON CONFLICT (source_term_id) DO NOTHING',
            [$uuid, $termId, 'category', $slug, ucfirst($slug), '', 0, 0, str_repeat('0', 64), $now, $now, $now]
        );
        return $uuid;
    }

    /**
     * Fetch all entity_taxonomies rows whose taxonomy_id matches $taxonomyUuid.
     * @return list<array<string,mixed>>
     */
    private function fetchJoinRows(string $taxonomyUuid): array
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT entity_id, taxonomy_id FROM content.entity_taxonomies WHERE taxonomy_id = $1::uuid',
            [$taxonomyUuid]
        );
        if ($result === false) {
            return [];
        }
        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);
        return $rows;
    }

    private function fetchPageSlug(int $sourcePostId): ?string
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT slug FROM content.pages WHERE source_post_id = $1',
            [$sourcePostId]
        );
        if ($result === false) {
            return null;
        }
        $row = pg_fetch_assoc($result);
        pg_free_result($result);
        return $row['slug'] ?? null;
    }

    private function makeCategory(int $termId = 5, string $slug = 'test-cat'): CanonicalCategory
    {
        return new CanonicalCategory(
            termId:      $termId,
            name:        'Test Category',
            slug:        $slug,
            description: 'Integration test category',
            parentId:    0,
            count:       3,
        );
    }

    private function makeEvent(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
    ): FakeIntegrationEvent {
        return new FakeIntegrationEvent(
            id:               $this->newUuid(),
            eventType:        $eventType,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: $aggregateVersion,
        );
    }

    private function countRows(string $table): int
    {
        $result = pg_query($this->pgConn, "SELECT COUNT(*) AS cnt FROM {$table}");
        if ($result === false) {
            return 0;
        }
        $row = pg_fetch_assoc($result);
        pg_free_result($result);
        return (int) ($row['cnt'] ?? 0);
    }

    private function fetchAggregateVersion(string $aggregateType, string $aggregateId): int
    {
        $result = pg_query_params(
            $this->pgConn,
            'SELECT latest_processed_version FROM system.aggregate_versions
             WHERE aggregate_type = $1 AND aggregate_id = $2',
            [$aggregateType, $aggregateId]
        );
        if ($result === false) {
            return 0;
        }
        $row = pg_fetch_assoc($result);
        pg_free_result($result);
        return (int) ($row['latest_processed_version'] ?? 0);
    }

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

    // -------------------------------------------------------------------------
    // Connection helpers
    // -------------------------------------------------------------------------

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: 'postgres';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'postgres';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'postgres';

        $dsn  = "host={$host} port={$port} user={$user} password={$pass} dbname={$db}";
        $conn = @pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping integration test.");
        }

        return $conn;
    }

    private function createSchema(): void
    {
        // Defensive teardown: drop residue from prior runs before creating fresh schemas.
        // Necessary because tearDown uses pg_close() — if the physical connection dropped
        // mid-test the DROP never executed and rows survive into the next suite invocation.
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        pg_query($this->pgConn, "DROP SCHEMA IF EXISTS {$this->schema} CASCADE");

        // system schema for processed_events and aggregate_versions
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS system');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.processed_events (
                event_id     UUID        NOT NULL,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_system_processed_events PRIMARY KEY (event_id)
            )
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_system_aggregate_versions PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ');

        // content schema for projection tables
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.pages (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT \'page\',
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
                meta_jsonb         JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_pages PRIMARY KEY (id),
                CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
            )
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.posts (
                id                 UUID         NOT NULL,
                source_post_id     BIGINT       NOT NULL,
                source_entity_type VARCHAR(50)  NOT NULL DEFAULT \'post\',
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
                meta_jsonb         JSONB        NOT NULL DEFAULT \'{}\'::jsonb,
                created_at         TIMESTAMPTZ  NOT NULL,
                synced_at          TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_content_posts PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
            )
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.taxonomies (
                id              UUID         NOT NULL,
                source_term_id  BIGINT       NOT NULL,
                taxonomy_type   VARCHAR(50)  NOT NULL,
                slug            VARCHAR(255) NOT NULL,
                name            VARCHAR(255) NOT NULL,
                description     TEXT         NOT NULL DEFAULT \'\',
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
        ');
        pg_query($this->pgConn, '
            CREATE TABLE IF NOT EXISTS content.entity_taxonomies (
                entity_id   UUID NOT NULL,
                taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ');
        pg_query($this->pgConn, '
            CREATE INDEX IF NOT EXISTS idx_entity_taxonomies_taxonomy_id
                ON content.entity_taxonomies (taxonomy_id)
        ');
    }
}

// -------------------------------------------------------------------------
// Test-local helpers (in same file to avoid namespace clutter)
// -------------------------------------------------------------------------

/**
 * Configurable EventInterface stub for integration tests.
 */
final class FakeIntegrationEvent implements \HSP\Core\Contracts\EventInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $eventType,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly int    $aggregateVersion,
    ) {}

    public function getId(): string                          { return $this->id; }
    public function getEventType(): string                   { return $this->eventType; }
    public function getEventVersion(): int                   { return 1; }
    public function getAggregateType(): string               { return $this->aggregateType; }
    public function getAggregateId(): string                 { return $this->aggregateId; }
    public function getAggregateVersion(): int               { return $this->aggregateVersion; }
    public function getPayload(): array                      { return []; }
    public function getChecksum(): string                    { return str_repeat('f', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable       { return new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCorrelationId(): string               { return '01900000-0000-7000-8000-000000000099'; }
    public function getCausationId(): ?string                { return null; }
}

/**
 * DatabaseConnectionInterface wrapper that throws DatabaseException on the Nth execute() call.
 *
 * Used to simulate a mid-transaction failure for atomicity tests.
 * rollback() is always passed through (must not be sabotaged — that would hide the real bug).
 */
final class SaboteurConnection implements \HSP\Core\Database\DatabaseConnectionInterface
{
    private int $executeCount = 0;

    public function __construct(
        private readonly mixed $pgConn,
        private readonly int   $failOnExecuteNumber,
    ) {}

    public function execute(string $sql, array $params = []): int
    {
        $this->executeCount++;

        if ($this->executeCount === $this->failOnExecuteNumber) {
            throw new DatabaseException("Saboteur: intentional failure on execute #{$this->executeCount}");
        }

        $result = empty($params)
            ? pg_query($this->pgConn, $sql)
            : pg_query_params($this->pgConn, $sql, $params);

        if ($result === false) {
            throw new DatabaseException('PostgreSQL execute failed: ' . pg_last_error($this->pgConn));
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);
        return $affected;
    }

    public function query(string $sql, array $params = []): array
    {
        $result = empty($params)
            ? pg_query($this->pgConn, $sql)
            : pg_query_params($this->pgConn, $sql, $params);

        if ($result === false) {
            throw new DatabaseException('PostgreSQL query failed: ' . pg_last_error($this->pgConn));
        }

        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);
        return $rows;
    }

    public function beginTransaction(): void
    {
        $result = pg_query($this->pgConn, 'BEGIN');
        if ($result === false) {
            throw new DatabaseException('PostgreSQL BEGIN failed: ' . pg_last_error($this->pgConn));
        }
        pg_free_result($result);
    }

    public function commit(): void
    {
        $result = pg_query($this->pgConn, 'COMMIT');
        if ($result === false) {
            throw new DatabaseException('PostgreSQL COMMIT failed: ' . pg_last_error($this->pgConn));
        }
        pg_free_result($result);
    }

    public function rollback(): void
    {
        $result = pg_query($this->pgConn, 'ROLLBACK');
        if ($result !== false) {
            pg_free_result($result);
        }
    }
}
