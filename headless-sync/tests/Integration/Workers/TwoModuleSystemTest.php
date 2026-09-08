<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Workers;

use HSP\Core\Contracts\ProjectionDescriptor;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\DispatcherWorkerStrategy;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Projection\ProjectionRegistry;
use HSP\Core\Queue\PartitionRouter;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;
use HSP\Core\Workers\DatabaseHeartbeatPublisher;
use HSP\Core\Workers\Strategies\EventWorkerStrategy;
use HSP\Core\Workers\Strategies\MaintenanceWorkerStrategy;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use HSP\Core\Workers\WorkerEngine;
use HSP\Modules\Commerce\Adapters\AttributeAdapter;
use HSP\Modules\Commerce\Adapters\InventoryAdapter;
use HSP\Modules\Commerce\Adapters\ProductAdapter as CommerceProductAdapter;
use HSP\Modules\Commerce\Adapters\TermAdapter as CommerceTermAdapter;
use HSP\Modules\Commerce\Adapters\VariationAdapter;
use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\Events\CommerceEventTypes;
use HSP\Modules\Commerce\Extractors\AttributeExtractor;
use HSP\Modules\Commerce\Extractors\InventoryExtractor;
use HSP\Modules\Commerce\Extractors\ProductExtractor as CommerceProductExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor as CommerceTermExtractor;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Handlers\AttributeTombstoneHandler;
use HSP\Modules\Commerce\Handlers\AttributeUpsertHandler;
use HSP\Modules\Commerce\Handlers\InventoryTombstoneHandler;
use HSP\Modules\Commerce\Handlers\InventoryUpsertHandler;
use HSP\Modules\Commerce\Handlers\ProductTombstoneHandler as CommerceProductTombstoneHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler as CommerceProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler as CommerceTermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler as CommerceTermUpsertHandler;
use HSP\Modules\Commerce\Handlers\VariationTombstoneHandler;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;
use HSP\Modules\Commerce\InventoryOwner;
use HSP\Modules\Commerce\Subscribers\CommerceSubscriber;
use HSP\Modules\Commerce\Transformers\AttributeTransformer;
use HSP\Modules\Commerce\Transformers\InventoryTransformer;
use HSP\Modules\Commerce\Transformers\ProductTransformer as CommerceProductTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer as CommerceTermTransformer;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\AttributeValidator;
use HSP\Modules\Commerce\Validation\InventoryValidator;
use HSP\Modules\Commerce\Validation\ProductValidator as CommerceProductValidator;
use HSP\Modules\Commerce\Validation\TermValidator as CommerceTermValidator;
use HSP\Modules\Commerce\Validation\VariationValidator;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Handlers\CategoryTombstoneHandler;
use HSP\Modules\Content\Handlers\CategoryUpsertHandler;
use HSP\Modules\Content\Handlers\PageTombstoneHandler;
use HSP\Modules\Content\Handlers\PageUpsertHandler;
use HSP\Modules\Content\Handlers\PostTombstoneHandler;
use HSP\Modules\Content\Handlers\PostUpsertHandler;
use HSP\Modules\Content\Subscribers\ContentSubscriber;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Integration\Replay\FakeWpStore;
use HSP\Tests\Integration\Replay\ReplayReadingLoader;
use HSP\Tests\Support\CommerceSchema;
use HSP\Tests\Support\ContentSchema;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\TestCase;

/**
 * P2-S7 — the two-module system test, on LIVE MySQL + PostgreSQL.
 *
 * This is the scenario Phase 2 exists to pass, and it is deliberately not "Commerce works".
 * Every other test in the suite exercises one module's slice; this one runs BOTH through the
 * one real engine at once:
 *
 *   outbox capture → bounded WP-Cron cycle → relay → domain partition routing → projection
 *                                                                  → content.* + commerce.*
 *
 * Nothing here is a fake pipeline. The RelayWorkerStrategy reads a real MySQL outbox, the
 * DispatcherWorkerStrategy routes through the real PartitionRouter, and the EventWorkerStrategy
 * drives the shipped ContentSubscriber and CommerceSubscriber into the shipped adapters. If the
 * two modules can interfere with each other anywhere, it is visible here and nowhere else.
 *
 * The properties under test are the ones that only a SECOND module can break:
 *
 *   - routing by domain, with each partition drained fairly
 *   - `processing.projection_batch_size` as the TOTAL cycle budget, never multiplied per domain
 *   - no double-processing and monotonic versions across a mixed queue
 *   - a deletion in every Commerce vertical tombstoning through the ordinary path
 *
 * Environment (self-skips when absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class TwoModuleSystemTest extends TestCase
{
    private string $prefix = 'hsp_p2s7_';
    private string $outbox;

    private ?\mysqli $mysqli = null;
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    private FakeWpStore $wp;
    private InMemoryCommerceLoader $commerce;

    protected function setUp(): void
    {
        $this->outbox = $this->prefix . 'hsp_outbox';

        $this->mysqli = $this->connectMysql();
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);

        $this->wp       = new FakeWpStore();
        $this->commerce = new InMemoryCommerceLoader();

        $this->createMysqlSchema();
        $this->createPgsqlSchema();
    }

    protected function tearDown(): void
    {
        if ($this->mysqli !== null) {
            $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
            $this->mysqli->close();
            $this->mysqli = null;
        }

        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS commerce CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // The mixed-domain scenario
    // =========================================================================

    /**
     * One cycle, both domains, every Phase 2 aggregate.
     *
     * The single most important assertion in Phase 2: a WordPress content change and a full
     * WooCommerce catalogue change travel the SAME pipeline in the SAME bounded cycle and both
     * land, with core knowing nothing about either module.
     */
    public function test_a_mixed_domain_change_set_reaches_both_projections(): void
    {
        $this->seedMixedCatalogue();

        $result = $this->engine()->runCycle();

        // Eight aggregates, two domains, one cycle: a post and a content category, plus a
        // product category, an attribute, an attribute term, a product, a variation and its
        // inventory.
        self::assertSame(8, $result->relayed, 'every captured event relayed');
        self::assertSame(8, $result->projected, 'every relayed event projected in one cycle');

        // Content
        self::assertSame(1, $this->countRows('content.posts'));
        self::assertSame(1, $this->countRows('content.taxonomies'));

        // Commerce — all five verticals
        self::assertSame(1, $this->countRows('commerce.products'));
        self::assertSame(2, $this->countRows('commerce.taxonomies'), 'a category and an attribute term');
        self::assertSame(1, $this->countRows('commerce.attributes'));
        self::assertSame(1, $this->countRows('commerce.product_variations'));
        self::assertSame(1, $this->countRows('commerce.inventory'));
    }

    /**
     * Routing resolves by EVENT DOMAIN, through the one seam (AG-4).
     *
     * The queue name is the observable proof: before P2-S1 the partition was a literal in three
     * separate places, so a second domain had nowhere to go.
     */
    public function test_each_domain_routes_to_its_own_partition(): void
    {
        $this->seedMixedCatalogue();
        $this->engine()->runCycle();

        $rows = $this->db->query(
            "SELECT e.aggregate_type, j.queue_name
             FROM system.queue_jobs j
             JOIN system.events e ON e.id = j.event_id
             ORDER BY e.aggregate_type"
        );

        $byAggregate = [];
        foreach ($rows as $row) {
            $byAggregate[(string) $row['aggregate_type']] = (string) $row['queue_name'];
        }

        self::assertSame('content', $byAggregate['post'] ?? null);
        self::assertSame('content', $byAggregate['category'] ?? null);
        self::assertSame('commerce', $byAggregate['product'] ?? null);
        self::assertSame('commerce', $byAggregate['product_category'] ?? null);
        self::assertSame('commerce', $byAggregate['attribute'] ?? null);
        self::assertSame('commerce', $byAggregate['attribute_term'] ?? null);
        self::assertSame('commerce', $byAggregate['product_variation'] ?? null);
        self::assertSame('commerce', $byAggregate['inventory'] ?? null);
    }

    /**
     * A saturated Commerce import must not starve Content (AG-4).
     *
     * The budget is deliberately smaller than the backlog, so the cycle CANNOT drain everything
     * and has to choose. A per-partition loop that drained commerce first would leave content
     * at zero — which is the failure this rotation exists to prevent, and it would look like
     * "content is just a bit behind" rather than like a bug.
     */
    public function test_a_saturated_commerce_queue_does_not_starve_content(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->seedCommerceProduct(1000 + $i, "bulk-{$i}");
        }

        // Content arrives last, i.e. in the least favourable position.
        for ($i = 1; $i <= 5; $i++) {
            $this->seedContentPost(2000 + $i, "post-{$i}");
        }

        // Half the backlog, so the cycle must split it rather than finish.
        $this->engine(projectionBatchSize: 20)->runCycle();

        $contentDone  = $this->countRows('content.posts');
        $commerceDone = $this->countRows('commerce.products');

        self::assertGreaterThan(0, $contentDone, 'Content made progress despite a busy Commerce queue');
        self::assertGreaterThan(0, $commerceDone, 'Commerce made progress too');
    }

    /** And the mirror image, because starvation is not symmetric by accident. */
    public function test_a_saturated_content_queue_does_not_starve_commerce(): void
    {
        for ($i = 1; $i <= 40; $i++) {
            $this->seedContentPost(1000 + $i, "bulk-{$i}");
        }

        for ($i = 1; $i <= 5; $i++) {
            $this->seedCommerceProduct(2000 + $i, "product-{$i}");
        }

        $this->engine(projectionBatchSize: 20)->runCycle();

        self::assertGreaterThan(0, $this->countRows('commerce.products'), 'Commerce made progress');
        self::assertGreaterThan(0, $this->countRows('content.posts'), 'Content made progress too');
    }

    /**
     * The batch size is the TOTAL cycle budget, never multiplied per domain (AG-4).
     *
     * This is the ruling the plan calls load-bearing: two active domains must not silently
     * double the work a cycle does, because the cycle has to exit before max_execution_time and
     * that headroom is the whole basis of the WP-Cron execution model (ADR-054).
     */
    public function test_the_projection_batch_is_a_total_not_a_per_domain_budget(): void
    {
        for ($i = 1; $i <= 15; $i++) {
            $this->seedContentPost(1000 + $i, "post-{$i}");
            $this->seedCommerceProduct(2000 + $i, "product-{$i}");
        }

        $result = $this->engine(projectionBatchSize: 10)->runCycle();

        self::assertSame(
            10,
            $result->projected,
            'a 10-event budget with two active domains must project 10 events, not 10 per domain',
        );

        $total = $this->countRows('content.posts') + $this->countRows('commerce.products');
        self::assertSame(10, $total);
    }

    /**
     * Durable progress across cycles: what one bounded cycle could not finish, the next does.
     */
    public function test_a_mixed_backlog_drains_across_consecutive_cycles(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->seedContentPost(1000 + $i, "post-{$i}");
            $this->seedCommerceProduct(2000 + $i, "product-{$i}");
        }

        $engine = $this->engine(projectionBatchSize: 8);

        $projected = 0;
        for ($cycle = 0; $cycle < 5; $cycle++) {
            $projected += $engine->runCycle()->projected;
        }

        self::assertSame(20, $projected);
        self::assertSame(10, $this->countRows('content.posts'));
        self::assertSame(10, $this->countRows('commerce.products'));
    }

    // =========================================================================
    // Integrity across a mixed queue
    // =========================================================================

    public function test_no_event_is_processed_twice(): void
    {
        $this->seedMixedCatalogue();

        $engine = $this->engine();
        $engine->runCycle();
        // A second cycle over an already-drained queue must add nothing.
        $engine->runCycle();

        $events    = $this->countRows('system.events');
        $processed = $this->countRows('system.processed_events');

        self::assertSame($events, $processed, 'one processed_events row per event, never two');
        self::assertSame(0, $this->countRows("system.dead_letter_jobs"));
    }

    /**
     * Aggregate versions stay monotonic per (type, id) across BOTH domains.
     *
     * The pair is what matters: `product` 42 and `post` 42 are different aggregates that share
     * an id, and a watermark keyed on the id alone would let one domain's version roll the
     * other's back — silently suppressing writes in a module that never did anything wrong.
     */
    public function test_aggregate_versions_are_keyed_by_domain_as_well_as_id(): void
    {
        // Deliberately the SAME numeric id in both domains.
        $this->seedContentPost(42, 'shared-id-post');
        $this->seedCommerceProduct(42, 'shared-id-product');

        $this->engine()->runCycle();

        $rows = $this->db->query(
            "SELECT aggregate_type, latest_processed_version
             FROM system.aggregate_versions
             WHERE aggregate_id = '42'
             ORDER BY aggregate_type"
        );

        $types = array_map(static fn (array $r): string => (string) $r['aggregate_type'], $rows);

        self::assertContains('post', $types);
        self::assertContains('product', $types);
        self::assertSame(1, $this->countRows("content.posts WHERE source_post_id = 42"));
        self::assertSame(1, $this->countRows("commerce.products WHERE source_product_id = 42"));
    }

    /** Content's own behaviour is unchanged by Commerce being active. */
    public function test_content_projections_are_correct_alongside_commerce(): void
    {
        $this->seedMixedCatalogue();
        $this->engine()->runCycle();

        $row = $this->db->query(
            "SELECT slug, status, deleted_at FROM content.posts WHERE source_post_id = 101"
        )[0] ?? null;

        self::assertNotNull($row);
        self::assertSame('launch-notes', $row['slug']);
        self::assertNull($row['deleted_at']);
    }

    // =========================================================================
    // Lifecycle — one deletion from EVERY Commerce vertical
    // =========================================================================

    /**
     * Every independently synchronised Commerce aggregate tombstones through the ordinary path.
     *
     * The plan requires at least one deletion or out-of-scope transition from each vertical in
     * the final validation, and the reason is that a stale projection is INVISIBLE: nothing
     * errors, the row simply keeps being served after the source is gone.
     */
    public function test_a_deletion_in_every_commerce_vertical_tombstones(): void
    {
        $this->seedMixedCatalogue();
        $this->engine()->runCycle();

        // Everything present before the deletions.
        self::assertSame(0, $this->countRows('commerce.products WHERE deleted_at IS NOT NULL'));

        $this->capture(CommerceEventTypes::PRODUCT_DELETED, 'product', '201', 2);
        $this->capture(CommerceEventTypes::CATEGORY_DELETED, 'product_category', '301', 2);
        $this->capture(CommerceEventTypes::ATTRIBUTE_DELETED, 'attribute', '401', 2);
        $this->capture(CommerceEventTypes::ATTRIBUTE_TERM_DELETED, 'attribute_term', '302', 2);
        $this->capture(CommerceEventTypes::VARIATION_DELETED, 'product_variation', '501', 2);
        $this->capture(CommerceEventTypes::INVENTORY_DELETED, 'inventory', '201', 2);

        $this->engine()->runCycle();

        self::assertSame(1, $this->countRows('commerce.products WHERE deleted_at IS NOT NULL'));
        self::assertSame(2, $this->countRows('commerce.taxonomies WHERE deleted_at IS NOT NULL'));
        self::assertSame(1, $this->countRows('commerce.attributes WHERE deleted_at IS NOT NULL'));
        self::assertSame(1, $this->countRows('commerce.product_variations WHERE deleted_at IS NOT NULL'));
        self::assertSame(1, $this->countRows('commerce.inventory WHERE deleted_at IS NOT NULL'));

        // SOFT deletes throughout: the rows survive so replay can revive them (DECISION I/T/U).
        self::assertSame(1, $this->countRows('commerce.products'));
        self::assertSame(1, $this->countRows('commerce.product_variations'));
    }

    /**
     * The out-of-scope transition, which is the deletion case that does NOT look like one.
     *
     * A `variable` product retyped to `grouped` has left Phase 2 scope (AG-13). Nothing was
     * deleted in WordPress, so nothing emits a deletion — the ordinary update event is what has
     * to notice, and tombstone. Without that, the product stays publicly visible forever purely
     * because its new type is unsupported.
     */
    public function test_a_product_leaving_supported_scope_tombstones_itself_and_its_variation(): void
    {
        $this->seedMixedCatalogue();
        $this->engine()->runCycle();

        self::assertSame(0, $this->countRows('commerce.products WHERE deleted_at IS NOT NULL'));

        $this->commerce->products[201]['product_type'] = 'grouped';

        // Ordinary UPDATE events, not deletions.
        $this->capture(CommerceEventTypes::PRODUCT_UPDATED, 'product', '201', 2);
        $this->capture(CommerceEventTypes::VARIATION_UPDATED, 'product_variation', '501', 2);
        $this->capture(CommerceEventTypes::INVENTORY_UPDATED, 'inventory', '201', 2);

        $this->engine()->runCycle();

        self::assertSame(1, $this->countRows('commerce.products WHERE deleted_at IS NOT NULL'));
        self::assertSame(
            1,
            $this->countRows('commerce.product_variations WHERE deleted_at IS NOT NULL'),
            'a variation follows its parent out of scope',
        );
        self::assertSame(
            1,
            $this->countRows('commerce.inventory WHERE deleted_at IS NOT NULL'),
            'and so does its stock',
        );
    }

    /** Re-entering scope revives everything, through re-emission alone (DECISION T/U). */
    public function test_re_entering_scope_revives_the_projections(): void
    {
        $this->seedMixedCatalogue();
        $this->engine()->runCycle();

        $this->commerce->products[201]['product_type'] = 'grouped';
        $this->capture(CommerceEventTypes::PRODUCT_UPDATED, 'product', '201', 2);
        $this->capture(CommerceEventTypes::VARIATION_UPDATED, 'product_variation', '501', 2);
        $this->engine()->runCycle();

        self::assertSame(1, $this->countRows('commerce.products WHERE deleted_at IS NOT NULL'));

        $this->commerce->products[201]['product_type'] = 'variable';
        $this->capture(CommerceEventTypes::PRODUCT_UPDATED, 'product', '201', 3);
        $this->capture(CommerceEventTypes::VARIATION_UPDATED, 'product_variation', '501', 3);
        $this->engine()->runCycle();

        self::assertSame(
            0,
            $this->countRows('commerce.products WHERE deleted_at IS NOT NULL'),
            'the tombstone/checksum trap: an identical checksum must not suppress the revival',
        );
        self::assertSame(0, $this->countRows('commerce.product_variations WHERE deleted_at IS NOT NULL'));
    }

    // =========================================================================
    // PERFORMANCE — the mixed-domain latency measurement (DECISION AB)
    // =========================================================================

    /**
     * End-to-end sync latency with BOTH domains active, measured and printed.
     *
     * P1B-S5 measured this for Content alone. The Phase 2 question is different and is the one
     * DECISION AG Part 5 makes a STOP-and-flag: does a second domain consume the headroom that
     * DECISION AB's cadence depends on?
     *
     * The arithmetic is unchanged and still honest about what a test can and cannot observe. The
     * wait for the next WP-Cron tick is not elapsed time, it is a config value — so:
     *
     *     worst case = interval_seconds + cycle duration
     *     typical    = interval_seconds / 2 + cycle duration
     *
     * Every number is printed with the cadence and batch sizes that produced it, so the result
     * is reproducible and a config change invalidates it loudly rather than silently.
     *
     * The ASSERTION is against the PRD's 30s SLA rather than a tighter self-imposed bound. A
     * tighter one would measure this machine's disk and Docker latency more than it measures the
     * platform — and a performance test that fails for the wrong reason gets loosened, taking
     * the real guarantee with it.
     */
    public function test_mixed_domain_sync_latency_fits_the_sla(): void
    {
        // Read from the SHIPPED config, never restated: a cadence change must move this number.
        $processing      = require \dirname(__DIR__, 3) . '/config/worker.php';
        $processing      = $processing['processing'];
        $intervalSeconds = (int) $processing['interval_seconds'];
        $batchSize       = (int) $processing['projection_batch_size'];
        $cycleBudget     = (float) $processing['cycle_time_budget_seconds'];

        // One realistic edit in EACH domain, arriving together.
        $this->seedContentPost(1, 'latency-probe-post');
        $this->seedCommerceProduct(2, 'latency-probe-product');

        $engine = $this->engine(projectionBatchSize: $batchSize, budget: $cycleBudget);

        $startedAt = microtime(true);
        $result    = $engine->runCycle();
        $cycle     = microtime(true) - $startedAt;

        // Measuring a cycle that projected nothing would measure nothing.
        self::assertSame(2, $result->projected, 'both probes were projected by this one cycle');
        self::assertSame(1, $this->countRows('content.posts'));
        self::assertSame(1, $this->countRows('commerce.products'));

        $worstCase   = $intervalSeconds + $cycle;
        $typicalCase = ($intervalSeconds / 2) + $cycle;

        fwrite(STDERR, sprintf(
            "\n[P2-S7] MIXED-DOMAIN END-TO-END SYNC LATENCY (one content edit + one product edit)\n"
            . "  pipeline (relay -> dispatch -> route -> project): %.3f s for 2 events across 2 domains\n"
            . "  cron cadence (processing.interval_seconds):       %d s\n"
            . "  batch size (TOTAL, shared by both domains):       %d | cycle budget: %.0f s\n"
            . "  => typical total ~ %.1f s | worst case ~ %.1f s | PRD SLA: 30 s\n"
            . "  => margin at worst case: %.1f s\n"
            . "  (DECISION AB — the SLA also needs an out-of-band trigger at <= interval_seconds)\n\n",
            $cycle,
            $intervalSeconds,
            $batchSize,
            $cycleBudget,
            $typicalCase,
            $worstCase,
            30.0 - $worstCase,
        ));

        self::assertLessThan(
            30.0,
            $worstCase,
            sprintf(
                'Mixed-domain worst-case latency is %.1fs (interval_seconds=%d + %.3fs cycle) '
                . 'against the PRD 30s SLA. DECISION AG Part 5 is explicit that this is a '
                . 'STOP-and-flag, NOT a licence to raise the cycle budget, PHP timeout, '
                . 'connection count or processing architecture.',
                $worstCase,
                $intervalSeconds,
                $cycle,
            ),
        );
    }

    /**
     * Adding Commerce did not multiply the per-cycle work.
     *
     * The measurement that would have been missed by testing each domain alone: with both
     * domains active the cycle still processes at most `projection_batch_size` events, so the
     * headroom DECISION AB's cadence relies on is unchanged by the second module.
     */
    public function test_a_second_domain_does_not_enlarge_the_cycle(): void
    {
        $processing = require \dirname(__DIR__, 3) . '/config/worker.php';
        $batchSize  = (int) $processing['processing']['projection_batch_size'];

        // Comfortably more work than one cycle may take, split across both domains.
        $backlog = $batchSize + 20;

        for ($i = 1; $i <= $backlog; $i++) {
            if ($i % 2 === 0) {
                $this->seedContentPost(10000 + $i, "post-{$i}");
            } else {
                $this->seedCommerceProduct(20000 + $i, "product-{$i}");
            }
        }

        $result = $this->engine()->runCycle();

        self::assertLessThanOrEqual(
            $batchSize,
            $result->projected,
            'the shipped batch size is the TOTAL cycle budget, not a per-domain allowance (AG-4)',
        );
    }

    // =========================================================================
    // Harness
    // =========================================================================

    private function engine(int $projectionBatchSize = 200, float $budget = 20.0): WorkerEngine
    {
        $router = $this->router();
        $queue  = new DatabaseQueueProvider($this->db);

        return new WorkerEngine(
            new RelayWorkerStrategy(
                new MysqliOutboxConnection(fn () => $this->mysqli),
                new PgsqlOutboxConnection($this->pgConn),
                $this->prefix,
                200,
            ),
            new DispatcherWorkerStrategy(
                new EventDispatcher($this->db, $queue, $router, 200),
            ),
            new EventWorkerStrategy($queue, $this->registry(), $this->db, $router, retryLimit: 10),
            new MaintenanceWorkerStrategy($queue, ['partitions' => ['content', 'commerce', 'system']]),
            new DatabaseHeartbeatPublisher($this->db),
            projectionBatchSize:    $projectionBatchSize,
            cycleTimeBudgetSeconds: $budget,
            workerType:             'processing',
        );
    }

    /** Both domains registered, exactly as each module's boot() does (AG-4). */
    private function router(): PartitionRouter
    {
        $router = new PartitionRouter(['content', 'commerce', 'system']);
        $router->register('content', 'content');
        $router->register('commerce', 'commerce');

        return $router;
    }

    /** The real subscribers of BOTH modules, wired into one registry. */
    private function registry(): EventRegistry
    {
        $registry = new EventRegistry();

        foreach ($this->contentHandlers() as $eventType => $handler) {
            $registry->register($eventType, $handler);
        }

        $commerce = $this->commerceSubscriber();
        foreach (CommerceEventTypes::ALL as $eventType) {
            $registry->register($eventType, $commerce);
        }

        return $registry;
    }

    /** @return array<string, callable> */
    private function contentHandlers(): array
    {
        $pageLoader = new ReplayReadingLoader($this->wp, 'page');
        $postLoader = new ReplayReadingLoader($this->wp, 'post');

        $pageAdapter     = new PageAdapter($this->db);
        $postAdapter     = new PostAdapter($this->db);
        $categoryAdapter = new CategoryAdapter($this->db);

        $subscriber = new ContentSubscriber([
            ContentEventTypes::PAGE_CREATED     => new PageUpsertHandler($pageLoader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_UPDATED     => new PageUpsertHandler($pageLoader, new PageExtractor(new PageValidator()), new PageTransformer(), $pageAdapter),
            ContentEventTypes::PAGE_DELETED     => new PageTombstoneHandler($pageAdapter),
            ContentEventTypes::POST_CREATED     => new PostUpsertHandler($postLoader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_UPDATED     => new PostUpsertHandler($postLoader, new PostExtractor(new PostValidator()), new PostTransformer(), $postAdapter),
            ContentEventTypes::POST_DELETED     => new PostTombstoneHandler($postAdapter),
            ContentEventTypes::CATEGORY_CREATED => new CategoryUpsertHandler($postLoader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_UPDATED => new CategoryUpsertHandler($postLoader, new CategoryExtractor(new CategoryValidator()), new CategoryTransformer(), $categoryAdapter),
            ContentEventTypes::CATEGORY_DELETED => new CategoryTombstoneHandler($categoryAdapter),
        ]);

        $handlers = [];
        foreach (
            [
                ContentEventTypes::PAGE_CREATED, ContentEventTypes::PAGE_UPDATED, ContentEventTypes::PAGE_DELETED,
                ContentEventTypes::POST_CREATED, ContentEventTypes::POST_UPDATED, ContentEventTypes::POST_DELETED,
                ContentEventTypes::CATEGORY_CREATED, ContentEventTypes::CATEGORY_UPDATED, ContentEventTypes::CATEGORY_DELETED,
            ] as $eventType
        ) {
            $handlers[$eventType] = $subscriber;
        }

        return $handlers;
    }

    private function commerceSubscriber(): CommerceSubscriber
    {
        $loader = $this->commerce;

        return new CommerceSubscriber(
            new CommerceProductUpsertHandler(
                $loader,
                new CommerceProductExtractor(new CommerceProductValidator()),
                new CommerceProductTransformer(),
                new CommerceProductAdapter($this->db),
            ),
            new CommerceProductTombstoneHandler(new CommerceProductAdapter($this->db)),
            new CommerceTermUpsertHandler(
                $loader,
                new CommerceTermExtractor(new CommerceTermValidator()),
                new CommerceTermTransformer(),
                new CommerceTermAdapter($this->db),
            ),
            new CommerceTermTombstoneHandler(new CommerceTermAdapter($this->db)),
            new AttributeUpsertHandler(
                $loader,
                new AttributeExtractor(new AttributeValidator()),
                new AttributeTransformer(),
                new AttributeAdapter($this->db),
            ),
            new AttributeTombstoneHandler(new AttributeAdapter($this->db)),
            new VariationUpsertHandler(
                $loader,
                new VariationExtractor(new VariationValidator()),
                new VariationTransformer(),
                new VariationAdapter($this->db),
            ),
            new VariationTombstoneHandler(new VariationAdapter($this->db)),
            new InventoryUpsertHandler(
                $loader,
                new InventoryExtractor(new InventoryValidator()),
                new InventoryTransformer(),
                new InventoryAdapter($this->db),
            ),
            new InventoryTombstoneHandler(new InventoryAdapter($this->db)),
        );
    }

    /**
     * The projection descriptors both modules register — for the coverage assertion below, and
     * as the shape a reconciliation pass over this schema would use.
     */
    private function projections(): ProjectionRegistry
    {
        $registry = new ProjectionRegistry();

        $registry->register(new ProjectionDescriptor('post', 'content.posts', 'source_post_id'));
        $registry->register(new ProjectionDescriptor('page', 'content.pages', 'source_post_id'));
        $registry->register(new ProjectionDescriptor('category', 'content.taxonomies', 'source_term_id', 'category', 'taxonomy_type'));
        $registry->register(new ProjectionDescriptor('product', 'commerce.products', 'source_product_id'));
        $registry->register(new ProjectionDescriptor('product_category', 'commerce.taxonomies', 'source_term_id', CommerceTaxonomies::PRODUCT_CAT, 'taxonomy_type'));
        $registry->register(new ProjectionDescriptor('attribute', 'commerce.attributes', 'source_attribute_id'));
        $registry->register(new ProjectionDescriptor('attribute_term', 'commerce.taxonomies', 'source_term_id', CommerceTaxonomies::ATTRIBUTE_PREFIX, 'taxonomy_type', ProjectionDescriptor::MATCH_PREFIX));
        $registry->register(new ProjectionDescriptor('product_variation', 'commerce.product_variations', 'source_variation_id'));
        $registry->register(new ProjectionDescriptor('inventory', 'commerce.inventory', 'owner_id'));

        return $registry;
    }

    /** Every registered projection must name a table that actually exists in the live schema. */
    public function test_every_registered_projection_exists_in_the_live_schema(): void
    {
        foreach ($this->projections()->all() as $type => $descriptor) {
            [$schema, $table] = explode('.', $descriptor->table);

            $exists = $this->db->query(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = $1 AND table_name = $2',
                [$schema, $table],
            );

            self::assertNotSame([], $exists, "'{$type}' names a table that does not exist: {$descriptor->table}");

            $column = $this->db->query(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = $1 AND table_name = $2 AND column_name = $3',
                [$schema, $table, $descriptor->sourceIdColumn],
            );

            self::assertNotSame(
                [],
                $column,
                "'{$type}' names a source id column that does not exist: "
                . "{$descriptor->table}.{$descriptor->sourceIdColumn}",
            );
        }
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * One content change plus a complete WooCommerce catalogue: a product in a category, a
     * global attribute with a term, a variation and its stock.
     */
    private function seedMixedCatalogue(): void
    {
        // --- Content -----------------------------------------------------
        $this->wp->putPost(101, 'publish', 'post', 'launch-notes');
        $this->capture(ContentEventTypes::POST_CREATED, 'post', '101', 1);

        $this->wp->putTerm(102, 'announcements');
        $this->capture(ContentEventTypes::CATEGORY_CREATED, 'category', '102', 1);

        // --- Commerce ----------------------------------------------------
        $this->commerce->terms[301] = $this->term(301, 'clothing', CommerceTaxonomies::PRODUCT_CAT);
        $this->commerce->terms[302] = $this->term(302, 'blue', 'pa_colour');

        $this->commerce->attributes[401] = [
            'id' => 401, 'slug' => 'pa_colour', 'name' => 'Colour',
            'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false,
        ];

        $this->commerce->products[201] = $this->product(201, 'shirt', 'variable', [301], [302]);

        $this->commerce->variations[501] = [
            'id' => 501, 'parent_id' => 201, 'sku' => 'SHIRT-BLUE',
            'name' => 'Shirt - Blue', 'description' => '', 'status' => 'publish',
            'price' => '19.99', 'regular_price' => '19.99', 'sale_price' => null,
            'featured_media_id' => 0, 'menu_order' => 0,
            'attributes' => ['pa_colour' => 'blue'], 'attribute_term_ids' => [302],
            'modified_at' => '2026-01-01 00:00:00',
        ];

        $this->commerce->inventory[201] = [
            'owner_type' => InventoryOwner::TYPE_PRODUCT, 'owner_id' => 201,
            'manages_stock' => true, 'stock_quantity' => 12,
            'stock_status' => 'instock', 'backorders' => 'no', 'low_stock_amount' => null,
        ];

        $this->capture(CommerceEventTypes::CATEGORY_CREATED, 'product_category', '301', 1);
        $this->capture(CommerceEventTypes::ATTRIBUTE_TERM_CREATED, 'attribute_term', '302', 1);
        $this->capture(CommerceEventTypes::ATTRIBUTE_CREATED, 'attribute', '401', 1);
        $this->capture(CommerceEventTypes::PRODUCT_CREATED, 'product', '201', 1);
        $this->capture(CommerceEventTypes::VARIATION_CREATED, 'product_variation', '501', 1);
        $this->capture(CommerceEventTypes::INVENTORY_UPDATED, 'inventory', '201', 1);
    }

    private function seedContentPost(int $id, string $slug): void
    {
        $this->wp->putPost($id, 'publish', 'post', $slug);
        $this->capture(ContentEventTypes::POST_CREATED, 'post', (string) $id, 1);
    }

    private function seedCommerceProduct(int $id, string $slug): void
    {
        $this->commerce->products[$id] = $this->product($id, $slug);
        $this->capture(CommerceEventTypes::PRODUCT_CREATED, 'product', (string) $id, 1);
    }

    /**
     * @param list<int> $categoryIds
     * @param list<int> $attributeTermIds
     * @return array<string,mixed>
     */
    private function product(
        int $id,
        string $slug,
        string $type = 'simple',
        array $categoryIds = [],
        array $attributeTermIds = [],
    ): array {
        return [
            'id' => $id, 'sku' => "SKU-{$id}", 'slug' => $slug, 'name' => "Product {$id}",
            'description' => '', 'short_description' => '', 'status' => 'publish',
            'product_type' => $type, 'catalog_visibility' => 'visible', 'featured' => false,
            'price' => '19.99', 'regular_price' => '19.99', 'sale_price' => null,
            'featured_media_id' => 0, 'gallery_media_ids' => [],
            'published_at' => '2026-01-01 00:00:00', 'modified_at' => '2026-01-01 00:00:00',
            'meta' => [], 'category_ids' => $categoryIds,
            'attribute_term_ids' => $attributeTermIds,
        ];
    }

    /** @return array<string,mixed> */
    private function term(int $id, string $slug, string $taxonomy): array
    {
        return [
            'term_id' => $id, 'taxonomy' => $taxonomy, 'slug' => $slug,
            'name' => ucfirst($slug), 'description' => '', 'parent' => 0, 'count' => 0,
        ];
    }

    /** Write one row into the REAL MySQL outbox — the platform's only capture point (Rule 3). */
    private function capture(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int $aggregateVersion,
    ): void {
        $id            = $this->uuidv7();
        $correlationId = $this->uuidv7();
        $now           = gmdate('Y-m-d H:i:s');
        $payload       = json_encode(['id' => $aggregateId], JSON_THROW_ON_ERROR);
        $checksum      = hash('sha256', $eventType . $aggregateId . $aggregateVersion);

        $stmt = $this->mysqli->prepare(
            "INSERT INTO `{$this->outbox}`
                 (`id`, `event_type`, `event_version`, `aggregate_type`, `aggregate_id`,
                  `aggregate_version`, `source_updated_at`, `checksum`, `correlation_id`,
                  `causation_id`, `payload`, `status`, `created_at`, `relayed_at`)
             VALUES (?, ?, 1, ?, ?, ?, '2026-01-15 10:00:00', ?, ?, NULL, ?, 'pending', ?, NULL)"
        );
        $stmt->bind_param(
            'ssssissss',
            $id,
            $eventType,
            $aggregateType,
            $aggregateId,
            $aggregateVersion,
            $checksum,
            $correlationId,
            $payload,
            $now,
        );
        $stmt->execute();
        $stmt->close();
    }

    private function countRows(string $tableWithOptionalWhere): int
    {
        $rows = $this->db->query("SELECT COUNT(*) AS c FROM {$tableWithOptionalWhere}");

        return (int) ($rows[0]['c'] ?? 0);
    }

    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex  = sprintf('%012x', $ms);
        $rand12 = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex = sprintf('%04x', 0x7000 | $rand12);
        $rand14 = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex = sprintf('%04x', 0x8000 | $rand14);
        $tail   = bin2hex(substr($bytes, 4, 6));

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($tsHex, 0, 8),
            substr($tsHex, 8, 4),
            $b67hex,
            $b89hex,
            $tail,
        );
    }

    // =========================================================================
    // Schema
    // =========================================================================

    private function createMysqlSchema(): void
    {
        $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
        $this->mysqli->query(
            "CREATE TABLE `{$this->outbox}` (
                `id`                CHAR(36)                   NOT NULL,
                `event_type`        VARCHAR(255)               NOT NULL,
                `event_version`     INT                        NOT NULL,
                `aggregate_type`    VARCHAR(100)               NOT NULL,
                `aggregate_id`      VARCHAR(255)               NOT NULL,
                `aggregate_version` BIGINT                     NOT NULL,
                `source_updated_at` DATETIME                   NOT NULL,
                `checksum`          CHAR(64)                   NOT NULL,
                `correlation_id`    CHAR(36)                   NOT NULL,
                `causation_id`      CHAR(36)                   NULL,
                `payload`           JSON                       NOT NULL,
                `status`            ENUM('pending','relayed')  NOT NULL DEFAULT 'pending',
                `created_at`        DATETIME                   NOT NULL,
                `relayed_at`        DATETIME                   NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_relay_claim` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function createPgsqlSchema(): void
    {
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS commerce CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        pg_query($this->pgConn, 'CREATE SCHEMA system');
        pg_query($this->pgConn, 'CREATE SCHEMA content');

        pg_query($this->pgConn, "
            CREATE TABLE system.events (
                id                UUID         NOT NULL PRIMARY KEY,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INTEGER      NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                payload           JSONB        NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                checksum          VARCHAR(64)  NOT NULL,
                correlation_id    UUID         NOT NULL,
                causation_id      UUID         NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.queue_jobs (
                id                    UUID         NOT NULL PRIMARY KEY,
                event_id              UUID         NOT NULL,
                queue_name            VARCHAR(255) NOT NULL,
                status                VARCHAR(50)  NOT NULL,
                attempts              INTEGER      NOT NULL DEFAULT 0,
                available_at          TIMESTAMPTZ  NOT NULL,
                started_at            TIMESTAMPTZ  NULL,
                completed_at          TIMESTAMPTZ  NULL,
                last_error            TEXT         NULL,
                worker_id             UUID         NULL,
                visibility_timeout_at TIMESTAMPTZ  NULL,
                CONSTRAINT uq_queue_jobs_event_id UNIQUE (event_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.dead_letter_jobs (
                id               UUID        NOT NULL PRIMARY KEY,
                job_id           UUID        NOT NULL,
                event_id         UUID        NOT NULL,
                failure_reason   TEXT        NOT NULL,
                created_at       TIMESTAMPTZ NOT NULL,
                stack_trace      TEXT        NULL,
                attempt_count    INTEGER     NOT NULL DEFAULT 0,
                worker_id        UUID        NULL,
                payload_snapshot JSONB       NOT NULL,
                replayed_at      TIMESTAMPTZ NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_agg PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.processed_events (
                event_id     UUID        NOT NULL PRIMARY KEY,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE system.worker_heartbeats (
                worker_id         UUID        NOT NULL PRIMARY KEY,
                worker_type       TEXT        NOT NULL,
                status            TEXT        NOT NULL,
                last_heartbeat_at TIMESTAMPTZ NOT NULL,
                started_at        TIMESTAMPTZ NOT NULL
            )
        ");

        // Content's projection tables. Hand-written here rather than read from the module's
        // migrations because this harness only drives the three aggregates it seeds; Commerce's
        // come from the REAL files, which is what makes the descriptor check below meaningful.
        pg_query($this->pgConn, "
            CREATE TABLE content.pages (
                id UUID NOT NULL, source_post_id BIGINT NOT NULL, source_entity_type VARCHAR(50) NOT NULL DEFAULT 'page',
                slug VARCHAR(255) NOT NULL, title TEXT NOT NULL, content TEXT NOT NULL, status VARCHAR(50) NOT NULL,
                parent_id BIGINT NOT NULL DEFAULT 0, menu_order INTEGER NOT NULL DEFAULT 0,
                published_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, deleted_at TIMESTAMPTZ NULL,
                checksum VARCHAR(64) NOT NULL, meta_jsonb JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_pages PRIMARY KEY (id),
                CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.posts (
                id UUID NOT NULL, source_post_id BIGINT NOT NULL, source_entity_type VARCHAR(50) NOT NULL DEFAULT 'post',
                slug VARCHAR(255) NOT NULL, title TEXT NOT NULL, content TEXT NOT NULL, excerpt TEXT NOT NULL,
                status VARCHAR(50) NOT NULL, author VARCHAR(255) NOT NULL,
                published_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, deleted_at TIMESTAMPTZ NULL,
                checksum VARCHAR(64) NOT NULL, meta_jsonb JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_posts PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.taxonomies (
                id UUID NOT NULL, source_term_id BIGINT NOT NULL, taxonomy_type VARCHAR(50) NOT NULL,
                slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT NOT NULL DEFAULT '',
                parent_id BIGINT NOT NULL DEFAULT 0, post_count INTEGER NOT NULL DEFAULT 0,
                deleted_at TIMESTAMPTZ NULL, checksum VARCHAR(64) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL, updated_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_taxonomies PRIMARY KEY (id),
                CONSTRAINT uq_content_taxonomies_source_term_id UNIQUE (source_term_id)
            )
        ");
        pg_query($this->pgConn, "
            CREATE TABLE content.entity_taxonomies (
                entity_id UUID NOT NULL, taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ");

        // The featured-media column the content adapters write; owned by content migration 0007.
        ContentSchema::ensureFeaturedMediaSupport($this->pgConn);

        // Commerce's schema comes from the REAL migration files.
        CommerceSchema::applyAll($this->pgConn);
    }

    private function connectMysql(): \mysqli
    {
        $host = getenv('HSP_TEST_MYSQL_HOST')     ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_MYSQL_PORT') ?: 3306);
        $user = getenv('HSP_TEST_MYSQL_USER')     ?: 'root';
        $pass = getenv('HSP_TEST_MYSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_MYSQL_DATABASE') ?: 'test';

        mysqli_report(MYSQLI_REPORT_OFF);
        $mysqli = @new \mysqli($host, $user, $pass, $db, $port);

        if ($mysqli->connect_errno !== 0) {
            self::markTestSkipped("MySQL not available at {$host}:{$port}.");
        }

        return $mysqli;
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
