<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Adapters\VariationAdapter;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Handlers\VariationTombstoneHandler;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;
use HSP\Modules\Commerce\Queries\VariationFilterSet;
use HSP\Modules\Commerce\Queries\VariationQueryProvider;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\TermValidator;
use HSP\Modules\Commerce\Validation\VariationValidator;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\TestCase;

/**
 * P2-S5 — commerce.product_variations against live PostgreSQL.
 *
 * The properties under test are the ones AG-7 buys and can lose. A variation is an
 * independently synchronised aggregate that references two things it does not own — its parent
 * product and its selected attribute terms — and under at-least-once, non-FIFO delivery either
 * can arrive after it. Every reference is therefore a SOURCE id rather than a projection uuid,
 * and the tests below are mostly about proving that choice actually holds up in the orders a
 * real pipeline produces.
 *
 * Schema comes from the REAL migration files, so this also proves migration 0006 and its
 * indexes.
 */
final class VariationIntegrationTest extends TestCase
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

    private function variationHandler(): VariationUpsertHandler
    {
        return new VariationUpsertHandler(
            $this->loader,
            new VariationExtractor(new VariationValidator()),
            new VariationTransformer(),
            new VariationAdapter($this->db),
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

    private function termHandler(): TermUpsertHandler
    {
        return new TermUpsertHandler(
            $this->loader,
            new TermExtractor(new TermValidator()),
            new TermTransformer(),
            new TermAdapter($this->db),
        );
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @param array<string,string> $attributes @param list<int> $termIds */
    private function givenVariation(
        int $id,
        int $parentId,
        array $attributes = [],
        array $termIds = [],
        int $version = 1,
        string $price = '19.99',
        int $menuOrder = 0,
    ): void {
        $this->loader->variations[$id] = [
            'id'                 => $id,
            'parent_id'          => $parentId,
            'sku'                => "SKU-{$id}",
            'name'               => "Variation {$id}",
            'description'        => '',
            'status'             => 'publish',
            'price'              => $price,
            'regular_price'      => $price,
            'sale_price'         => null,
            'featured_media_id'  => 0,
            'menu_order'         => $menuOrder,
            'attributes'         => $attributes,
            'attribute_term_ids' => $termIds,
            'modified_at'        => '2026-01-01 00:00:00',
        ];

        $this->variationHandler()->handle($this->event($id, 'updated', $version));
    }

    private function givenParent(int $id, string $slug, string $type = 'variable'): void
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

    private function projectParent(int $id): void
    {
        $this->productHandler()->handle(
            new VariationIntegrationEvent('commerce.product.updated', 'product', (string) $id, 1, "p{$id}")
        );
    }

    private function givenAttributeTerm(int $termId, string $slug, string $taxonomy): void
    {
        $this->loader->terms[$termId] = [
            'term_id' => $termId, 'taxonomy' => $taxonomy, 'slug' => $slug,
            'name' => ucfirst($slug), 'description' => '', 'parent' => 0, 'count' => 0,
        ];

        $this->termHandler()->handle(
            new VariationIntegrationEvent(
                'commerce.attribute_term.created',
                'attribute_term',
                (string) $termId,
                1,
                "t{$termId}",
            )
        );
    }

    private function event(int $id, string $action, int $version): EventInterface
    {
        return new VariationIntegrationEvent(
            "commerce.product_variation.{$action}",
            'product_variation',
            (string) $id,
            $version,
            "v{$id}-{$action}-{$version}",
        );
    }

    /** @return array<string,mixed>|null */
    private function fetch(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM commerce.product_variations WHERE source_variation_id = $1',
            [$id],
        );

        return $rows[0] ?? null;
    }

    // =========================================================================
    // Projection
    // =========================================================================

    public function test_a_variation_projects_end_to_end(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        $row = $this->fetch(77);

        self::assertNotNull($row);
        self::assertSame(42, (int) $row['source_parent_id']);
        self::assertSame('SKU-77', $row['sku']);
        self::assertSame('19.99', $row['price']);
        self::assertNull($row['deleted_at']);
        self::assertSame(['pa_colour' => 'blue'], json_decode((string) $row['attributes'], true));
    }

    /**
     * The "any" entry survives the round trip through JSONB.
     *
     * PostgreSQL is happy to store an empty string in a JSON object; the risk was never the
     * database but every layer above deciding an empty value was noise worth dropping.
     */
    public function test_an_any_selection_survives_the_round_trip(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue', 'pa_size' => ''], [40]);

        $attributes = json_decode((string) $this->fetch(77)['attributes'], true);

        self::assertArrayHasKey('pa_size', $attributes);
        self::assertSame('', $attributes['pa_size']);
    }

    public function test_an_unchanged_variation_is_write_suppressed(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        $first = $this->fetch(77);

        $this->variationHandler()->handle($this->event(77, 'updated', 2));

        $second = $this->fetch(77);

        self::assertSame($first['synced_at'], $second['synced_at']);
        // The event is still recorded and the watermark still advances (Rule 4).
        self::assertSame(2, $this->latestProcessedVersion('product_variation', '77'));
    }

    public function test_a_changed_selection_reaches_the_projection(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        $this->givenVariation(77, 42, ['pa_colour' => 'red'], [42], version: 2);

        self::assertSame(
            ['pa_colour' => 'red'],
            json_decode((string) $this->fetch(77)['attributes'], true),
        );
    }

    public function test_a_stale_event_cannot_overwrite_a_newer_projection(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40], version: 5);

        // A redelivered older event arriving after the newer one.
        $this->givenVariation(77, 42, ['pa_colour' => 'red'], [42], version: 2);

        self::assertSame(
            ['pa_colour' => 'blue'],
            json_decode((string) $this->fetch(77)['attributes'], true),
        );
        self::assertSame(5, $this->latestProcessedVersion('product_variation', '77'));
    }

    // =========================================================================
    // Soft references (AG-7)
    // =========================================================================

    /**
     * A variation may project BEFORE its parent — the ordinary case, not an edge case.
     *
     * With a foreign key this insert would fail and dead-letter. With a source-id reference the
     * row is complete on arrival and the parent simply joins later.
     */
    public function test_a_variation_projects_before_its_parent_exists(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM commerce.products'));
        self::assertNotNull($this->fetch(77));

        // The parent arrives; nothing about the variation needs rewriting.
        $this->projectParent(42);

        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM commerce.products'));
        self::assertCount(1, $this->listFor(42));
    }

    /**
     * The same for attribute terms: the link exists before the term does, and becomes readable
     * the moment the term lands — with no reprojection of the variation, which could not happen
     * anyway because its checksum has not moved.
     */
    public function test_a_link_survives_a_term_that_has_not_projected_yet(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        self::assertSame([40], $this->linkedTermIds(77));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM commerce.taxonomies'));

        $this->givenAttributeTerm(40, 'blue', 'pa_colour');

        self::assertSame(1, $this->scalar(
            "SELECT COUNT(*) FROM commerce.entity_taxonomies et
             JOIN commerce.taxonomies t ON t.source_term_id = et.source_term_id
             WHERE t.taxonomy_type = 'pa_colour'"
        ));
    }

    /**
     * A variation's links must not disturb its PARENT's links.
     *
     * Both live in commerce.entity_taxonomies, and the rewrite is a DELETE-by-entity followed by
     * an INSERT. If entity_id were anything shared between a product and its variations — the
     * source product id, say — projecting one variation would wipe the parent's categories, and
     * the parent could not repair them because its own checksum has not moved.
     */
    public function test_a_variation_rewrite_leaves_the_parents_links_alone(): void
    {
        $this->givenParent(42, 'shirt');
        $this->loader->products[42]['attribute_term_ids'] = [40];
        $this->projectParent(42);

        $parentLinks = $this->scalar('SELECT COUNT(*) FROM commerce.entity_taxonomies');
        self::assertSame(1, $parentLinks);

        $this->givenVariation(77, 42, ['pa_colour' => 'red'], [42]);

        // Two rows now: the parent's and the variation's, each under its own entity id.
        self::assertSame(2, $this->scalar('SELECT COUNT(*) FROM commerce.entity_taxonomies'));
        self::assertSame([42], $this->linkedTermIds(77));
    }

    // =========================================================================
    // Lifecycle (DECISION I / T / U)
    // =========================================================================

    public function test_a_deleted_variation_is_tombstoned_and_leaves_the_listing(): void
    {
        $this->givenParent(42, 'shirt');
        $this->projectParent(42);
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        self::assertCount(1, $this->listFor(42));

        (new VariationTombstoneHandler(new VariationAdapter($this->db)))
            ->handle($this->event(77, 'deleted', 2));

        self::assertNotNull($this->fetch(77)['deleted_at'], 'soft delete, not a hard delete');
        self::assertCount(0, $this->listFor(42));
    }

    /**
     * Re-emission revives a tombstoned variation.
     *
     * Tombstoning leaves the checksum alone, so without the adapter's deleted_at clause the
     * revival would be write-suppressed forever while reconciliation detected the drift on every
     * pass and repaired it by a re-emission that is suppressed in turn.
     */
    public function test_re_emission_revives_a_tombstoned_variation(): void
    {
        $this->givenParent(42, 'shirt');
        $this->projectParent(42);
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        (new VariationTombstoneHandler(new VariationAdapter($this->db)))
            ->handle($this->event(77, 'deleted', 2));

        $this->variationHandler()->handle($this->event(77, 'updated', 3));

        self::assertNull($this->fetch(77)['deleted_at']);
        self::assertCount(1, $this->listFor(42));
    }

    /**
     * A parent leaving supported scope takes its variations with it (AG-13).
     *
     * Reached through the loader, which declines to return a variation whose parent is out of
     * scope — so the handler has no product-type branch and there is no variation-specific
     * repair path.
     */
    public function test_a_variation_is_tombstoned_when_its_parent_leaves_scope(): void
    {
        $this->givenParent(42, 'shirt');
        $this->projectParent(42);
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);
        self::assertNull($this->fetch(77)['deleted_at']);

        $this->loader->products[42]['product_type'] = 'grouped';

        $this->variationHandler()->handle($this->event(77, 'updated', 2));

        self::assertNotNull($this->fetch(77)['deleted_at']);
    }

    /**
     * The reverse transition: a variation of an out-of-scope parent projects nothing at all, and
     * then appears normally once the parent enters scope.
     *
     * Note what "nothing at all" means — not a tombstoned row, no row. The tombstone the handler
     * issues on the way in finds nothing to soft-delete, which is the correct outcome and the
     * reason the handler can treat "gone" and "out of scope" identically: on a projection that
     * was never written, a tombstone is a harmless no-op.
     */
    public function test_a_variation_appears_when_its_parent_enters_scope(): void
    {
        $this->givenParent(42, 'shirt', 'grouped');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        self::assertNull($this->fetch(77), 'an out-of-scope parent projects no variation at all');

        $this->loader->products[42]['product_type'] = 'variable';
        $this->variationHandler()->handle($this->event(77, 'updated', 2));

        $row = $this->fetch(77);
        self::assertNotNull($row);
        self::assertNull($row['deleted_at']);
    }

    // =========================================================================
    // Delivery
    // =========================================================================

    public function test_the_listing_is_scoped_to_one_parent_and_ordered_by_menu_order(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenParent(43, 'mug');

        $this->givenVariation(79, 42, ['pa_size' => 'large'], [], menuOrder: 2);
        $this->givenVariation(78, 42, ['pa_size' => 'small'], [], menuOrder: 1);
        $this->givenVariation(80, 43, ['pa_size' => 'small'], [], menuOrder: 1);

        $ids = array_map(
            static fn (array $r): int => (int) $r['source_variation_id'],
            $this->listFor(42),
        );

        self::assertSame([78, 79], $ids, 'this parent only, in the store\'s own order');
    }

    public function test_the_listing_refuses_an_unscoped_query(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new VariationQueryProvider($this->db))->list(new VariationFilterSet());
    }

    public function test_the_provider_rejects_another_domains_filter(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new VariationQueryProvider($this->db))->list(
            new \HSP\Modules\Commerce\Queries\ProductFilterSet()
        );
    }

    public function test_a_variation_is_addressable_by_its_source_id(): void
    {
        $this->givenParent(42, 'shirt');
        $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40]);

        $row = (new VariationQueryProvider($this->db))->findBySlug('77');

        self::assertNotNull($row);
        self::assertSame(77, (int) $row['source_variation_id']);
        self::assertNull((new VariationQueryProvider($this->db))->findBySlug('not-a-number'));
    }

    // =========================================================================
    // Migration + index shape (performance DoD)
    // =========================================================================

    /**
     * The dominant read — every variation of one product — must be index-backed at catalogue
     * scale, and must not degrade into a scan of every variation in the store.
     *
     * Seeded at a realistic size deliberately: against a handful of rows a sequential scan IS
     * the cheapest plan, so an EXPLAIN assertion on a small fixture passes for the wrong reason
     * and proves nothing. That is the P1B-S1/S2/S3 lesson.
     */
    public function test_the_variation_listing_is_index_backed(): void
    {
        $this->seedAtScale(200, 20);
        pg_query($this->pgConn, 'ANALYZE commerce.product_variations');

        $plan = $this->explain(
            'SELECT id FROM commerce.product_variations
             WHERE deleted_at IS NULL AND source_parent_id = 100
             ORDER BY menu_order ASC, id ASC
             LIMIT 50'
        );

        self::assertStringNotContainsString(
            'Seq Scan on product_variations',
            $plan,
            'a product page must not scan every variation in the store',
        );
    }

    public function test_source_identity_is_unique_so_redelivery_cannot_duplicate_a_row(): void
    {
        $this->givenParent(42, 'shirt');

        foreach ([1, 2, 3] as $version) {
            $this->givenVariation(77, 42, ['pa_colour' => 'blue'], [40], version: $version);
        }

        self::assertSame(
            1,
            $this->scalar('SELECT COUNT(*) FROM commerce.product_variations WHERE source_variation_id = 77'),
        );
    }

    /**
     * SKU is INDEXED, not UNIQUE — a constraint would turn an unusual but real source state into
     * a projection failure that dead-letters (AG-7).
     */
    public function test_the_sku_index_is_not_a_unique_constraint(): void
    {
        $unique = $this->db->query(
            "SELECT indexname FROM pg_indexes
             WHERE schemaname = 'commerce' AND tablename = 'product_variations'
               AND indexdef LIKE '%UNIQUE%' AND indexname LIKE '%sku%'"
        );

        self::assertSame([], $unique);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<int, array<string,mixed>> */
    private function listFor(int $parentSourceId): array
    {
        return (new VariationQueryProvider($this->db))
            ->list(new VariationFilterSet(parentSourceId: $parentSourceId))
            ->rows;
    }

    /** @return list<int> */
    private function linkedTermIds(int $variationId): array
    {
        $rows = $this->db->query(
            'SELECT et.source_term_id
             FROM commerce.entity_taxonomies et
             JOIN commerce.product_variations v ON v.id = et.entity_id
             WHERE v.source_variation_id = $1
             ORDER BY et.source_term_id',
            [$variationId],
        );

        return array_map(static fn (array $r): int => (int) $r['source_term_id'], $rows);
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
    private function seedAtScale(int $parents, int $perParent): void
    {
        $values = [];

        for ($p = 0; $p < $parents; $p++) {
            $parentId = 100 + $p;

            for ($v = 0; $v < $perParent; $v++) {
                $n = $p * $perParent + $v;
                $values[] = sprintf(
                    "(gen_random_uuid(), %d, %d, 'SKU-%d', 'V%d', 'publish', %d, '{}'::jsonb, '%s')",
                    900000 + $n,
                    $parentId,
                    $n,
                    $n,
                    $v,
                    str_repeat('a', 64),
                );
            }
        }

        foreach (array_chunk($values, 500) as $chunk) {
            pg_query($this->pgConn, '
                INSERT INTO commerce.product_variations
                    (id, source_variation_id, source_parent_id, sku, name, status, menu_order,
                     attributes, checksum)
                VALUES ' . implode(',', $chunk));
        }
    }

    private function createSchema(): void
    {
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

        $dir = \dirname(__DIR__, 3) . '/modules/Commerce/Migrations';

        foreach ([
            '0001_create_commerce_schema.sql',
            '0002_create_commerce_products.sql',
            '0003_create_commerce_taxonomies.sql',
            '0004_create_commerce_entity_taxonomies.sql',
            '0006_create_commerce_product_variations.sql',
        ] as $file) {
            $sql = file_get_contents($dir . '/' . $file);
            self::assertIsString($sql, "missing migration {$file}");
            pg_query($this->pgConn, $sql);
        }
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

/** Event double carrying an explicit aggregate type, so one class serves every aggregate here. */
final class VariationIntegrationEvent implements EventInterface
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
    public function getChecksum(): string { return str_repeat('f', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000004'; }
    public function getCausationId(): ?string { return null; }
}
