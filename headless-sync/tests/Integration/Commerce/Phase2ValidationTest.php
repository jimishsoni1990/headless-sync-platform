<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\ProjectionDescriptor;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\Reconciliation\WpCommerceReconciliationSource;
use HSP\Modules\Commerce\Replay\CommerceReplayEmitter;
use HSP\Tests\Support\CommerceSchema;
use PHPUnit\Framework\TestCase;

/**
 * P2-S7 — Phase 2 contract close. Evidence only, no feature code.
 *
 * The per-session tests each proved their own vertical, and TwoModuleSystemTest proves the two
 * modules run together. This closes what neither covers: the PROHIBITIONS and the CONTRACT
 * statements, which have the property that nothing fails when they are violated — a rule with no
 * test is a hope, and Phase 1B learned that from DECISION Y.
 *
 * The three reporting items DECISION AG requires of this session are recorded here as tests, so
 * they cannot quietly drop off the roadmap:
 *
 *   - Product permalink compatibility          → EXPLICITLY DEFERRED (FLAG-COMMPERMA-1)
 *   - Product Category permalink compatibility → EXPLICITLY DEFERRED (FLAG-COMMPERMA-1)
 *   - Product-type scope                       → simple + variable, stated and asserted
 */
final class Phase2ValidationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);

        CommerceSchema::applySystemTables($this->pgConn);
        CommerceSchema::applyAll($this->pgConn);
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

    // =========================================================================
    // Reporting item 1 & 2 — permalinks (Requirement A)
    // =========================================================================

    /**
     * PRODUCT PERMALINK COMPATIBILITY: **EXPLICITLY DEFERRED WITH FLAG** (FLAG-COMMPERMA-1).
     *
     * Requirement A classifies the outcome as Case A (projected state suffices), Case B (a
     * store-level configuration projection is needed) or Case C (ship without reconstruction and
     * record it). The P2-S2 preflight classified it **Case B**: WooCommerce's permalink base is
     * configurable (`woocommerce_permalinks`) and a product's public path can depend on category
     * configuration, so a consumer cannot reconstruct the configured URL from catalogue data
     * alone.
     *
     * Case B requires STOP-and-flag rather than invention, so Phase 2 ships no permalink and no
     * settings projection. What this test enforces is the half that can rot: that no derived URL
     * column or field appeared anyway. A stored path would go stale on a settings change with no
     * event to repair it — a wrong URL that nothing detects.
     */
    public function test_no_commerce_projection_stores_a_derived_url(): void
    {
        $columns = $this->db->query(
            "SELECT table_name, column_name
             FROM information_schema.columns
             WHERE table_schema = 'commerce'
               AND (column_name LIKE '%permalink%'
                 OR column_name LIKE '%url%'
                 OR column_name = 'path'
                 OR column_name = 'uri')"
        );

        self::assertSame(
            [],
            $columns,
            'FLAG-COMMPERMA-1 is unresolved, so no Commerce table may carry a derived URL.',
        );
    }

    /** The same guarantee at the contract boundary: no endpoint publishes a URL field. */
    public function test_no_commerce_endpoint_publishes_a_permalink(): void
    {
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            $encoded = json_encode($endpoint->responseSchema) ?: '';

            self::assertStringNotContainsStringIgnoringCase(
                'permalink',
                $encoded,
                "{$endpoint->route} publishes a permalink while FLAG-COMMPERMA-1 is unresolved.",
            );
        }
    }

    // =========================================================================
    // Reporting item 3 — product-type scope (AG-13)
    // =========================================================================

    /**
     * PRODUCT-TYPE SCOPE: Phase 2 supports **`simple` and `variable` only**.
     *
     * Stated as an assertion rather than a sentence in a document, because the claim that would
     * be wrong is the unstated one — "WooCommerce products sync" reads as complete parity.
     * `grouped`, `external`/affiliate and third-party custom types are NOT supported, and are
     * handled as normal out-of-scope source rather than as failures.
     */
    public function test_phase_2_supports_simple_and_variable_products_only(): void
    {
        self::assertSame(['simple', 'variable'], ProductScope::SUPPORTED_TYPES);

        foreach (['grouped', 'external', 'subscription', 'bundle', 'booking'] as $unsupported) {
            self::assertFalse(
                ProductScope::isSupportedType($unsupported),
                "Phase 2 must not claim support for '{$unsupported}'.",
            );
        }
    }

    // =========================================================================
    // Amendment B — catalog visibility is not post status
    // =========================================================================

    /**
     * The product projection carries catalog visibility as its OWN column.
     *
     * Requirement B: `status = publish` does not mean "in the catalogue". If visibility were
     * derived from status, a published-but-hidden product would appear in every listing — and
     * every test using visible products would still pass.
     */
    public function test_the_product_projection_carries_catalog_visibility_separately_from_status(): void
    {
        $columns = $this->commerceColumns('products');

        self::assertContains('status', $columns);
        self::assertContains('catalog_visibility', $columns);
    }

    // =========================================================================
    // AG-8 — inventory owns stock
    // =========================================================================

    /**
     * No stock column survives on commerce.products.
     *
     * Doc 3 §13 placed `stock_status` on the product AND on inventory, which would give one
     * WordPress fact two projections, two checksums and two independent write-suppress decisions
     * that can disagree with no rule for which wins. AG-8 removed it; this stops it coming back.
     */
    public function test_the_product_projection_carries_no_stock_column(): void
    {
        foreach ($this->commerceColumns('products') as $column) {
            self::assertStringNotContainsString(
                'stock',
                $column,
                "commerce.products.{$column} duplicates a fact commerce.inventory owns (AG-8).",
            );
        }
    }

    /** And no per-product currency: currency is store-level configuration (Requirement C′). */
    public function test_no_commerce_projection_carries_a_per_row_currency(): void
    {
        $columns = $this->db->query(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'commerce' AND column_name LIKE '%currency%'"
        );

        self::assertSame([], $columns);
    }

    /**
     * Money is NUMERIC with NO imposed precision or scale (Requirement C).
     *
     * A fixed scale would narrow the source value on a store whose decimal configuration is not
     * two places, and binary floating point is prohibited outright.
     */
    public function test_every_monetary_column_is_unconstrained_numeric(): void
    {
        $rows = $this->db->query(
            "SELECT table_name, column_name, data_type, numeric_precision, numeric_scale
             FROM information_schema.columns
             WHERE table_schema = 'commerce'
               AND column_name IN ('price', 'regular_price', 'sale_price')"
        );

        self::assertNotSame([], $rows, 'the guard must actually find the money columns');

        foreach ($rows as $row) {
            $where = "{$row['table_name']}.{$row['column_name']}";

            self::assertSame('numeric', $row['data_type'], "{$where} must be NUMERIC");
            self::assertNull($row['numeric_precision'], "{$where} must impose no precision");
            self::assertNull($row['numeric_scale'], "{$where} must impose no scale");
        }
    }

    /** No binary floating point anywhere in the Commerce schema. */
    public function test_no_commerce_column_uses_binary_floating_point(): void
    {
        $rows = $this->db->query(
            "SELECT table_name, column_name, data_type FROM information_schema.columns
             WHERE table_schema = 'commerce'
               AND data_type IN ('real', 'double precision')"
        );

        self::assertSame([], $rows);
    }

    // =========================================================================
    // AG-7 — no cross-aggregate foreign keys
    // =========================================================================

    /**
     * Independently projected Commerce aggregates carry NO foreign keys to each other.
     *
     * Under at-least-once, non-FIFO delivery a child routinely arrives before its parent, and an
     * FK would turn valid out-of-order synchronisation into a DLQ failure. Doc 3 §18's mandatory
     * FK requirement is superseded for exactly this reason.
     */
    public function test_no_foreign_keys_between_commerce_aggregates(): void
    {
        $rows = $this->db->query(
            "SELECT tc.table_name, tc.constraint_name
             FROM information_schema.table_constraints tc
             WHERE tc.table_schema = 'commerce' AND tc.constraint_type = 'FOREIGN KEY'"
        );

        self::assertSame([], $rows, 'AG-7: soft references only between Commerce aggregates.');
    }

    /** And none reaching across into another module's schema. */
    public function test_commerce_holds_no_foreign_key_into_content(): void
    {
        $rows = $this->db->query(
            "SELECT tc.table_name
             FROM information_schema.table_constraints tc
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name
             WHERE tc.table_schema = 'commerce'
               AND tc.constraint_type = 'FOREIGN KEY'
               AND ccu.table_schema = 'content'"
        );

        self::assertSame([], $rows, 'AG-10: content.media is referenced softly, never by FK.');
    }

    // =========================================================================
    // AG-9 — one shared taxonomy model
    // =========================================================================

    /**
     * Product categories and `pa_*` terms share ONE table, and no second term table exists.
     *
     * Doc 3's `commerce.categories`, `commerce.attribute_terms` and `commerce.product_categories`
     * are superseded where they merely represent terms and relationships. `commerce.attributes`
     * stays, because a global attribute DEFINITION is not a term.
     */
    public function test_commerce_has_exactly_one_taxonomy_term_table(): void
    {
        $tables = $this->commerceTables();

        self::assertContains('taxonomies', $tables);
        self::assertContains('entity_taxonomies', $tables);
        self::assertContains('attributes', $tables, 'definitions are not terms (AG-9)');

        self::assertNotContains('categories', $tables);
        self::assertNotContains('attribute_terms', $tables);
        self::assertNotContains('product_categories', $tables);
    }

    /** And no `product_tag` support, which AG-9 excluded explicitly. */
    public function test_product_tags_are_not_implemented(): void
    {
        self::assertNull(CommerceTaxonomies::aggregateFor('product_tag'));
        self::assertSame([CommerceTaxonomies::PRODUCT_CAT], CommerceTaxonomies::supported());
    }

    /** AG-14: one inventory table, aggregate-aware, never split per owner kind. */
    public function test_inventory_is_one_aggregate_aware_table(): void
    {
        $tables = $this->commerceTables();

        self::assertContains('inventory', $tables);
        self::assertNotContains('product_inventory', $tables);
        self::assertNotContains('variation_inventory', $tables);

        $columns = $this->commerceColumns('inventory');
        self::assertContains('owner_type', $columns);
        self::assertContains('owner_id', $columns);
    }

    // =========================================================================
    // AG-10 — content.media stays the single attachment projection
    // =========================================================================

    public function test_commerce_projects_no_attachment_of_its_own(): void
    {
        self::assertNotContains('media', $this->commerceTables());
        self::assertNotContains('attachments', $this->commerceTables());
    }

    /**
     * Commerce's PHP contains no import from the Content module.
     *
     * Rule 5 in its sharpest form: the two modules must compose through Core contracts and
     * events, so that enabling and disabling them in any combination stays correct.
     */
    public function test_no_commerce_file_imports_the_content_module(): void
    {
        $offenders = [];

        foreach ($this->commercePhpFiles() as $file) {
            $source = file_get_contents($file);

            if ($source !== false && preg_match('/HSP\\\\Modules\\\\Content/', $source) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders, 'Rule 5 / AG-10: no Commerce → Content import.');
    }

    // =========================================================================
    // AG-1 — core knows no concrete module
    // =========================================================================

    /**
     * Adding Commerce added no reference to it anywhere under core/.
     *
     * The Phase 2 success test in one assertion: WooCommerce became the second domain module
     * without special-casing Commerce in Core.
     */
    public function test_core_contains_no_reference_to_the_commerce_module(): void
    {
        $offenders = [];

        $root     = \dirname(__DIR__, 3) . '/core';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if ($source !== false && preg_match('/HSP\\\\Modules\\\\Commerce/', $source) === 1) {
                $offenders[] = str_replace($root, 'core', $file->getPathname());
            }
        }

        self::assertSame([], $offenders, 'AG-1: core must not know a concrete module.');
    }

    // =========================================================================
    // DECISION Y — no search in Phase 2 either
    // =========================================================================

    /**
     * The needles are the FULL-TEXT ones specifically, matching the Phase 1B guard.
     *
     * A bare `USING GIN` is deliberately not among them: commerce.products indexes `meta_jsonb`
     * that way for containment queries, which is an ordinary JSONB index and not search. A guard
     * that rejected it would be wrong, and — worse — would be loosened by whoever hit it next,
     * taking the real prohibition with it.
     */
    public function test_no_commerce_migration_creates_a_tsvector_or_full_text_index(): void
    {
        $dir   = \dirname(__DIR__, 3) . '/modules/Commerce/Migrations';
        $files = glob($dir . '/*.sql') ?: [];

        self::assertNotSame([], $files, 'the guard must actually scan something');

        $offenders = [];

        foreach ($files as $file) {
            $sql = strtolower((string) file_get_contents($file));

            if (str_contains($sql, 'tsvector')
                || str_contains($sql, 'to_tsvector')
                || str_contains($sql, 'gin (to_tsvector')
                || str_contains($sql, 'tsquery')
                || str_contains($sql, 'pg_trgm')
            ) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame(
            [],
            $offenders,
            'DECISION Y defers PostgreSQL full-text search — no Commerce migration may introduce '
            . 'a tsvector column or a full-text index.',
        );
    }

    public function test_the_live_commerce_schema_carries_no_tsvector_column(): void
    {
        $rows = $this->db->query(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = 'commerce' AND udt_name = 'tsvector'"
        );

        self::assertSame([], $rows);
    }

    public function test_no_commerce_endpoint_is_a_search_endpoint(): void
    {
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            self::assertStringNotContainsString('search', strtolower($endpoint->route));

            foreach ($endpoint->parameters as $parameter) {
                self::assertNotSame(
                    'q',
                    strtolower($parameter->name),
                    "{$endpoint->route} exposes a free-text query parameter (DECISION Y).",
                );
            }
        }
    }

    // =========================================================================
    // Aggregate coverage — every Phase 2 aggregate can be replayed and reconciled
    // =========================================================================

    /**
     * FLAG-RECON-COVERAGE-1 for Commerce: an aggregate the module can reconcile but not replay
     * would be detected as drifted and never repaired, because reconciliation repairs EXCLUSIVELY
     * by re-emission (DECISION T/U).
     */
    public function test_every_reconcilable_commerce_aggregate_can_also_be_replayed(): void
    {
        $reconcilable = $this->constantOf(WpCommerceReconciliationSource::class, 'AGGREGATE_TYPES');
        $replayable   = $this->constantOf(CommerceReplayEmitter::class, 'AGGREGATE_TYPES');

        self::assertNotSame([], $reconcilable);
        self::assertSame(
            $reconcilable,
            $replayable,
            'the two lists must agree exactly — a gap either way is a repair that never happens',
        );
    }

    /** And each has an event vocabulary, so a re-emission has something to emit. */
    public function test_every_commerce_aggregate_has_an_event_vocabulary(): void
    {
        $aggregates = $this->constantOf(WpCommerceReconciliationSource::class, 'AGGREGATE_TYPES');

        foreach ($aggregates as $aggregate) {
            $matching = array_filter(
                CommerceEventTypes::ALL,
                static fn (string $type): bool => str_starts_with($type, "commerce.{$aggregate}."),
            );

            self::assertNotSame([], $matching, "'{$aggregate}' has no event types");
        }
    }

    /** Every Phase 2 aggregate names a projection table that exists. */
    public function test_every_commerce_projection_descriptor_matches_the_live_schema(): void
    {
        $descriptors = [
            new ProjectionDescriptor('product', 'commerce.products', 'source_product_id'),
            new ProjectionDescriptor('product_category', 'commerce.taxonomies', 'source_term_id', CommerceTaxonomies::PRODUCT_CAT, 'taxonomy_type'),
            new ProjectionDescriptor('attribute', 'commerce.attributes', 'source_attribute_id'),
            new ProjectionDescriptor('attribute_term', 'commerce.taxonomies', 'source_term_id', CommerceTaxonomies::ATTRIBUTE_PREFIX, 'taxonomy_type', ProjectionDescriptor::MATCH_PREFIX),
            new ProjectionDescriptor('product_variation', 'commerce.product_variations', 'source_variation_id'),
            new ProjectionDescriptor('inventory', 'commerce.inventory', 'owner_id'),
        ];

        foreach ($descriptors as $descriptor) {
            [, $table] = explode('.', $descriptor->table);

            $columns = $this->commerceColumns($table);

            self::assertNotSame([], $columns, "{$descriptor->table} does not exist");
            self::assertContains($descriptor->sourceIdColumn, $columns, $descriptor->table);
            self::assertContains('deleted_at', $columns, "{$descriptor->table} must be tombstonable");

            if ($descriptor->isDiscriminated()) {
                self::assertContains((string) $descriptor->discriminatorColumn, $columns, $descriptor->table);
            }
        }
    }

    // =========================================================================
    // No second repair path
    // =========================================================================

    /**
     * Reconciliation and replay repair ONLY by re-emission (DECISION T/U).
     *
     * The Commerce replay emitter must reach the outbox and nothing else — no PostgreSQL
     * connection, no adapter, no direct write. A second repair path is not a bug that shows up
     * as a failure; it is a divergence that shows up as two components disagreeing months later.
     */
    public function test_the_commerce_replay_emitter_holds_no_write_capability(): void
    {
        $constructor = (new \ReflectionClass(CommerceReplayEmitter::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            self::assertStringNotContainsString('DatabaseConnection', $name, $parameter->getName());
            self::assertStringNotContainsString('Adapter', $name, $parameter->getName());
        }
    }

    public function test_the_commerce_reconciliation_source_holds_no_write_capability(): void
    {
        $constructor = (new \ReflectionClass(WpCommerceReconciliationSource::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

            self::assertStringNotContainsString('DatabaseConnection', $name, $parameter->getName());
            self::assertStringNotContainsString('Adapter', $name, $parameter->getName());
        }
    }

    // =========================================================================
    // ADR-054 — no daemon crept in
    // =========================================================================

    /**
     * WP-Cron remains the only execution mechanism.
     *
     * Adding a second module is exactly the moment someone reaches for a worker pool, so the
     * prohibition is checked at the point it becomes tempting.
     */
    public function test_no_commerce_file_introduces_a_supervised_execution_path(): void
    {
        $offenders = [];

        foreach ($this->commercePhpFiles() as $file) {
            $source = strtolower((string) file_get_contents($file));

            foreach (['supervisord', 'systemd', 'pcntl_fork', 'proc_open', 'worker_pool'] as $needle) {
                if (str_contains($source, $needle)) {
                    $offenders[] = basename($file) . " → {$needle}";
                }
            }
        }

        self::assertSame([], $offenders, 'ADR-054: WP-Cron is the only v1.x execution mechanism.');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /** @return list<string> */
    private function commerceTables(): array
    {
        $rows = $this->db->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = 'commerce'"
        );

        return array_map(static fn (array $r): string => (string) $r['table_name'], $rows);
    }

    /** @return list<string> */
    private function commerceColumns(string $table): array
    {
        $rows = $this->db->query(
            "SELECT column_name FROM information_schema.columns
             WHERE table_schema = 'commerce' AND table_name = $1",
            [$table],
        );

        return array_map(static fn (array $r): string => (string) $r['column_name'], $rows);
    }

    /** @return list<string> */
    private function commercePhpFiles(): array
    {
        $root     = \dirname(__DIR__, 3) . '/modules/Commerce';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return list<string> */
    private function constantOf(string $class, string $name): array
    {
        $value = (new \ReflectionClass($class))->getConstant($name);

        self::assertIsArray($value, "{$class}::{$name} must exist and be an array");

        /** @var list<string> $value */
        return $value;
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
