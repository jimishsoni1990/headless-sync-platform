<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Handlers\CategoryUpsertHandler;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Resources\CategoryResource;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Tests\Support\ContentSchema;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * `post_count` convergence through the normal taxonomy pipeline (FLAG-TAGCOUNT-1).
 *
 * `post_count` is WordPress's own `wp_term_taxonomy.count`, projected verbatim
 * (ARCHITECTURE_DECISIONS.md DECISION AJ (AJ-4)) — so the value is correct exactly when the
 * taxonomy aggregate is re-emitted after WordPress has recounted. These tests hold the projection
 * side to that contract: whatever count WordPress reports at process time must land in
 * content.taxonomies and reach CategoryResource, and nothing in the write path — checksum
 * suppression above all — may swallow a change that moved only the count.
 *
 * The hook that produces those re-emissions (edited_term_taxonomy) is proven separately in
 * HookWiringTest; here WordPress is the FakeWpContentLoader, whose term count is moved between
 * events exactly as a real recount would move it.
 *
 * Tags and categories run the SAME lifecycle: one spine, one column, one defect. The live split
 * that raised the flag (tags wrong, categories right) was incidental — the categories had simply
 * been re-emitted by a backfill after their memberships settled, and the tags had not.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class TaxonomyPostCountIntegrationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Membership lifecycle — 0 → 1 → 2 → 1 → 0
    // =========================================================================

    /**
     * @param 'post_tag'|'category' $taxonomy
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('taxonomyProvider')]
    public function test_membership_lifecycle_converges_on_every_count(string $taxonomy, string $aggregate): void
    {
        $loader = $this->makeLoader(termId: 1, taxonomy: $taxonomy, count: 0);

        // The tag/category is created before anything is assigned to it — the exact shape that
        // froze the live tags at zero.
        $this->project($loader, $aggregate, 1, ContentEventTypes::TAG_CREATED, version: 1);
        self::assertSame(0, $this->fetchPostCount(1), 'created empty');

        foreach ([1, 2, 1, 0] as $i => $count) {
            $loader->termResult['count'] = $count;
            $this->project($loader, $aggregate, 1, ContentEventTypes::TAG_UPDATED, version: $i + 2);

            self::assertSame($count, $this->fetchPostCount(1), "count after transition to {$count}");
        }
    }

    /** @return array<string, array{string, string}> */
    public static function taxonomyProvider(): array
    {
        return [
            'tag'      => ['post_tag', 'tag'],
            'category' => ['category', 'category'],
        ];
    }

    // =========================================================================
    // Checksum: a count-only change must NOT be suppressed
    // =========================================================================

    public function test_a_change_to_the_count_alone_is_not_suppressed_as_unchanged(): void
    {
        // The critical case: same slug, same name, same description, same parent — only the count
        // moves. If `count` were outside CanonicalCategory's digest, DECISION 3 write-suppression
        // would correctly conclude "nothing changed" and the projection would never move again.
        $loader = $this->makeLoader(termId: 2, taxonomy: 'post_tag', count: 1);
        $this->project($loader, 'tag', 2, ContentEventTypes::TAG_CREATED, version: 1);

        $checksumBefore = $this->fetchColumn(2, 'checksum');

        $loader->termResult['count'] = 2;
        $this->project($loader, 'tag', 2, ContentEventTypes::TAG_UPDATED, version: 2);

        self::assertSame(2, $this->fetchPostCount(2));
        self::assertNotSame($checksumBefore, $this->fetchColumn(2, 'checksum'), 'count is inside the digest');
    }

    public function test_a_genuinely_identical_re_emission_is_still_suppressed(): void
    {
        // The other half of the same rule: re-emitting an unchanged term must not rewrite the row.
        $loader = $this->makeLoader(termId: 3, taxonomy: 'category', count: 4);
        $this->project($loader, 'category', 3, ContentEventTypes::CATEGORY_CREATED, version: 1);

        $syncedBefore = $this->fetchColumn(3, 'synced_at');

        $this->project($loader, 'category', 3, ContentEventTypes::CATEGORY_UPDATED, version: 2);

        self::assertSame(4, $this->fetchPostCount(3));
        self::assertSame($syncedBefore, $this->fetchColumn(3, 'synced_at'), 'no rewrite');
    }

    // =========================================================================
    // At-least-once and non-FIFO delivery
    // =========================================================================

    public function test_a_redelivered_count_event_is_idempotent(): void
    {
        $loader = $this->makeLoader(termId: 4, taxonomy: 'post_tag', count: 0);
        $this->project($loader, 'tag', 4, ContentEventTypes::TAG_CREATED, version: 1);

        $loader->termResult['count'] = 3;
        $event = $this->makeEvent(ContentEventTypes::TAG_UPDATED, 'tag', '4', 2);

        $handler = $this->makeHandler($loader);
        $handler->handle($event);
        $handler->handle($event); // same event id, redelivered

        self::assertSame(3, $this->fetchPostCount(4));
        self::assertSame(2, $this->countRows('system.processed_events'), 'ON CONFLICT DO NOTHING');
    }

    public function test_an_out_of_order_older_count_event_does_not_regress_the_projection(): void
    {
        // Non-FIFO delivery: the recount emitted by a later post save arrives first.
        $loader = $this->makeLoader(termId: 5, taxonomy: 'post_tag', count: 0);
        $this->project($loader, 'tag', 5, ContentEventTypes::TAG_CREATED, version: 1);

        $loader->termResult['count'] = 2;
        $this->project($loader, 'tag', 5, ContentEventTypes::TAG_UPDATED, version: 7);

        // Now the stale one lands. State-sync would reload count 2 anyway, so force the worst
        // case: an older event carrying the value the term used to have.
        $loader->termResult['count'] = 1;
        $this->project($loader, 'tag', 5, ContentEventTypes::TAG_UPDATED, version: 3);

        self::assertSame(2, $this->fetchPostCount(5), 'version guard holds the newer count');
    }

    public function test_a_stale_count_converges_through_ordinary_re_emission(): void
    {
        // Replay and reconciliation both repair by re-emitting the aggregate through this same
        // pipeline (DECISION T / U) — no repair SQL, no backfill UPDATE. If a site is already
        // serving a frozen count, this is the path that fixes it.
        $loader = $this->makeLoader(termId: 6, taxonomy: 'post_tag', count: 0);
        $this->project($loader, 'tag', 6, ContentEventTypes::TAG_CREATED, version: 1);
        self::assertSame(0, $this->fetchPostCount(6), 'the live defect, reproduced');

        $loader->termResult['count'] = 3; // WordPress has recounted since
        $this->project($loader, 'tag', 6, ContentEventTypes::TAG_UPDATED, version: 2);

        self::assertSame(3, $this->fetchPostCount(6));
    }

    // =========================================================================
    // Delivery
    // =========================================================================

    public function test_the_published_resource_carries_the_converged_count(): void
    {
        $loader = $this->makeLoader(termId: 7, taxonomy: 'post_tag', count: 0);
        $this->project($loader, 'tag', 7, ContentEventTypes::TAG_CREATED, version: 1);

        $loader->termResult['count'] = 3;
        $this->project($loader, 'tag', 7, ContentEventTypes::TAG_UPDATED, version: 2);

        $row = (new CategoryQueryProvider($this->db, 'post_tag'))->findBySlug('term-7');
        self::assertNotNull($row);

        self::assertSame(3, (new CategoryResource())->toArray($row)['post_count']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function project(
        FakeWpContentLoader $loader,
        string              $aggregate,
        int                 $termId,
        string              $eventType,
        int                 $version,
    ): void {
        // TAG_* and CATEGORY_* resolve to the same handler; only the aggregate type differs.
        $eventType = $aggregate === 'category'
            ? str_replace('content.tag.', 'content.category.', $eventType)
            : str_replace('content.category.', 'content.tag.', $eventType);

        $this->makeHandler($loader)->handle(
            $this->makeEvent($eventType, $aggregate, (string) $termId, $version)
        );
    }

    private function makeHandler(FakeWpContentLoader $loader): CategoryUpsertHandler
    {
        return new CategoryUpsertHandler(
            $loader,
            new CategoryExtractor(new CategoryValidator()),
            new CategoryTransformer(),
            new CategoryAdapter($this->db),
        );
    }

    private function makeLoader(int $termId, string $taxonomy, int $count): FakeWpContentLoader
    {
        $loader = new FakeWpContentLoader();
        $loader->termResult = [
            'term_id'     => $termId,
            'name'        => "Term {$termId}",
            'slug'        => "term-{$termId}",
            'description' => '',
            'parent'      => 0,
            'count'       => $count,
            'taxonomy'    => $taxonomy,
        ];

        return $loader;
    }

    private function makeEvent(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int    $aggregateVersion,
    ): TaxonomyCountTestEvent {
        return new TaxonomyCountTestEvent(
            id:               $this->newUuid(),
            eventType:        $eventType,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: $aggregateVersion,
        );
    }

    private function fetchPostCount(int $sourceTermId): int
    {
        return (int) $this->fetchColumn($sourceTermId, 'post_count');
    }

    private function fetchColumn(int $sourceTermId, string $column): string
    {
        $result = pg_query_params(
            $this->pgConn,
            "SELECT {$column} AS v FROM content.taxonomies WHERE source_term_id = $1",
            [$sourceTermId]
        );
        self::assertNotFalse($result);

        $row = pg_fetch_assoc($result) ?: null;
        pg_free_result($result);
        self::assertNotNull($row, "no content.taxonomies row for term {$sourceTermId}");

        return (string) $row['v'];
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

    private function newUuid(): string
    {
        $bytes = random_bytes(10);
        $hex   = sprintf('%012x', (int) (microtime(true) * 1000))
            . sprintf('%04x', 0x7000 | ((ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1])))
            . sprintf('%04x', 0x8000 | ((ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3])))
            . bin2hex(substr($bytes, 4, 6));

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
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
            PGSQL_CONNECT_FORCE_NEW
        );

        if ($conn === false) {
            self::markTestSkipped("PostgreSQL not available at {$host}:{$port} — skipping taxonomy count tests.");
        }

        return $conn;
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

        ContentSchema::ensureTaxonomySupport($this->pgConn);
    }
}

// -------------------------------------------------------------------------
// Test-local event stub
// -------------------------------------------------------------------------

/** Minimal EventInterface stub — these tests only vary type, aggregate and version. */
final class TaxonomyCountTestEvent implements \HSP\Core\Contracts\EventInterface
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
    public function getChecksum(): string                    { return hash('sha256', $this->id . $this->aggregateVersion); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable       { return new \DateTimeImmutable('2024-01-01T00:00:00Z'); }
    public function getCorrelationId(): string               { return '01900000-0000-7000-8000-000000000099'; }
    public function getCausationId(): ?string                { return null; }
}
