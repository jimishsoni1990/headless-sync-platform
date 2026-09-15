<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Commerce;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Queries\TermQueryProvider;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Validation\TermValidator;
use HSP\Tests\Support\CommerceSchema;
use HSP\Tests\Support\InMemoryCommerceLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `term_count` convergence through the normal Commerce taxonomy pipeline (FLAG-COMMTERMCOUNT-1).
 *
 * `commerce.taxonomies.term_count` — published as `count` — is `wp_term_taxonomy.count` read back
 * through `get_term()` and projected verbatim. It is therefore correct exactly when the taxonomy
 * aggregate is re-emitted after WordPress has recounted, and these tests hold the projection side
 * to that contract: whatever count the source reports at process time must land in
 * `commerce.taxonomies` and reach `TermResource`, and nothing in the write path — checksum
 * suppression above all — may swallow a change that moved only the count.
 *
 * The hook that produces those re-emissions (`edited_term_taxonomy`) is proven separately in
 * CommerceHookWiringTest; here WordPress is the in-memory loader, whose count is moved between
 * events exactly as a real recount would move it.
 *
 * BOTH families run, because they do NOT share a recount mechanism and the flag required that be
 * verified rather than assumed (WooCommerce 11.1.0):
 *
 *   pa_*         `update_count_callback => _update_post_term_count` — WordPress's own.
 *   product_cat  `update_count_callback => _wc_term_recount` — WooCommerce's own, which
 *                delegates to `_update_post_term_count()` for `wp_term_taxonomy.count` and then
 *                writes a SECOND, catalog-visibility-aware count to `wp_termmeta`. HSP projects
 *                the FIRST: the termmeta count only reaches `get_terms()` through the
 *                `wc_change_term_counts` filter, and `loadTerm()` uses `get_term()`.
 *
 * Different callbacks, one projected fact, one lifecycle — so one set of assertions, run twice.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class CommerceTermCountIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;
    private InMemoryCommerceLoader $loader;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->loader = new class extends InMemoryCommerceLoader {
        };

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

    /** @return array<string, array{0:string, 1:string}> taxonomy => aggregate type */
    public static function taxonomyProvider(): array
    {
        return [
            'product_cat (_wc_term_recount)'    => ['product_cat', 'product_category'],
            'pa_* (_update_post_term_count)'    => ['pa_color', 'attribute_term'],
        ];
    }

    // =========================================================================
    // Membership lifecycle — 0 → 1 → 2 → 1 → 0
    // =========================================================================

    #[DataProvider('taxonomyProvider')]
    public function test_membership_lifecycle_converges_on_every_count(string $taxonomy, string $aggregate): void
    {
        // The term is created before any product carries it — the exact shape that froze the
        // live values, since a term created during a product save starts at zero.
        $this->given(1, $taxonomy, count: 0);
        $this->project(1, $taxonomy, $aggregate, 'created', version: 1);

        self::assertSame(0, $this->termCount(1), 'created empty');

        foreach ([1, 2, 1, 0] as $i => $count) {
            $this->given(1, $taxonomy, $count);
            $this->project(1, $taxonomy, $aggregate, 'updated', version: $i + 2);

            self::assertSame($count, $this->termCount(1), "count after transition to {$count}");
        }
    }

    /**
     * Product STATUS transitions move the count too, and through the same signal:
     * `_update_term_count_on_transition_post_status` recounts on every
     * draft→publish→trash→publish move, and `_update_post_term_count()` counts `publish` only.
     */
    #[DataProvider('taxonomyProvider')]
    public function test_product_status_transitions_converge(string $taxonomy, string $aggregate): void
    {
        $this->given(2, $taxonomy, count: 0);           // product exists as a draft
        $this->project(2, $taxonomy, $aggregate, 'created', version: 1);

        foreach ([['publish', 1], ['draft', 0], ['publish', 1], ['trash', 0], ['publish', 1]] as $i => [$status, $count]) {
            $this->given(2, $taxonomy, $count);
            $this->project(2, $taxonomy, $aggregate, 'updated', version: $i + 2);

            self::assertSame($count, $this->termCount(2), "count after {$status}");
        }
    }

    // =========================================================================
    // Checksum: a count-only change must NOT be suppressed
    // =========================================================================

    #[DataProvider('taxonomyProvider')]
    public function test_a_change_to_the_count_alone_is_not_suppressed(string $taxonomy, string $aggregate): void
    {
        // Same slug, same name, same description, same parent — only the count moves. If
        // `count` were outside CanonicalTerm's digest, DECISION 3 write-suppression would
        // correctly conclude "nothing changed" and the projection would never move again.
        $this->given(3, $taxonomy, count: 2);
        $this->project(3, $taxonomy, $aggregate, 'created', version: 1);

        $checksumBefore = $this->column(3, 'checksum');

        $this->given(3, $taxonomy, count: 3);
        $this->project(3, $taxonomy, $aggregate, 'updated', version: 2);

        self::assertSame(3, $this->termCount(3));
        self::assertNotSame($checksumBefore, $this->column(3, 'checksum'), 'count is inside the digest');
    }

    #[DataProvider('taxonomyProvider')]
    public function test_a_genuinely_identical_re_emission_is_still_suppressed(string $taxonomy, string $aggregate): void
    {
        // The other half of the same rule: the non-dedupe at capture is only affordable because
        // an unchanged term does not rewrite its row.
        $this->given(4, $taxonomy, count: 4);
        $this->project(4, $taxonomy, $aggregate, 'created', version: 1);

        $syncedBefore = $this->column(4, 'synced_at');

        $this->project(4, $taxonomy, $aggregate, 'updated', version: 2);

        self::assertSame(4, $this->termCount(4));
        self::assertSame($syncedBefore, $this->column(4, 'synced_at'), 'no rewrite');
    }

    // =========================================================================
    // At-least-once and non-FIFO delivery
    // =========================================================================

    #[DataProvider('taxonomyProvider')]
    public function test_a_redelivered_count_event_is_idempotent(string $taxonomy, string $aggregate): void
    {
        $this->given(5, $taxonomy, count: 0);
        $this->project(5, $taxonomy, $aggregate, 'created', version: 1);

        $this->given(5, $taxonomy, count: 3);
        $event = $this->event($taxonomy, $aggregate, 5, 'updated', 2);

        $this->handler()->handle($event);
        $this->handler()->handle($event); // same event id, redelivered

        self::assertSame(3, $this->termCount(5));
        self::assertSame(2, $this->rowCount('system.processed_events'), 'ON CONFLICT DO NOTHING');
        self::assertSame(1, $this->rowCount('commerce.taxonomies'), 'no duplicate row');
    }

    #[DataProvider('taxonomyProvider')]
    public function test_an_out_of_order_older_count_event_does_not_regress(string $taxonomy, string $aggregate): void
    {
        // Non-FIFO delivery: a later recount arrives first. This is what makes capture-side
        // dedupe unnecessary AND makes it safe to emit a recount event per source recount.
        $this->given(6, $taxonomy, count: 1);
        $this->project(6, $taxonomy, $aggregate, 'created', version: 1);

        $this->given(6, $taxonomy, count: 3);
        $this->project(6, $taxonomy, $aggregate, 'updated', version: 7);

        // State-sync would reload count 3 anyway, so force the worst case: an older event
        // processed while the source still reports the value the term used to have.
        $this->given(6, $taxonomy, count: 2);
        $this->project(6, $taxonomy, $aggregate, 'updated', version: 3);

        self::assertSame(3, $this->termCount(6), 'version guard holds the newer count');
    }

    #[DataProvider('taxonomyProvider')]
    public function test_a_stale_count_converges_through_ordinary_re_emission(string $taxonomy, string $aggregate): void
    {
        // Replay and reconciliation both repair by re-emitting the aggregate through this same
        // pipeline (DECISION T / U) — no repair SQL, no backfill UPDATE. This is the path that
        // heals a site already serving a frozen count.
        $this->given(7, $taxonomy, count: 0);
        $this->project(7, $taxonomy, $aggregate, 'created', version: 1);
        self::assertSame(0, $this->termCount(7), 'the live defect, reproduced');

        $this->given(7, $taxonomy, count: 5); // WordPress has recounted since
        $this->project(7, $taxonomy, $aggregate, 'updated', version: 2);

        self::assertSame(5, $this->termCount(7));
    }

    // =========================================================================
    // Deletion — the Commerce/Content divergence
    // =========================================================================

    /**
     * `wp_delete_term()` reassigns the term's products BEFORE deleting it, which recounts and so
     * fires `edited_term_taxonomy` for the term about to cease to exist. Content had to suppress
     * that emit through `pre_delete_term` because its handler THROWS on a vanished term, which
     * would dead-letter an ordinary delete. Commerce's handler no-ops, so no suppression was
     * added — and that has to be proven, not asserted in a comment.
     */
    #[DataProvider('taxonomyProvider')]
    public function test_a_recount_for_a_term_being_deleted_does_not_fail(string $taxonomy, string $aggregate): void
    {
        $this->given(8, $taxonomy, count: 2);
        $this->project(8, $taxonomy, $aggregate, 'created', version: 1);

        // The reassignment recount lands after the term has already gone from WordPress.
        unset($this->loader->terms[8]);
        $this->project(8, $taxonomy, $aggregate, 'updated', version: 2);

        self::assertNull($this->column(8, 'deleted_at'), 'the stray upsert is a no-op, not a write');

        // The tombstone that follows carries the truth.
        (new TermTombstoneHandler(new TermAdapter($this->db)))
            ->handle($this->event($taxonomy, $aggregate, 8, 'deleted', 3));

        self::assertNotNull($this->column(8, 'deleted_at'));
    }

    /** Every OTHER term the reassignment touches genuinely gains products and must converge. */
    #[DataProvider('taxonomyProvider')]
    public function test_a_term_gaining_products_from_a_deletion_converges(string $taxonomy, string $aggregate): void
    {
        $this->given(9, $taxonomy, count: 1);
        $this->project(9, $taxonomy, $aggregate, 'created', version: 1);

        $this->given(9, $taxonomy, count: 3); // inherited the deleted term's products
        $this->project(9, $taxonomy, $aggregate, 'updated', version: 2);

        self::assertSame(3, $this->termCount(9));
    }

    // =========================================================================
    // Delivery
    // =========================================================================

    #[DataProvider('taxonomyProvider')]
    public function test_the_published_resource_carries_the_converged_count(string $taxonomy, string $aggregate): void
    {
        $this->given(10, $taxonomy, count: 0);
        $this->project(10, $taxonomy, $aggregate, 'created', version: 1);

        $this->given(10, $taxonomy, count: 4);
        $this->project(10, $taxonomy, $aggregate, 'updated', version: 2);

        $row = (new TermQueryProvider($this->db, $taxonomy))->findBySlug('term-10');
        self::assertNotNull($row);

        self::assertSame(4, (new TermResource())->toArray($row)['count']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function given(int $termId, string $taxonomy, int $count): void
    {
        $this->loader->terms[$termId] = [
            'term_id'     => $termId,
            'taxonomy'    => $taxonomy,
            'slug'        => "term-{$termId}",
            'name'        => "Term {$termId}",
            'description' => '',
            'parent'      => 0,
            'count'       => $count,
        ];
    }

    private function project(int $termId, string $taxonomy, string $aggregate, string $action, int $version): void
    {
        $this->handler()->handle($this->event($taxonomy, $aggregate, $termId, $action, $version));
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

    private function event(
        string $taxonomy,
        string $aggregate,
        int $termId,
        string $action,
        int $version,
    ): EventInterface {
        $eventType = $taxonomy === 'product_cat'
            ? "commerce.product_category.{$action}"
            : "commerce.attribute_term.{$action}";

        return new TermCountTestEvent($eventType, $aggregate, (string) $termId, $version);
    }

    private function termCount(int $termId): int
    {
        return (int) $this->column($termId, 'term_count');
    }

    private function column(int $termId, string $name): ?string
    {
        $rows = $this->db->query(
            "SELECT {$name} AS v FROM commerce.taxonomies WHERE source_term_id = $1",
            [$termId],
        );

        self::assertNotSame([], $rows, "no commerce.taxonomies row for term {$termId}");

        return $rows[0]['v'] === null ? null : (string) $rows[0]['v'];
    }

    private function rowCount(string $table): int
    {
        return (int) ($this->db->query("SELECT COUNT(*) AS c FROM {$table}")[0]['c'] ?? 0);
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

/** Carries its own aggregate type, so the two taxonomy families version independently. */
final class TermCountTestEvent implements EventInterface
{
    public function __construct(
        private readonly string $eventType,
        private readonly string $aggregateType,
        private readonly string $aggregateId,
        private readonly int $version,
    ) {
    }

    public function getId(): string
    {
        $key = $this->eventType . $this->aggregateId . $this->version;

        return sprintf('%s-0000-7000-8000-%012d', substr(md5($key), 0, 8), crc32($key) % 999999);
    }

    public function getEventType(): string { return $this->eventType; }
    public function getEventVersion(): int { return 1; }
    public function getAggregateType(): string { return $this->aggregateType; }
    public function getAggregateId(): string { return $this->aggregateId; }
    public function getAggregateVersion(): int { return $this->version; }
    /** @return array<string,mixed> */
    public function getPayload(): array { return ['term_id' => (int) $this->aggregateId]; }
    public function getChecksum(): string { return str_repeat('e', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2026-06-01T00:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000003'; }
    public function getCausationId(): ?string { return null; }
}
