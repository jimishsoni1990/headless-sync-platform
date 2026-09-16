<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Queries\TermFilterSet;
use HSP\Modules\Commerce\Queries\TermQueryProvider;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Validation\TermValidator;
use PHPUnit\Framework\TestCase;

/**
 * P2-S3 — commerce.taxonomies against live PostgreSQL.
 *
 * The property under test is the one a SHARED, discriminated projection buys and can lose: a
 * read that forgets its taxonomy predicate silently returns another taxonomy's rows, which
 * looks exactly like working code. DECISION AA had to fix three separate instances of that in
 * Content; these assertions exist so Commerce does not repeat them.
 *
 * Schema comes from the REAL migration files, so these tests also prove the migration and its
 * index shapes.
 */
final class ProductCategoryIntegrationTest extends TestCase
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

    private function handler(): TermUpsertHandler
    {
        return new TermUpsertHandler(
            $this->loader,
            new TermExtractor(new TermValidator()),
            new TermTransformer(),
            new TermAdapter($this->db),
        );
    }

    private function provider(string $taxonomy = 'product_cat'): TermQueryProvider
    {
        return new TermQueryProvider($this->db, $taxonomy);
    }

    /** @return array<string,mixed> */
    private function term(int $id, string $slug, string $taxonomy = 'product_cat', int $parent = 0): array
    {
        return [
            'term_id'     => $id,
            'taxonomy'    => $taxonomy,
            'slug'        => $slug,
            'name'        => ucfirst(str_replace('-', ' ', $slug)),
            'description' => '',
            'parent'      => $parent,
            'count'       => 0,
        ];
    }

    private function event(int $id, string $type, int $version, string $key): \HSP\Core\Contracts\EventInterface
    {
        return new IntegrationTermEvent($type, (string) $id, $version, $key);
    }

    // =========================================================================
    // Projection
    // =========================================================================

    public function test_a_product_category_projects_end_to_end(): void
    {
        $this->loader->terms[10] = $this->term(10, 'clothing');

        $this->handler()->handle($this->event(10, 'commerce.product_category.created', 1, 'e1'));

        $row = $this->fetch(10);

        self::assertNotNull($row);
        self::assertSame('clothing', $row['slug']);
        self::assertSame('product_cat', $row['taxonomy_type']);
        self::assertNull($row['deleted_at']);
    }

    public function test_the_hierarchy_is_projected_so_a_consumer_can_rebuild_the_tree(): void
    {
        $this->loader->terms[10] = $this->term(10, 'clothing');
        $this->loader->terms[11] = $this->term(11, 'men', parent: 10);

        $this->handler()->handle($this->event(10, 'commerce.product_category.created', 1, 'e1'));
        $this->handler()->handle($this->event(11, 'commerce.product_category.created', 1, 'e2'));

        self::assertSame(0, (int) $this->fetch(10)['parent_id']);
        self::assertSame(10, (int) $this->fetch(11)['parent_id']);
    }

    /**
     * AG-7: a child term may legitimately project BEFORE its parent under at-least-once,
     * non-FIFO delivery. With a foreign key that would dead-letter; with a soft reference it
     * simply resolves once the parent lands.
     */
    public function test_a_child_term_may_project_before_its_parent(): void
    {
        $this->loader->terms[11] = $this->term(11, 'men', parent: 10);

        $this->handler()->handle($this->event(11, 'commerce.product_category.created', 1, 'e2'));

        self::assertSame(10, (int) $this->fetch(11)['parent_id'], 'a dangling parent reference is valid');

        $this->loader->terms[10] = $this->term(10, 'clothing');
        $this->handler()->handle($this->event(10, 'commerce.product_category.created', 1, 'e1'));

        self::assertNotNull($this->fetch(10));
    }

    // =========================================================================
    // Public hierarchy — parent_slug resolved at read time (FLAG-COMMCATPARENT-1)
    // =========================================================================

    /** The tree rebuilds from the listing alone: every depth is listed, each names its parent by slug. */
    public function test_the_listing_resolves_each_parent_by_slug_at_every_depth(): void
    {
        $this->project(10, 'clothing');
        $this->project(11, 'men', 10);
        $this->project(12, 'shirts-men', 11);

        self::assertSame(
            ['clothing' => null, 'men' => 'clothing', 'shirts-men' => 'men'],
            array_column($this->provider()->list(new TermFilterSet())->rows, 'parent_slug', 'slug'),
        );
        self::assertSame('men', $this->provider()->findBySlug('shirts-men')['parent_slug']);
    }

    /** AG-7: a child that projects first is listed with a null reference — never dropped — then converges. */
    public function test_a_child_before_its_parent_is_listed_with_a_null_parent_slug_until_it_lands(): void
    {
        $this->project(11, 'men', 10);

        $rows = $this->provider()->list(new TermFilterSet())->rows;
        self::assertSame(['men'], array_column($rows, 'slug'), 'the child is never dropped');
        self::assertNull($rows[0]['parent_slug']);

        $this->project(10, 'clothing');

        self::assertSame('clothing', $this->provider()->findBySlug('men')['parent_slug']);
    }

    public function test_a_tombstoned_parent_resolves_to_null_and_the_child_stays_listed(): void
    {
        $this->project(10, 'clothing');
        $this->project(11, 'men', 10);

        (new TermTombstoneHandler(new TermAdapter($this->db)))
            ->handle($this->event(10, 'commerce.product_category.deleted', 2, 'd10'));

        self::assertSame(['men'], array_column($this->provider()->list(new TermFilterSet())->rows, 'slug'));
        self::assertNull($this->provider()->findBySlug('men')['parent_slug']);
    }

    /** Read-time, not a stored copy: a parent's new slug shows the moment its own row updates. */
    public function test_a_parent_slug_change_is_visible_without_reprojecting_the_child(): void
    {
        $this->project(10, 'clothing');
        $this->project(11, 'men', 10);

        $this->loader->terms[10] = $this->term(10, 'apparel');
        $this->handler()->handle($this->event(10, 'commerce.product_category.updated', 2, 'u10'));

        self::assertSame('apparel', $this->provider()->findBySlug('men')['parent_slug']);
    }

    /** DECISION AA holds inside the join: a parent id naming another taxonomy's term resolves to nothing. */
    public function test_the_parent_join_never_crosses_taxonomies(): void
    {
        $this->project(20, 'blue', 0, 'pa_colour');
        $this->project(11, 'men', 20);

        self::assertNull($this->provider()->findBySlug('men')['parent_slug']);
    }

    public function test_reprocessing_an_unchanged_term_suppresses_the_write(): void
    {
        $this->loader->terms[10] = $this->term(10, 'clothing');

        $this->handler()->handle($this->event(10, 'commerce.product_category.created', 1, 'e1'));
        $first = $this->fetch(10);

        $this->handler()->handle($this->event(10, 'commerce.product_category.updated', 2, 'e2'));
        $second = $this->fetch(10);

        self::assertSame($first['checksum'], $second['checksum']);
        self::assertSame($first['synced_at'], $second['synced_at']);
    }

    public function test_deleting_a_term_soft_deletes_it(): void
    {
        $this->loader->terms[10] = $this->term(10, 'clothing');
        $this->handler()->handle($this->event(10, 'commerce.product_category.created', 1, 'e1'));

        (new TermTombstoneHandler(new TermAdapter($this->db)))
            ->handle($this->event(10, 'commerce.product_category.deleted', 2, 'e2'));

        self::assertNotNull($this->fetch(10)['deleted_at']);
        self::assertNull($this->provider()->findBySlug('clothing'));
    }

    // =========================================================================
    // The shared-table hazard
    // =========================================================================

    /**
     * The defect class DECISION AA exists for. WordPress guarantees slug uniqueness only
     * WITHIN a taxonomy, so a product category and an attribute term may both be `blue`. A read
     * that forgets its taxonomy predicate returns the wrong one — silently.
     */
    public function test_two_taxonomies_sharing_a_slug_resolve_to_different_terms(): void
    {
        $this->loader->terms[10] = $this->term(10, 'blue', 'product_cat');
        $this->loader->terms[20] = $this->term(20, 'blue', 'pa_colour');

        $this->handler()->handle($this->event(10, 'commerce.product_category.created', 1, 'e1'));
        $this->handler()->handle($this->event(20, 'commerce.product_category.created', 1, 'e2'));

        $category = $this->provider('product_cat')->findBySlug('blue');
        $colour   = $this->provider('pa_colour')->findBySlug('blue');

        self::assertNotNull($category);
        self::assertNotNull($colour);
        self::assertSame(10, (int) $category['source_term_id']);
        self::assertSame(20, (int) $colour['source_term_id'], 'the predicate must decide which row is returned');
    }

    public function test_a_listing_returns_only_its_own_taxonomy(): void
    {
        $this->loader->terms[10] = $this->term(10, 'clothing', 'product_cat');
        $this->loader->terms[20] = $this->term(20, 'red', 'pa_colour');

        $this->handler()->handle($this->event(10, 'commerce.product_category.created', 1, 'e1'));
        $this->handler()->handle($this->event(20, 'commerce.product_category.created', 1, 'e2'));

        $slugs = array_column($this->provider('product_cat')->list(new TermFilterSet())->rows, 'slug');

        self::assertSame(['clothing'], $slugs);
    }

    // =========================================================================
    // Pagination and index-backing
    // =========================================================================

    public function test_paging_terms_that_share_a_name_returns_each_exactly_once(): void
    {
        for ($id = 1; $id <= 25; $id++) {
            // Every term shares the SAME name, so only the id tiebreaker separates them —
            // the case a naive cursor pages wrongly.
            $this->loader->terms[$id] = [
                'term_id' => $id, 'taxonomy' => 'product_cat', 'slug' => "cat-{$id}",
                'name' => 'Same Name', 'description' => '', 'parent' => 0, 'count' => 0,
            ];
            $this->handler()->handle($this->event($id, 'commerce.product_category.created', 1, "e{$id}"));
        }

        $seen   = [];
        $cursor = null;

        do {
            $page = $this->provider()->list(new TermFilterSet(cursor: $cursor, limit: 7));

            foreach ($page->rows as $row) {
                $seen[] = $row['slug'];
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(25, $seen);
        self::assertSame(25, count(array_unique($seen)));
    }

    public function test_the_slug_lookup_and_listing_are_index_backed(): void
    {
        $this->seedTerms(2000);
        pg_query($this->pgConn, 'ANALYZE commerce.taxonomies');

        $slug = $this->explain(
            "SELECT id FROM commerce.taxonomies
             WHERE slug = 'term-1500' AND taxonomy_type = 'product_cat' AND deleted_at IS NULL LIMIT 1"
        );
        self::assertStringNotContainsString('Seq Scan on taxonomies', $slug, $slug);

        $listing = $this->explain(
            "SELECT id FROM commerce.taxonomies
             WHERE taxonomy_type = 'product_cat' AND deleted_at IS NULL
             ORDER BY name ASC, id ASC LIMIT 50"
        );
        self::assertStringNotContainsString('Seq Scan on taxonomies', $listing, $listing);
    }

    /**
     * The parent_slug self-join, planned from the provider's OWN SQL (captured, not retyped)
     * against 20 000 categories under 2 000 parents: index-backed on both sides, and one query
     * per call whether the page holds one row or two hundred — no per-row parent lookup.
     */
    public function test_the_parent_slug_join_is_index_backed_with_a_constant_query_count(): void
    {
        $this->seedHierarchy(20000, 2000);
        pg_query($this->pgConn, 'ANALYZE commerce.taxonomies');

        $recorder = new class ($this->db) implements DatabaseConnectionInterface {
            /** @var list<array{0:string,1:array<int,mixed>}> */
            public array $log = [];

            public function __construct(private readonly DatabaseConnectionInterface $inner)
            {
            }

            public function execute(string $sql, array $params = []): int
            {
                return $this->inner->execute($sql, $params);
            }

            public function query(string $sql, array $params = []): array
            {
                $this->log[] = [$sql, $params];

                return $this->inner->query($sql, $params);
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
        };
        $provider = new TermQueryProvider($recorder, 'product_cat');

        $one  = $provider->list(new TermFilterSet(limit: 1));
        $full = $provider->list(new TermFilterSet(limit: 200));
        $leaf = $provider->findBySlug('term-15000');

        self::assertCount(1, $one->rows);
        self::assertCount(200, $full->rows);
        self::assertCount(3, $recorder->log, 'one query per call, independent of page size');
        self::assertSame('term-1001', $leaf['parent_slug'] ?? null, '15000 % 2000 + 1');

        foreach ($recorder->log as [$sql, $params]) {
            $plan = $this->explain($sql, $params);

            self::assertStringNotContainsString('Seq Scan', $plan, $plan);
            self::assertStringContainsString('uq_commerce_taxonomies_source_id', $plan, $plan);
        }
    }

    /**
     * The migration deliberately ships no UNIQUE (taxonomy_type, slug): WordPress enforces slug
     * uniqueness at WRITE time and defeasibly, so a constraint here would turn an unusual but
     * valid source state into a projection failure that dead-letters (see the P2-S3 preflight
     * note). This asserts the schema actually reflects that decision.
     */
    public function test_the_projection_has_no_unique_constraint_on_taxonomy_type_and_slug(): void
    {
        $rows = $this->db->query(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = 'commerce' AND tablename = 'taxonomies'"
        );

        foreach ($rows as $row) {
            $def = (string) $row['indexdef'];

            if (str_contains($def, 'taxonomy_type') && str_contains($def, 'slug')) {
                self::assertStringNotContainsString(
                    'UNIQUE',
                    $def,
                    'a unique (taxonomy_type, slug) would dead-letter a legitimately duplicated slug',
                );
            }
        }

        self::assertNotSame([], $rows);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return array<string,mixed>|null */
    private function fetch(int $termId): ?array
    {
        $rows = $this->db->query(
            'SELECT * FROM commerce.taxonomies WHERE source_term_id = $1',
            [$termId],
        );

        return $rows[0] ?? null;
    }

    /** @param array<int,mixed> $params */
    private function explain(string $sql, array $params = []): string
    {
        $result = pg_query_params($this->pgConn, 'EXPLAIN ' . $sql, $params);
        $plan   = '';

        while ($row = pg_fetch_row($result)) {
            $plan .= $row[0] . "\n";
        }

        return $plan;
    }

    private function seedTerms(int $count): void
    {
        $values = [];
        for ($i = 1; $i <= $count; $i++) {
            $values[] = sprintf(
                "(gen_random_uuid(), %d, 'product_cat', 'term-%d', 'Term %d', '', 0, 0, '%s')",
                $i,
                $i,
                $i,
                str_repeat('b', 64),
            );
        }

        pg_query($this->pgConn, '
            INSERT INTO commerce.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id,
                 term_count, checksum)
            VALUES ' . implode(',', $values));
    }

    /** `$parents` top-level terms, then children spread evenly beneath them (`id % parents + 1`). */
    private function seedHierarchy(int $count, int $parents): void
    {
        $values = [];
        for ($i = 1; $i <= $count; $i++) {
            $values[] = sprintf(
                "(gen_random_uuid(), %d, 'product_cat', 'term-%d', 'Term %d', '', %d, 0, '%s')",
                $i,
                $i,
                $i,
                $i <= $parents ? 0 : $i % $parents + 1,
                str_repeat('b', 64),
            );
        }

        pg_query($this->pgConn, '
            INSERT INTO commerce.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id,
                 term_count, checksum)
            VALUES ' . implode(',', $values));
    }

    private function project(int $id, string $slug, int $parent = 0, string $taxonomy = 'product_cat'): void
    {
        $this->loader->terms[$id] = $this->term($id, $slug, $taxonomy, $parent);
        $this->handler()->handle($this->event($id, 'commerce.product_category.created', 1, "c{$id}"));
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

final class IntegrationTermEvent implements \HSP\Core\Contracts\EventInterface
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
        return sprintf('%s-0000-7000-8000-%012d', substr(md5($this->eventKey), 0, 8), crc32($this->eventKey) % 999999);
    }

    public function getEventType(): string { return $this->eventType; }
    public function getEventVersion(): int { return 1; }
    public function getAggregateType(): string { return 'product_category'; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getAggregateVersion(): int { return $this->version; }
    /** @return array<string,mixed> */
    public function getPayload(): array { return []; }
    public function getChecksum(): string { return str_repeat('d', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string { return null; }
}
