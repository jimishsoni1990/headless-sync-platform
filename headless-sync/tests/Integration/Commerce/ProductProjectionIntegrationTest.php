<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Handlers\ProductTombstoneHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 — commerce.products against live PostgreSQL: handler → adapter → projection, and the
 * delivery query provider over the same schema.
 *
 * The schema is built by executing the REAL migration SQL files, never a hand-copied DDL
 * block. Hand-seeded DDL is what let migration 0011 ship unwired and unnoticed
 * (FLAG-ONBS2-1) — the tests passed while a fresh install had no such table. Running the real
 * files means these tests also prove the migration and its indexes, which the EXPLAIN
 * assertions below depend on.
 *
 * Self-skips without PostgreSQL, per the suite convention.
 */
final class ProductProjectionIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private FakeCommerceSource $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new FakeCommerceSource();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS commerce CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    private function upsertHandler(): ProductUpsertHandler
    {
        return new ProductUpsertHandler(
            $this->loader,
            new ProductExtractor(new ProductValidator()),
            new ProductTransformer(),
            new ProductAdapter($this->db),
        );
    }

    private function tombstoneHandler(): ProductTombstoneHandler
    {
        return new ProductTombstoneHandler(new ProductAdapter($this->db));
    }

    // =========================================================================
    // Projection round-trip
    // =========================================================================

    public function test_a_product_projects_end_to_end(): void
    {
        $this->loader->products[42] = $this->product(42, 'blue-widget');

        $this->upsertHandler()->handle($this->event(42, 'commerce.product.created', 1));

        $row = $this->fetch(42);

        self::assertNotNull($row);
        self::assertSame('blue-widget', $row['slug']);
        self::assertSame('simple', $row['product_type']);
        self::assertSame('visible', $row['catalog_visibility']);
        // NUMERIC round-trips as an exact decimal string, never a float.
        self::assertSame('19.99', $row['price']);
        self::assertNull($row['deleted_at']);
    }

    /**
     * DECISION 3: the projection upsert, system.processed_events and
     * system.aggregate_versions all commit together.
     */
    public function test_all_three_operations_commit_together(): void
    {
        $this->loader->products[42] = $this->product(42, 'w');

        $this->upsertHandler()->handle($this->event(42, 'commerce.product.created', 7));

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM commerce.products'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM system.processed_events'));
        self::assertSame(
            7,
            $this->scalar(
                "SELECT latest_processed_version FROM system.aggregate_versions
                 WHERE aggregate_type = 'product' AND aggregate_id = '42'"
            ),
        );
    }

    /** Re-processing an unchanged product must perform ZERO projection writes (DECISION 3). */
    public function test_reprocessing_an_unchanged_product_suppresses_the_write(): void
    {
        $this->loader->products[42] = $this->product(42, 'w');

        $this->upsertHandler()->handle($this->event(42, 'commerce.product.created', 1));
        $first = $this->fetch(42);

        $this->upsertHandler()->handle($this->event(42, 'commerce.product.updated', 2, 'evt-2'));
        $second = $this->fetch(42);

        self::assertSame($first['checksum'], $second['checksum']);
        self::assertSame(
            $first['synced_at'],
            $second['synced_at'],
            'synced_at only moves when the row is actually written — an unchanged product must not churn',
        );
    }

    public function test_a_changed_price_does_reach_the_projection(): void
    {
        $this->loader->products[42] = $this->product(42, 'w');
        $this->upsertHandler()->handle($this->event(42, 'commerce.product.created', 1));

        $this->loader->products[42]['price'] = '24.50';
        $this->upsertHandler()->handle($this->event(42, 'commerce.product.updated', 2, 'evt-2'));

        // '24.5', not '24.50' — and the two halves agree for the same reason. Money::normalize()
        // strips trailing zeros to get one canonical form per value (Requirement C), and
        // NUMERIC with no declared scale does not pad them back. That agreement is what makes
        // the round trip stable: what is stored is exactly what the checksum was computed over,
        // so re-reading a product can never look like drift.
        self::assertSame('24.5', $this->fetch(42)['price']);
    }

    /** Redelivery is idempotent — at-least-once (Rule 4). */
    public function test_redelivering_the_same_event_produces_no_duplicate_row(): void
    {
        $this->loader->products[42] = $this->product(42, 'w');

        $event = $this->event(42, 'commerce.product.created', 1);
        $this->upsertHandler()->handle($event);
        $this->upsertHandler()->handle($event);

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM commerce.products'));
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM system.processed_events'));
    }

    public function test_deleting_a_product_soft_deletes_it_and_removes_it_from_the_listing(): void
    {
        $this->loader->products[42] = $this->product(42, 'w');
        $this->upsertHandler()->handle($this->event(42, 'commerce.product.created', 1));

        $this->tombstoneHandler()->handle($this->event(42, 'commerce.product.deleted', 2, 'evt-2'));

        self::assertNotNull($this->fetch(42)['deleted_at'], 'soft delete, not a hard delete');
        self::assertCount(0, $this->provider()->list(new ProductFilterSet())->rows);
    }

    /**
     * AG-13: a product that leaves supported scope must TOMBSTONE, not linger. Driven through
     * the real handler, so the decision is the shipped one rather than a re-implementation.
     */
    public function test_a_product_retyped_out_of_scope_is_tombstoned(): void
    {
        $this->loader->products[42] = $this->product(42, 'w', 'variable');
        $this->upsertHandler()->handle($this->event(42, 'commerce.product.created', 1));
        self::assertNull($this->fetch(42)['deleted_at']);

        $this->loader->products[42]['product_type'] = 'grouped';
        $this->upsertHandler()->handle($this->event(42, 'commerce.product.updated', 2, 'evt-2'));

        self::assertNotNull(
            $this->fetch(42)['deleted_at'],
            'a variable product retyped to grouped must not stay publicly visible forever',
        );
    }

    // =========================================================================
    // Catalog visibility (Requirement B)
    // =========================================================================

    public function test_a_published_product_hidden_from_the_catalog_is_excluded_from_the_listing(): void
    {
        $this->loader->products[1] = $this->product(1, 'visible-widget');
        $this->loader->products[2] = $this->product(2, 'hidden-widget');
        // publish status, but WooCommerce excludes it from the catalog.
        $this->loader->products[2]['catalog_visibility'] = 'hidden';

        $this->upsertHandler()->handle($this->event(1, 'commerce.product.created', 1, 'e1'));
        $this->upsertHandler()->handle($this->event(2, 'commerce.product.created', 1, 'e2'));

        $slugs = array_column($this->provider()->list(new ProductFilterSet())->rows, 'slug');

        self::assertSame(['visible-widget'], $slugs);

        // …but it remains reachable at its direct address, as WooCommerce does.
        self::assertNotNull($this->provider()->findBySlug('hidden-widget'));
    }

    // =========================================================================
    // Cursor pagination with duplicate sort values
    // =========================================================================

    /**
     * The case a naive cursor gets wrong: products imported together share `published_at` to
     * the second, so without the id tiebreaker a page boundary skips or repeats rows.
     */
    public function test_paging_products_that_share_a_published_at_returns_each_exactly_once(): void
    {
        for ($id = 1; $id <= 25; $id++) {
            $this->loader->products[$id] = $this->product($id, "widget-{$id}");
            $this->upsertHandler()->handle($this->event($id, 'commerce.product.created', 1, "e{$id}"));
        }

        $seen   = [];
        $cursor = null;

        do {
            $page = $this->provider()->list(new ProductFilterSet(cursor: $cursor, limit: 7));

            foreach ($page->rows as $row) {
                $seen[] = $row['slug'];
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(25, $seen);
        self::assertSame(25, count(array_unique($seen)), 'no product may be returned twice');
    }

    // =========================================================================
    // PERFORMANCE DoD — index-backed access paths
    // =========================================================================

    /**
     * Seeded at a realistic size deliberately. Against a handful of rows PostgreSQL correctly
     * prefers a sequential scan, so the assertion would pass or fail for reasons unrelated to
     * the index — the mistake P1B-S1, S2 and S3 each made and had to correct.
     */
    public function test_the_listing_and_slug_lookup_are_index_backed(): void
    {
        $this->seedProducts(2000);
        pg_query($this->pgConn, 'ANALYZE commerce.products');

        $listing = $this->explain(
            "SELECT id FROM commerce.products
             WHERE deleted_at IS NULL AND catalog_visibility IN ('visible','catalog')
             ORDER BY published_at DESC, id DESC LIMIT 20"
        );
        self::assertStringNotContainsString('Seq Scan on products', $listing, $listing);

        $slug = $this->explain(
            "SELECT id FROM commerce.products WHERE slug = 'widget-1500' AND deleted_at IS NULL LIMIT 1"
        );
        self::assertStringNotContainsString('Seq Scan on products', $slug, $slug);

        $sku = $this->explain(
            "SELECT id FROM commerce.products WHERE sku = 'SKU-1500' AND deleted_at IS NULL LIMIT 1"
        );
        self::assertStringNotContainsString('Seq Scan on products', $sku, $sku);
    }

    /** No N+1: a listing costs the same number of queries at any page size. */
    public function test_a_listing_costs_the_same_query_count_at_any_page_size(): void
    {
        $this->seedProducts(50);

        $counting = new CountingConnection($this->db);
        $provider = new ProductQueryProvider($counting);

        $provider->list(new ProductFilterSet(limit: 1));
        $one = $counting->queries;

        $counting->queries = 0;
        $provider->list(new ProductFilterSet(limit: 50));
        $many = $counting->queries;

        self::assertSame(1, $one, 'a listing must be a single query');
        self::assertSame($one, $many, 'query count must not grow with page size');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function provider(): ProductQueryProvider
    {
        return new ProductQueryProvider($this->db);
    }

    /** @return array<string,mixed>|null */
    private function fetch(int $sourceId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM commerce.products WHERE source_product_id = $1',
            [$sourceId],
        );

        return $rows[0] ?? null;
    }

    private function scalar(string $sql): int
    {
        $rows = $this->db->query($sql);

        return (int) array_values($rows[0] ?? [0])[0];
    }

    private function explain(string $sql): string
    {
        $result = pg_query($this->pgConn, 'EXPLAIN ' . $sql);
        $plan   = '';

        while ($row = pg_fetch_row($result)) {
            $plan .= $row[0] . "\n";
        }

        return $plan;
    }

    private function seedProducts(int $count): void
    {
        $values = [];
        for ($i = 1; $i <= $count; $i++) {
            $values[] = sprintf(
                "(gen_random_uuid(), %d, 'SKU-%d', 'widget-%d', 'Widget %d', 'publish', 'simple',
                  'visible', false, %s, NOW() - (%d * INTERVAL '1 minute'), NOW(), NOW(), NOW(), '%s', '{}')",
                $i,
                $i,
                $i,
                $i,
                number_format(5 + ($i % 90), 2, '.', ''),
                $i,
                str_repeat('a', 64),
            );
        }

        pg_query($this->pgConn, '
            INSERT INTO commerce.products
                (id, source_product_id, sku, slug, name, status, product_type,
                 catalog_visibility, featured, price, published_at, updated_at, created_at,
                 synced_at, checksum, meta_jsonb)
            VALUES ' . implode(',', $values));
    }

    /** @return array<string,mixed> */
    private function product(int $id, string $slug, string $type = 'simple'): array
    {
        return [
            'id'                 => $id,
            'sku'                => 'SKU-' . $id,
            'slug'               => $slug,
            'name'               => 'Widget ' . $id,
            'description'        => 'A widget.',
            'short_description'  => 'Widget.',
            'status'             => 'publish',
            'product_type'       => $type,
            'catalog_visibility' => 'visible',
            'featured'           => false,
            'price'              => '19.99',
            'regular_price'      => '24.99',
            'sale_price'         => '19.99',
            'featured_media_id'  => 0,
            'gallery_media_ids'  => [],
            'published_at'       => '2026-01-01 00:00:00',
            'modified_at'        => '2026-01-01 00:00:00',
            'meta'               => [],
        ];
    }

    private function event(
        int $id,
        string $type,
        int $version,
        string $eventId = 'evt-1',
    ): \HSP\Core\Contracts\EventInterface {
        return new IntegrationCommerceEvent($type, (string) $id, $version, $eventId);
    }

    private function createSchema(): void
    {
        \HSP\Tests\Support\CommerceSchema::applySystemTables($this->pgConn);
        \HSP\Tests\Support\CommerceSchema::applyAll($this->pgConn);
    }
    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: 'postgres';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'postgres';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'postgres';

        $conn = @pg_connect(
            "host={$host} port={$port} user={$user} password={$pass} dbname={$db}",
            PGSQL_CONNECT_FORCE_NEW,
        );

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port}.");
        }

        return $conn;
    }
}

/** Loader double holding a small in-memory catalog. */
/** Loader double holding a small in-memory catalog. */
final class FakeCommerceSource extends \HSP\Tests\Support\InMemoryCommerceLoader
{
}

/** Counts queries so the no-N+1 assertion measures rather than assumes. */
final class CountingConnection implements \HSP\Core\Database\DatabaseConnectionInterface
{
    public int $queries = 0;

    public function __construct(private readonly \HSP\Core\Database\DatabaseConnectionInterface $inner)
    {
    }

    /** @return array<int, array<string,mixed>> */
    public function query(string $sql, array $params = []): array
    {
        $this->queries++;

        return $this->inner->query($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->inner->execute($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }
}

final class IntegrationCommerceEvent implements \HSP\Core\Contracts\EventInterface
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $aggregateId,
        private readonly int $version,
        private readonly string $eventKey,
    ) {
    }

    public function getId(): string
    {
        // Deterministic UUID per logical event, so redelivery of the SAME event collides on
        // processed_events exactly as it would in production.
        return sprintf('%s-0000-7000-8000-%012d', substr(md5($this->eventKey), 0, 8), crc32($this->eventKey) % 999999);
    }

    public function getEventType(): string { return $this->eventType; }
    public function getEventVersion(): int { return 1; }
    public function getAggregateType(): string { return 'product'; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getAggregateVersion(): int { return $this->version; }
    /** @return array<string,mixed> */
    public function getPayload(): array { return []; }
    public function getChecksum(): string { return str_repeat('f', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string { return null; }
}
