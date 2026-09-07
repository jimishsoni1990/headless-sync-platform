<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\AttributeAdapter;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Extractors\AttributeExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Handlers\AttributeTombstoneHandler;
use HSP\Modules\Commerce\Handlers\AttributeUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Queries\AttributeQueryProvider;
use HSP\Modules\Commerce\Queries\TermFilterSet;
use HSP\Modules\Commerce\Queries\TermQueryProvider;
use HSP\Modules\Commerce\Transformers\AttributeTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Validation\AttributeValidator;
use HSP\Modules\Commerce\Validation\TermValidator;
use PHPUnit\Framework\TestCase;

/**
 * P2-S4 — attribute definitions and `pa_*` terms against live PostgreSQL.
 *
 * Two projections, deliberately different in kind (AG-9):
 *
 *   commerce.attributes   one aggregate per table, no discriminator
 *   commerce.taxonomies   SHARED with product categories, discriminated by a PREFIX
 *
 * The prefix is the new thing and the risky one. Every previous discriminated read compared
 * against a fixed value the module owned; `pa_colour` is defined by the store operator, so the
 * set cannot be enumerated anywhere in the platform. That buys flexibility and costs a
 * guarantee: nothing stops a read from matching more than it owns unless the predicate is
 * anchored, which is what these tests hold in place.
 *
 * Schema comes from the REAL migration files, so this also proves migration 0005 and the index
 * shapes it declares.
 */
final class AttributeIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private AttributeSourceStub $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new AttributeSourceStub();

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

    private function attributeUpsert(): AttributeUpsertHandler
    {
        return new AttributeUpsertHandler(
            $this->loader,
            new AttributeExtractor(new AttributeValidator()),
            new AttributeTransformer(),
            new AttributeAdapter($this->db),
        );
    }

    private function attributeTombstone(): AttributeTombstoneHandler
    {
        return new AttributeTombstoneHandler(new AttributeAdapter($this->db));
    }

    private function termUpsert(): TermUpsertHandler
    {
        return new TermUpsertHandler(
            $this->loader,
            new TermExtractor(new TermValidator()),
            new TermTransformer(),
            new TermAdapter($this->db),
        );
    }

    private function termTombstone(): TermTombstoneHandler
    {
        return new TermTombstoneHandler(new TermAdapter($this->db));
    }

    /** @return array<string,mixed> */
    private function attribute(int $id, string $slug, string $name, string $type = 'select'): array
    {
        return [
            'id'           => $id,
            'slug'         => $slug,
            'name'         => $name,
            'type'         => $type,
            'order_by'     => 'menu_order',
            'has_archives' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function term(int $id, string $slug, string $taxonomy): array
    {
        return [
            'term_id'     => $id,
            'taxonomy'    => $taxonomy,
            'slug'        => $slug,
            'name'        => ucfirst($slug),
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        ];
    }

    private function attributeEvent(int $id, string $action, int $version, string $key): EventInterface
    {
        return new AttributeIntegrationEvent(
            "commerce.attribute.{$action}",
            'attribute',
            (string) $id,
            $version,
            $key,
        );
    }

    private function termEvent(int $id, string $action, int $version, string $key): EventInterface
    {
        return new AttributeIntegrationEvent(
            "commerce.attribute_term.{$action}",
            'attribute_term',
            (string) $id,
            $version,
            $key,
        );
    }

    /** @return array<string,mixed>|null */
    private function fetchAttribute(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM commerce.attributes WHERE source_attribute_id = $1',
            [$id],
        );

        return $rows[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    private function fetchTerm(int $id): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM commerce.taxonomies WHERE source_term_id = $1',
            [$id],
        );

        return $rows[0] ?? null;
    }

    // =========================================================================
    // Attribute definitions — the full lifecycle
    // =========================================================================

    public function test_a_definition_projects_end_to_end(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');

        $this->attributeUpsert()->handle($this->attributeEvent(3, 'created', 1, 'a1'));

        $row = $this->fetchAttribute(3);

        self::assertNotNull($row);
        self::assertSame('pa_colour', $row['slug']);
        self::assertSame('Colour', $row['name']);
        self::assertSame('select', $row['type']);
        self::assertNull($row['deleted_at']);
    }

    public function test_an_update_lands_on_current_state(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'created', 1, 'a1'));

        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colours', 'button');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'updated', 2, 'a2'));

        $row = $this->fetchAttribute(3);
        self::assertSame('Colours', $row['name']);
        self::assertSame('button', $row['type']);
    }

    /**
     * Renaming an attribute renames its taxonomy, and the projection must follow.
     *
     * Verified against WooCommerce 11.1.0: `woocommerce_attribute_updated` carries the OLD slug
     * precisely because the rename moves the taxonomy. Reloading current state (ADR-044) means
     * the projection lands on the new name — but only if `slug` is in the checksum, or DECISION 3
     * suppresses the write and consumers keep joining on a taxonomy that no longer exists.
     */
    public function test_a_taxonomy_rename_reaches_the_projection(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'created', 1, 'a1'));

        $this->loader->attributes[3] = $this->attribute(3, 'pa_color', 'Color');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'updated', 2, 'a2'));

        self::assertSame('pa_color', $this->fetchAttribute(3)['slug']);
    }

    public function test_an_unchanged_definition_is_write_suppressed(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'created', 1, 'a1'));

        $first = $this->fetchAttribute(3);

        $this->attributeUpsert()->handle($this->attributeEvent(3, 'updated', 2, 'a2'));

        $second = $this->fetchAttribute(3);

        // The projection row is untouched (DECISION 3 write suppression) …
        self::assertSame($first['updated_at'], $second['updated_at']);
        // … but the event is still recorded as processed and the watermark still advances,
        // which is what makes redelivery safe (Rule 4).
        self::assertSame(2, $this->latestProcessedVersion('attribute', '3'));
    }

    public function test_a_deleted_definition_is_tombstoned_not_removed(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'created', 1, 'a1'));

        $this->attributeTombstone()->handle($this->attributeEvent(3, 'deleted', 2, 'a3'));

        $row = $this->fetchAttribute(3);

        self::assertNotNull($row, 'DECISION I: the row stays, carrying deleted_at');
        self::assertNotNull($row['deleted_at']);
        self::assertNull((new AttributeQueryProvider($this->db))->findBySlug('pa_colour'));
    }

    /**
     * A tombstoned definition comes back when the source does — the replay/reconciliation path.
     *
     * WooCommerce genuinely allows an attribute to be deleted and re-created with the same
     * taxonomy, and re-emission is the only repair mechanism (DECISION T/U). If the upsert did
     * not clear `deleted_at` the projection would stay invisible forever with no way to fix it
     * short of direct SQL, which is exactly the second repair path the rulings forbid.
     */
    public function test_re_emission_revives_a_tombstoned_definition(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'created', 1, 'a1'));
        $this->attributeTombstone()->handle($this->attributeEvent(3, 'deleted', 2, 'a3'));

        $this->attributeUpsert()->handle($this->attributeEvent(3, 'updated', 3, 'a4'));

        self::assertNull($this->fetchAttribute(3)['deleted_at']);
        self::assertNotNull((new AttributeQueryProvider($this->db))->findBySlug('pa_colour'));
    }

    public function test_a_stale_event_cannot_overwrite_a_newer_projection(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'updated', 5, 'a5'));

        // A redelivered older event arrives after the newer one (at-least-once, non-FIFO).
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Old Name');
        $this->attributeUpsert()->handle($this->attributeEvent(3, 'updated', 2, 'a6'));

        self::assertSame('Colour', $this->fetchAttribute(3)['name']);
        self::assertSame(5, $this->latestProcessedVersion('attribute', '3'));
    }

    // =========================================================================
    // Attribute terms — the shared, prefix-discriminated projection
    // =========================================================================

    public function test_a_pa_term_projects_with_its_own_taxonomy_as_the_discriminator(): void
    {
        $this->loader->terms[40] = $this->term(40, 'blue', 'pa_colour');

        $this->termUpsert()->handle($this->termEvent(40, 'created', 1, 't1'));

        $row = $this->fetchTerm(40);

        self::assertNotNull($row);
        self::assertSame('blue', $row['slug']);
        // NOT 'attribute_term' and NOT 'pa_' — the row carries the concrete taxonomy, which is
        // what lets one attribute's terms be read without the others.
        self::assertSame('pa_colour', $row['taxonomy_type']);
    }

    /**
     * The same slug in two attributes is two different terms.
     *
     * WordPress enforces slug uniqueness only within a taxonomy, so `pa_colour/blue` and
     * `pa_finish/blue` legitimately coexist. Anything keyed on the bare slug would collapse them.
     */
    public function test_the_same_slug_in_two_attributes_stays_two_terms(): void
    {
        $this->loader->terms[40] = $this->term(40, 'blue', 'pa_colour');
        $this->loader->terms[41] = $this->term(41, 'blue', 'pa_finish');

        $this->termUpsert()->handle($this->termEvent(40, 'created', 1, 't1'));
        $this->termUpsert()->handle($this->termEvent(41, 'created', 1, 't2'));

        self::assertSame('pa_colour', $this->fetchTerm(40)['taxonomy_type']);
        self::assertSame('pa_finish', $this->fetchTerm(41)['taxonomy_type']);

        $colour = new TermQueryProvider($this->db, 'pa_colour');
        self::assertSame(40, (int) $colour->findBySlug('blue')['source_term_id']);

        $finish = new TermQueryProvider($this->db, 'pa_finish');
        self::assertSame(41, (int) $finish->findBySlug('blue')['source_term_id']);
    }

    /**
     * Categories and attribute terms share one table and must not see each other.
     *
     * This is the assertion DECISION AA exists for, now with a third occupant in the table. A
     * category listing that returned attribute terms would look like working code — the rows are
     * structurally identical — and would publish `pa_colour/blue` as a product category.
     */
    public function test_categories_and_attribute_terms_do_not_leak_into_each_other(): void
    {
        $this->loader->terms[10] = $this->term(10, 'clothing', 'product_cat');
        $this->loader->terms[40] = $this->term(40, 'blue', 'pa_colour');
        $this->loader->terms[41] = $this->term(41, 'large', 'pa_size');

        foreach ([10, 40, 41] as $id) {
            $this->termUpsert()->handle($this->termEvent($id, 'created', 1, "t{$id}"));
        }

        $categories = (new TermQueryProvider($this->db, 'product_cat'))->list(new TermFilterSet());
        self::assertCount(1, $categories->rows);
        self::assertSame('clothing', $categories->rows[0]['slug']);

        $colours = (new TermQueryProvider($this->db, 'pa_colour'))->list(new TermFilterSet());
        self::assertCount(1, $colours->rows);
        self::assertSame('blue', $colours->rows[0]['slug']);

        self::assertNull((new TermQueryProvider($this->db, 'product_cat'))->findBySlug('blue'));
        self::assertNull((new TermQueryProvider($this->db, 'pa_colour'))->findBySlug('clothing'));
    }

    public function test_an_attribute_term_lifecycle_completes_through_tombstone_and_revival(): void
    {
        $this->loader->terms[40] = $this->term(40, 'blue', 'pa_colour');
        $this->termUpsert()->handle($this->termEvent(40, 'created', 1, 't1'));

        $this->loader->terms[40] = $this->term(40, 'navy-blue', 'pa_colour');
        $this->termUpsert()->handle($this->termEvent(40, 'updated', 2, 't2'));
        self::assertSame('navy-blue', $this->fetchTerm(40)['slug']);

        $this->termTombstone()->handle($this->termEvent(40, 'deleted', 3, 't3'));
        self::assertNotNull($this->fetchTerm(40)['deleted_at']);
        self::assertNull((new TermQueryProvider($this->db, 'pa_colour'))->findBySlug('navy-blue'));

        $this->termUpsert()->handle($this->termEvent(40, 'updated', 4, 't4'));
        self::assertNull($this->fetchTerm(40)['deleted_at']);
    }

    // =========================================================================
    // The prefix predicate, at the SQL level
    // =========================================================================

    /**
     * The reconciliation scope for `attribute_term` must claim every `pa_*` row and nothing else.
     *
     * This is what the prefix descriptor buys and what it risks. The orphan sweep lists rows by
     * this predicate and tombstones the ones WordPress no longer has — so a predicate that
     * matched `product_cat` too would tombstone live product categories on the next
     * reconciliation pass. That failure has no error message; the categories simply vanish.
     */
    public function test_the_prefix_scope_claims_every_pa_taxonomy_and_no_others(): void
    {
        $this->loader->terms[10] = $this->term(10, 'clothing', 'product_cat');
        $this->loader->terms[40] = $this->term(40, 'blue', 'pa_colour');
        $this->loader->terms[41] = $this->term(41, 'large', 'pa_size');

        foreach ([10, 40, 41] as $id) {
            $this->termUpsert()->handle($this->termEvent($id, 'created', 1, "t{$id}"));
        }

        $rows = $this->db->query(
            "SELECT source_term_id FROM commerce.taxonomies
             WHERE deleted_at IS NULL AND taxonomy_type LIKE 'pa_%'
             ORDER BY source_term_id"
        );

        self::assertSame([40, 41], array_map(static fn (array $r): int => (int) $r['source_term_id'], $rows));
    }

    // =========================================================================
    // Migration + index shape (performance DoD)
    // =========================================================================

    /**
     * The reads this session ships must be index-backed at catalogue scale.
     *
     * Asserted as index EXISTENCE rather than through EXPLAIN: against the handful of rows a
     * test inserts, a sequential scan genuinely is the cheapest plan, so an EXPLAIN assertion
     * here would either pass for the wrong reason or force an artificial data volume into every
     * run. The P1B-S1/S2/S3 lesson is that the latter proves nothing.
     */
    public function test_the_attribute_reads_are_index_backed(): void
    {
        $indexes = array_map(
            static fn (array $r): string => (string) $r['indexname'],
            $this->db->query(
                "SELECT indexname FROM pg_indexes WHERE schemaname = 'commerce' AND tablename = 'attributes'"
            ),
        );

        // Slug lookup drives /product-attributes/{taxonomy}; (name, id) drives the keyset listing.
        self::assertContains('idx_commerce_attributes_slug', $indexes);
        self::assertContains('idx_commerce_attributes_name', $indexes);
    }

    /**
     * Slug is INDEXED, not UNIQUE.
     *
     * WooCommerce enforces uniqueness at write time, but a constraint here would turn an unusual
     * source state into a projection failure that dead-letters — and a DLQ entry is a worse
     * outcome than a duplicate row a reconciliation pass can resolve (AG-7).
     */
    public function test_the_attribute_slug_index_is_not_a_unique_constraint(): void
    {
        $unique = $this->db->query(
            "SELECT indexname FROM pg_indexes
             WHERE schemaname = 'commerce' AND tablename = 'attributes'
               AND indexdef LIKE '%UNIQUE%' AND indexname LIKE '%slug%'"
        );

        self::assertSame([], $unique);
    }

    public function test_source_identity_is_unique_so_redelivery_cannot_duplicate_a_row(): void
    {
        $this->loader->attributes[3] = $this->attribute(3, 'pa_colour', 'Colour');

        // Same aggregate, three different events — at-least-once redelivery (Rule 4).
        foreach ([1, 2, 3] as $i) {
            $this->attributeUpsert()->handle($this->attributeEvent(3, 'updated', $i, "dup{$i}"));
        }

        $rows = $this->db->query('SELECT id FROM commerce.attributes WHERE source_attribute_id = 3');

        self::assertCount(1, $rows);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function latestProcessedVersion(string $type, string $id): int
    {
        $rows = $this->db->query(
            'SELECT latest_processed_version FROM system.aggregate_versions
             WHERE aggregate_type = $1 AND aggregate_id = $2',
            [$type, $id],
        );

        return (int) ($rows[0]['latest_processed_version'] ?? 0);
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
            '0003_create_commerce_taxonomies.sql',
            '0004_create_commerce_entity_taxonomies.sql',
            '0005_create_commerce_attributes.sql',
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

/** In-memory attribute + term source. Implements only what these handlers reach. */
/** In-memory attribute + term source. */
final class AttributeSourceStub extends \HSP\Tests\Support\InMemoryCommerceLoader
{
}

/** Event double carrying an explicit aggregate type, so one class serves both aggregates. */
final class AttributeIntegrationEvent implements EventInterface
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
    public function getChecksum(): string { return str_repeat('e', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000003'; }
    public function getCausationId(): ?string { return null; }
}
