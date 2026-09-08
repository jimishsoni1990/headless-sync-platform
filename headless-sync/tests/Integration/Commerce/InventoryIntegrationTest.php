<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\InventoryAdapter;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Extractors\InventoryExtractor;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Handlers\InventoryUpsertHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\InventoryOwner;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Transformers\InventoryTransformer;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Validation\InventoryValidator;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\TestCase;

/**
 * P2-S6 — commerce.inventory against live PostgreSQL.
 *
 * Two rulings meet here and they pull in opposite directions, which is what makes the read side
 * worth this much testing:
 *
 *   AG-8  stock lives in ONE place and products join it at read time
 *   AG-7  product and inventory are independent aggregates that arrive in either order
 *
 * Together they mean a product can be perfectly valid and have no inventory row yet — so the
 * join must be tolerant (LEFT), and "no row" must read as UNKNOWN rather than as out of stock.
 * The Part 4b rules spell out both halves, and the tests below are mostly about the second: an
 * INNER JOIN would drop valid products from a listing silently, and treating NULL as false would
 * publish "sold out" about a product nobody has counted yet.
 *
 * Schema comes from the REAL migration files, so this also proves migration 0007.
 */
final class InventoryIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private InMemoryCommerceLoader $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new InMemoryCommerceLoader();

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

    // -------------------------------------------------------------------------
    // Spine
    // -------------------------------------------------------------------------

    private function inventoryHandler(): InventoryUpsertHandler
    {
        return new InventoryUpsertHandler(
            $this->loader,
            new InventoryExtractor(new InventoryValidator()),
            new InventoryTransformer(),
            new InventoryAdapter($this->db),
        );
    }

    private function productHandler(): ProductUpsertHandler
    {
        return new ProductUpsertHandler(
            $this->loader,
            new ProductExtractor(new ProductValidator()),
            new ProductTransformer(),
            new ProductAdapter($this->db),
        );
    }

    private function givenProduct(int $id, string $slug, string $type = 'simple'): void
    {
        $this->loader->products[$id] = [
            'id' => $id, 'sku' => "P-{$id}", 'slug' => $slug, 'name' => "Product {$id}",
            'description' => '', 'short_description' => '', 'status' => 'publish',
            'product_type' => $type, 'catalog_visibility' => 'visible', 'featured' => false,
            'price' => '19.99', 'regular_price' => '19.99', 'sale_price' => null,
            'featured_media_id' => 0, 'gallery_media_ids' => [],
            'published_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
            'meta' => [], 'category_ids' => [], 'attribute_term_ids' => [],
        ];
    }

    private function projectProduct(int $id, int $version = 1): void
    {
        $this->productHandler()->handle(
            new InventoryIntegrationEvent('commerce.product.updated', 'product', (string) $id, $version, "p{$id}-{$version}")
        );
    }

    private function givenInventory(
        int $ownerId,
        string $ownerType = InventoryOwner::TYPE_PRODUCT,
        string $status = 'instock',
        bool $manages = true,
        ?int $quantity = 5,
    ): void {
        $this->loader->inventory[$ownerId] = [
            'owner_type'       => $ownerType,
            'owner_id'         => $ownerId,
            'manages_stock'    => $manages,
            'stock_quantity'   => $manages ? $quantity : null,
            'stock_status'     => $status,
            'backorders'       => 'no',
            'low_stock_amount' => null,
        ];
    }

    private function projectInventory(int $ownerId, int $version = 1): void
    {
        $this->inventoryHandler()->handle($this->event($ownerId, 'updated', $version));
    }

    private function event(int $ownerId, string $action, int $version): EventInterface
    {
        return new InventoryIntegrationEvent(
            "commerce.inventory.{$action}",
            'inventory',
            (string) $ownerId,
            $version,
            "i{$ownerId}-{$action}-{$version}",
        );
    }

    /** @return array<string,mixed>|null */
    private function fetch(int $ownerId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM commerce.inventory WHERE owner_id = $1',
            [$ownerId],
        );

        return $rows[0] ?? null;
    }

    // =========================================================================
    // Projection
    // =========================================================================

    public function test_inventory_projects_end_to_end(): void
    {
        $this->givenProduct(42, 'widget');
        $this->givenInventory(42, quantity: 7);

        $this->projectInventory(42);

        $row = $this->fetch(42);

        self::assertNotNull($row);
        self::assertSame('product', $row['owner_type']);
        self::assertSame(7, (int) $row['stock_quantity']);
        self::assertSame('instock', $row['stock_status']);
        self::assertNull($row['deleted_at']);
    }

    /**
     * NULL quantity and 0 quantity are opposite facts and must not collide.
     *
     * "Not tracked" means unlimited — a made-to-order product — while 0 means sold out. A digest
     * that conflated them would suppress the write that moves between the two, and a resource
     * that published 0 for both would tell a customer the wrong thing.
     */
    public function test_untracked_stock_is_null_not_zero(): void
    {
        $this->givenProduct(42, 'made-to-order');
        $this->givenInventory(42, manages: false);
        $this->projectInventory(42);

        $row = $this->fetch(42);

        self::assertNull($row['stock_quantity']);
        self::assertSame('f', $row['manages_stock']);
        self::assertSame('instock', $row['stock_status'], 'status is independent of quantity tracking');
    }

    public function test_an_unchanged_inventory_is_write_suppressed(): void
    {
        $this->givenProduct(42, 'widget');
        $this->givenInventory(42);
        $this->projectInventory(42);

        $first = $this->fetch(42);

        $this->projectInventory(42, version: 2);

        self::assertSame($first['synced_at'], $this->fetch(42)['synced_at']);
        self::assertSame(2, $this->latestProcessedVersion('inventory', '42'));
    }

    public function test_a_quantity_change_reaches_the_projection(): void
    {
        $this->givenProduct(42, 'widget');
        $this->givenInventory(42, quantity: 5);
        $this->projectInventory(42);

        $this->givenInventory(42, quantity: 0);
        $this->projectInventory(42, version: 2);

        self::assertSame(0, (int) $this->fetch(42)['stock_quantity']);
    }

    // =========================================================================
    // Ownership (AG-14)
    // =========================================================================

    /**
     * A variation that manages its own stock IS an owner and gets its own row.
     */
    public function test_a_self_managed_variation_owns_its_stock(): void
    {
        $this->givenProduct(42, 'shirt', 'variable');
        $this->loader->variations[77] = ['id' => 77, 'parent_id' => 42];
        $this->givenInventory(77, InventoryOwner::TYPE_VARIATION, quantity: 3);

        $this->projectInventory(77);

        $row = $this->fetch(77);

        self::assertNotNull($row);
        self::assertSame('product_variation', $row['owner_type']);
        self::assertSame(3, (int) $row['stock_quantity']);
    }

    /**
     * A PARENT-MANAGED variation gets NO ROW — the core of AG-14.
     *
     * The loader declines to return it, because WooCommerce's own
     * `get_stock_managed_by_id()` names the parent. Inventing a row here for symmetry would give
     * one stock fact two projections with two checksums and two independent write-suppress
     * decisions that can disagree, with no rule for which wins.
     */
    public function test_a_parent_managed_variation_gets_no_inventory_row(): void
    {
        $this->givenProduct(42, 'shirt', 'variable');
        $this->loader->variations[77] = ['id' => 77, 'parent_id' => 42];
        // No inventory entry for 77 at all — the loader reports it as a non-owner.
        $this->givenInventory(42, quantity: 9);

        $this->projectInventory(42);
        $this->projectInventory(77);

        self::assertNotNull($this->fetch(42), 'the parent owns the fact');
        self::assertNull($this->fetch(77), 'the variation must not duplicate it');
    }

    /**
     * Switching a variation to parent-managed TOMBSTONES its row.
     *
     * The row is now a duplicate of a fact its parent holds, and leaving it published would mean
     * two answers to one question. Reached through the ordinary handler, with no
     * inventory-specific repair path.
     */
    public function test_a_variation_that_stops_owning_its_stock_is_tombstoned(): void
    {
        $this->givenProduct(42, 'shirt', 'variable');
        $this->loader->variations[77] = ['id' => 77, 'parent_id' => 42];
        $this->givenInventory(77, InventoryOwner::TYPE_VARIATION, quantity: 3);

        $this->projectInventory(77);
        self::assertNull($this->fetch(77)['deleted_at']);

        // The operator switches the variation to inherit its parent's stock.
        unset($this->loader->inventory[77]);

        $this->projectInventory(77, version: 2);

        self::assertNotNull($this->fetch(77)['deleted_at']);
    }

    /** And back again — re-emission revives it (the tombstone/checksum trap). */
    public function test_a_variation_that_resumes_owning_its_stock_is_revived(): void
    {
        $this->givenProduct(42, 'shirt', 'variable');
        $this->loader->variations[77] = ['id' => 77, 'parent_id' => 42];
        $this->givenInventory(77, InventoryOwner::TYPE_VARIATION, quantity: 3);
        $this->projectInventory(77);

        unset($this->loader->inventory[77]);
        $this->projectInventory(77, version: 2);
        self::assertNotNull($this->fetch(77)['deleted_at']);

        // Same state as before — an identical checksum, which is exactly why the adapter cannot
        // suppress on checksum alone.
        $this->givenInventory(77, InventoryOwner::TYPE_VARIATION, quantity: 3);
        $this->projectInventory(77, version: 3);

        self::assertNull($this->fetch(77)['deleted_at']);
        self::assertSame(3, (int) $this->fetch(77)['stock_quantity']);
    }

    /** One row per owner, whatever the redelivery pattern (Rule 4). */
    public function test_one_row_per_owner(): void
    {
        $this->givenProduct(42, 'widget');
        $this->givenInventory(42);

        foreach ([1, 2, 3] as $version) {
            $this->projectInventory(42, $version);
        }

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM commerce.inventory'));
    }

    // =========================================================================
    // Part 4b — out-of-order read semantics
    // =========================================================================

    /** Product first, inventory later. The product must be readable throughout. */
    public function test_a_product_is_listed_before_its_inventory_projects(): void
    {
        $this->givenProduct(42, 'widget');
        $this->projectProduct(42);

        $rows = $this->list();

        self::assertCount(1, $rows, 'an INNER JOIN would have dropped this product silently');
        self::assertNull($rows[0]['stock_status'], 'unknown, not out of stock');
    }

    /** Inventory first, product later. Neither order loses anything. */
    public function test_inventory_may_project_before_its_product(): void
    {
        $this->givenProduct(42, 'widget');
        $this->givenInventory(42, quantity: 4);

        $this->projectInventory(42);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM commerce.products'));

        $this->projectProduct(42);

        $rows = $this->list();
        self::assertCount(1, $rows);
        self::assertSame('instock', $rows[0]['stock_status']);
        self::assertSame(4, (int) $rows[0]['stock_quantity']);
    }

    /**
     * A listing during temporary lag is structurally correct: every product present, some with
     * stock and some without, and nothing missing.
     */
    public function test_a_listing_is_correct_while_inventory_lags(): void
    {
        $this->givenProduct(1, 'a');
        $this->givenProduct(2, 'b');
        $this->givenProduct(3, 'c');
        $this->projectProduct(1);
        $this->projectProduct(2);
        $this->projectProduct(3);

        // Only one has caught up.
        $this->givenInventory(2, quantity: 1);
        $this->projectInventory(2);

        $rows = $this->list();

        self::assertCount(3, $rows);

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['slug']] = $row['stock_status'];
        }

        // Sorted by slug for the comparison: the listing's own order is (published_at DESC, id
        // DESC) and these three share a timestamp, so the id tiebreaker decides — which is the
        // correct behaviour to have and the wrong thing to assert on here.
        ksort($byStatus);

        self::assertSame(['a' => null, 'b' => 'instock', 'c' => null], $byStatus);
    }

    /**
     * THE rule that costs money if it is wrong: unknown must never be read as a stock fact.
     *
     * `in_stock=true` must exclude a product nobody has counted — answering "what can I buy"
     * with unknowns loses an order — and `in_stock=false` must exclude it too, because unknown
     * is not out of stock either.
     */
    public function test_unknown_inventory_matches_neither_direction_of_the_stock_filter(): void
    {
        $this->givenProduct(1, 'known-in');
        $this->givenProduct(2, 'known-out');
        $this->givenProduct(3, 'unknown');
        $this->projectProduct(1);
        $this->projectProduct(2);
        $this->projectProduct(3);

        $this->givenInventory(1, status: 'instock');
        $this->givenInventory(2, status: 'outofstock');
        $this->projectInventory(1);
        $this->projectInventory(2);

        self::assertSame(['known-in'], $this->slugs($this->list(inStock: true)));
        self::assertSame(['known-out'], $this->slugs($this->list(inStock: false)));
        // With no filter, all three — including the unknown one.
        self::assertCount(3, $this->list());
    }

    public function test_backordered_stock_is_not_in_stock_but_is_still_listed(): void
    {
        $this->givenProduct(1, 'backordered');
        $this->projectProduct(1);
        $this->givenInventory(1, status: 'onbackorder');
        $this->projectInventory(1);

        self::assertSame([], $this->slugs($this->list(inStock: true)));
        self::assertSame(['backordered'], $this->slugs($this->list(inStock: false)));
        self::assertCount(1, $this->list());
    }

    /** A tombstoned inventory row reverts the product to unknown, not to out of stock. */
    public function test_a_tombstoned_inventory_row_reads_as_unknown_again(): void
    {
        $this->givenProduct(1, 'widget');
        $this->projectProduct(1);
        $this->givenInventory(1, status: 'instock');
        $this->projectInventory(1);

        self::assertSame('instock', $this->list()[0]['stock_status']);

        (new \HSP\Modules\Commerce\Handlers\InventoryTombstoneHandler(new InventoryAdapter($this->db)))
            ->handle($this->event(1, 'deleted', 2));

        $rows = $this->list();
        self::assertCount(1, $rows, 'the product itself is unaffected');
        self::assertNull($rows[0]['stock_status']);
    }

    // =========================================================================
    // Resource contract
    // =========================================================================

    public function test_the_resource_publishes_unknown_stock_as_nulls(): void
    {
        $this->givenProduct(1, 'widget');
        $this->projectProduct(1);

        $shaped = (new ProductResource())->toArray($this->list()[0]);

        self::assertNull($shaped['stock']['status']);
        self::assertNull($shaped['stock']['managed']);
        self::assertNull($shaped['stock']['quantity']);
    }

    /**
     * Tracked-with-zero and untracked both publish a null quantity, and `managed` is what tells
     * them apart — so the contract can express "sold out" and "unlimited" as different things.
     */
    public function test_the_resource_distinguishes_untracked_from_sold_out(): void
    {
        $this->givenProduct(1, 'made-to-order');
        $this->givenProduct(2, 'sold-out');
        $this->projectProduct(1);
        $this->projectProduct(2);

        $this->givenInventory(1, manages: false);
        $this->givenInventory(2, status: 'outofstock', quantity: 0);
        $this->projectInventory(1);
        $this->projectInventory(2);

        $shaped = [];
        foreach ($this->list() as $row) {
            $shaped[$row['slug']] = (new ProductResource())->toArray($row)['stock'];
        }

        self::assertFalse($shaped['made-to-order']['managed']);
        self::assertNull($shaped['made-to-order']['quantity']);
        self::assertSame('instock', $shaped['made-to-order']['status']);

        self::assertTrue($shaped['sold-out']['managed']);
        self::assertSame(0, $shaped['sold-out']['quantity']);
        self::assertSame('outofstock', $shaped['sold-out']['status']);
    }

    // =========================================================================
    // Migration + index shape (performance DoD)
    // =========================================================================

    /**
     * The stock-filtered listing must be index-backed at catalogue scale.
     *
     * Seeded at a realistic size deliberately: against a handful of rows a sequential scan IS the
     * cheapest plan, so an EXPLAIN assertion on a small fixture passes for the wrong reason.
     */
    public function test_the_stock_filtered_listing_is_index_backed(): void
    {
        $this->seedAtScale(2000);
        pg_query($this->pgConn, 'ANALYZE commerce.inventory');
        pg_query($this->pgConn, 'ANALYZE commerce.products');

        $plan = $this->explain(
            "SELECT p.id FROM commerce.products p
             LEFT JOIN commerce.inventory i
                    ON i.owner_type = 'product' AND i.owner_id = p.source_product_id
                   AND i.deleted_at IS NULL
             WHERE p.deleted_at IS NULL AND i.stock_status = 'instock'
             ORDER BY p.published_at DESC, p.id DESC
             LIMIT 20"
        );

        self::assertStringNotContainsString(
            'Seq Scan on inventory',
            $plan,
            'a stock filter must reach inventory by index, not scan every owner in the store',
        );
    }

    /**
     * The owner UNIQUE is a real constraint, unlike the slug indexes elsewhere in this schema.
     *
     * Those are deliberately non-unique because a duplicate would dead-letter a valid source
     * state (AG-7). This one is different in kind: it is the table's own identity, and a
     * duplicate would mean one stock fact with two projections — the exact defect AG-8 removed
     * from commerce.products.
     */
    public function test_the_owner_key_is_unique(): void
    {
        $this->givenProduct(42, 'widget');
        $this->givenInventory(42);
        $this->projectInventory(42);

        $duplicated = @pg_query(
            $this->pgConn,
            "INSERT INTO commerce.inventory (id, owner_type, owner_id, stock_status, checksum)
             VALUES (gen_random_uuid(), 'product', 42, 'instock', '" . str_repeat('a', 64) . "')"
        );

        self::assertFalse($duplicated, 'a second row for one owner must be refused');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<int, array<string,mixed>> */
    private function list(?bool $inStock = null): array
    {
        return (new ProductQueryProvider($this->db))
            ->list(new ProductFilterSet(inStock: $inStock))
            ->rows;
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @return list<string>
     */
    private function slugs(array $rows): array
    {
        return array_values(array_column($rows, 'slug'));
    }

    private function latestProcessedVersion(string $type, string $id): int
    {
        $rows = $this->db->query(
            'SELECT latest_processed_version FROM system.aggregate_versions
             WHERE aggregate_type = $1 AND aggregate_id = $2',
            [$type, $id],
        );

        return (int) ($rows[0]['latest_processed_version'] ?? 0);
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

    /** Bulk rows straight into PostgreSQL — the subject here is the PLAN, not the pipeline. */
    private function seedAtScale(int $products): void
    {
        $productRows   = [];
        $inventoryRows = [];
        $checksum      = str_repeat('a', 64);

        for ($i = 0; $i < $products; $i++) {
            $id = 1000 + $i;

            $productRows[] = sprintf(
                "(gen_random_uuid(), %d, 'slug-%d', 'P%d', 'publish', 'simple', 'visible',"
                . " '2026-01-01T00:00:00Z'::timestamptz, '%s')",
                $id,
                $id,
                $id,
                $checksum,
            );

            $inventoryRows[] = sprintf(
                "(gen_random_uuid(), 'product', %d, '%s', '%s')",
                $id,
                $i % 10 === 0 ? 'outofstock' : 'instock',
                $checksum,
            );
        }

        foreach (array_chunk($productRows, 500) as $chunk) {
            pg_query($this->pgConn, '
                INSERT INTO commerce.products
                    (id, source_product_id, slug, name, status, product_type, catalog_visibility,
                     published_at, checksum)
                VALUES ' . implode(',', $chunk));
        }

        foreach (array_chunk($inventoryRows, 500) as $chunk) {
            pg_query($this->pgConn, '
                INSERT INTO commerce.inventory (id, owner_type, owner_id, stock_status, checksum)
                VALUES ' . implode(',', $chunk));
        }
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

/** Event double carrying an explicit aggregate type. */
final class InventoryIntegrationEvent implements EventInterface
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly int $version,
        private readonly string $eventKey,
    ) {
    }

    public function getId(): string
    {
        return sprintf(
            '%s-0000-7000-8000-%012d',
            substr(md5($this->eventKey), 0, 8),
            crc32($this->eventKey) % 999999,
        );
    }

    public function getEventType(): string { return $this->eventType; }
    public function getEventVersion(): int { return 1; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getAggregateVersion(): int { return $this->version; }
    /** @return array<string,mixed> */
    public function getPayload(): array { return []; }
    public function getChecksum(): string { return str_repeat('b', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000005'; }
    public function getCausationId(): ?string { return null; }
}
