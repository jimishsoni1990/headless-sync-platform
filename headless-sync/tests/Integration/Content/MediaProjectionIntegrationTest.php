<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Contracts\FilterSet;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Adapters\MediaAdapter;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Extractors\MediaExtractor;
use HSP\Modules\Content\Handlers\MediaTombstoneHandler;
use HSP\Modules\Content\Handlers\MediaUpsertHandler;
use HSP\Modules\Content\Queries\MediaQueryProvider;
use HSP\Modules\Content\Transformers\MediaTransformer;
use HSP\Modules\Content\Validation\MediaValidator;
use HSP\Tests\Unit\Content\FakeWpContentLoader;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the content.media projection (P1B-S1 DoD).
 *
 * Exercises handler → adapter → live PostgreSQL, and the delivery-side query provider
 * against the same live schema.
 *
 * The schema is created by executing the REAL migration SQL file rather than a hand-copied
 * DDL block. Seeding DDL by hand is exactly what let migration 0011 ship unwired and unnoticed
 * (FLAG-ONBS2-1): the tests passed while a fresh install had no such table. Running the real
 * file means these tests also prove the migration itself, indexes included — which the
 * index-backed assertions below depend on.
 *
 * No WordPress bootstrap required — WpContentLoader is replaced by FakeWpContentLoader.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class MediaProjectionIntegrationTest extends TestCase
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
    // Projection round-trip
    // =========================================================================

    public function test_upsert_handler_writes_the_projection_and_records_all_three_ops(): void
    {
        $this->upsertHandler()->handle($this->event(ContentEventTypes::MEDIA_CREATED, 1));

        self::assertSame(1, $this->countRows('content.media'));
        self::assertSame(1, $this->countRows('system.processed_events'));
        self::assertSame(1, $this->countRows('system.aggregate_versions'));

        $row = $this->db->query('SELECT * FROM content.media')[0];
        self::assertSame('sunset', $row['slug']);
        self::assertSame('image/jpeg', $row['mime_type']);
        self::assertSame('A sunset over water', $row['alt_text']);
        self::assertSame(1600, (int) $row['width']);

        // Size variants land already resolved to absolute URLs (Rule 2: the read path
        // assembles nothing).
        $sizes = json_decode((string) $row['sizes_jsonb'], true);
        self::assertSame(
            'https://example.test/wp-content/uploads/2024/01/sunset-150x150.jpg',
            $sizes['thumbnail']['url'],
        );
    }

    public function test_reprocessing_unchanged_media_performs_zero_projection_writes(): void
    {
        $handler = $this->upsertHandler();
        $handler->handle($this->event(ContentEventTypes::MEDIA_CREATED, 1));

        $before = $this->db->query('SELECT synced_at, checksum FROM content.media')[0];

        // A second, higher-versioned event for identical content: the freshly computed
        // projection checksum matches the stored one, so DECISION 3 suppresses the upsert.
        $handler->handle($this->event(ContentEventTypes::MEDIA_UPDATED, 2));

        $after = $this->db->query('SELECT synced_at, checksum FROM content.media')[0];

        self::assertSame($before['checksum'], $after['checksum']);
        self::assertSame($before['synced_at'], $after['synced_at'], 'no projection write occurred');
        self::assertSame(2, $this->countRows('system.processed_events'), 'both events still recorded');
    }

    public function test_a_changed_alt_text_is_projected(): void
    {
        $loader  = new FakeWpContentLoader();
        $handler = $this->upsertHandler($loader);
        $handler->handle($this->event(ContentEventTypes::MEDIA_CREATED, 1));

        $attachment = $loader->attachmentResult;
        $attachment['hsp_alt'] = 'Updated alternative text';
        $loader->attachmentResult = $attachment;

        $handler->handle($this->event(ContentEventTypes::MEDIA_UPDATED, 2));

        $row = $this->db->query('SELECT alt_text FROM content.media')[0];
        self::assertSame('Updated alternative text', $row['alt_text']);
    }

    public function test_tombstone_soft_deletes_and_removes_the_item_from_the_listing(): void
    {
        $this->upsertHandler()->handle($this->event(ContentEventTypes::MEDIA_CREATED, 1));

        (new MediaTombstoneHandler(new MediaAdapter($this->db)))
            ->handle($this->event(ContentEventTypes::MEDIA_DELETED, 2));

        $row = $this->db->query('SELECT deleted_at FROM content.media')[0];
        self::assertNotNull($row['deleted_at']);

        // Soft-deleted media must disappear from the published contract.
        self::assertCount(0, (new MediaQueryProvider($this->db))->list(new FilterSet())->rows);
        self::assertNull((new MediaQueryProvider($this->db))->findBySlug('sunset'));
    }

    // =========================================================================
    // Performance DoD — index-backed access paths
    // =========================================================================

    public function test_the_listing_query_is_index_backed_at_scale(): void
    {
        $this->seedMedia(2000);

        $plan = $this->explain(
            "SELECT id, slug, published_at FROM content.media
             WHERE deleted_at IS NULL
             ORDER BY published_at DESC, id DESC
             LIMIT 21"
        );

        self::assertStringNotContainsString(
            'Seq Scan',
            $plan,
            "The media listing must be index-backed at the 100,000+ record target — "
            . "idx_content_media_live_published_at exists for exactly this query.\nPlan:\n{$plan}",
        );
    }

    public function test_the_slug_lookup_is_index_backed_at_scale(): void
    {
        $this->seedMedia(2000);

        $plan = $this->explain(
            "SELECT id FROM content.media WHERE slug = 'seed-1500' AND deleted_at IS NULL LIMIT 1"
        );

        self::assertStringNotContainsString('Seq Scan', $plan, "Plan:\n{$plan}");
    }

    // =========================================================================
    // Cursor pagination — the stability guarantee, on real rows
    // =========================================================================

    public function test_paging_over_rows_sharing_a_published_at_skips_and_duplicates_nothing(): void
    {
        // Every row shares one published_at, so ONLY the id tiebreaker separates them —
        // the case that a naive cursor gets wrong.
        $this->seedMedia(25, sharedTimestamp: true);

        $provider = new MediaQueryProvider($this->db);
        $seen     = [];
        $cursor   = null;

        do {
            $page = $provider->list(new FilterSet(cursor: $cursor, limit: 7));
            foreach ($page->rows as $row) {
                $seen[] = $row['slug'];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        self::assertCount(25, $seen, 'every row was returned exactly once');
        self::assertCount(25, array_unique($seen), 'no row was returned twice');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function upsertHandler(?FakeWpContentLoader $loader = null): MediaUpsertHandler
    {
        return new MediaUpsertHandler(
            $loader ?? new FakeWpContentLoader(),
            new MediaExtractor(new MediaValidator()),
            new MediaTransformer(),
            new MediaAdapter($this->db),
        );
    }

    private function event(string $eventType, int $version): FakeMediaEvent
    {
        return new FakeMediaEvent($eventType, $version);
    }

    private function seedMedia(int $count, bool $sharedTimestamp = false): void
    {
        for ($i = 1; $i <= $count; $i++) {
            // Shared: every row carries the SAME published_at, so only the id tiebreaker
            // separates them. Distinct: one row per minute, the ordinary case.
            $publishedAt = $sharedTimestamp
                ? '2024-01-01 00:00:00+00'
                : (new \DateTimeImmutable('2024-01-01T00:00:00Z'))
                    ->modify("+{$i} minutes")
                    ->format('Y-m-d H:i:sP');

            $this->db->execute(
                "INSERT INTO content.media
                    (id, source_post_id, slug, title, mime_type, url, published_at, updated_at,
                     checksum, created_at, synced_at)
                 VALUES (gen_random_uuid(), \$1, \$2, 'Seed', 'image/jpeg', 'https://example.test/s.jpg',
                         \$3::timestamptz, \$3::timestamptz, repeat('a', 64), now(), now())",
                [1000 + $i, 'seed-' . $i, $publishedAt]
            );
        }

        // Without fresh statistics the planner may prefer a sequential scan purely because it
        // believes the table is tiny.
        $this->db->execute('ANALYZE content.media');
    }

    private function explain(string $sql): string
    {
        $rows = $this->db->query('EXPLAIN ' . $sql);
        return implode("\n", array_map(static fn (array $r): string => (string) reset($r), $rows));
    }

    private function countRows(string $table): int
    {
        $rows = $this->db->query("SELECT COUNT(*) AS c FROM {$table}");
        return (int) ($rows[0]['c'] ?? 0);
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

        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');

        // The REAL migration file — see the class docblock for why this is not copied DDL.
        $sql = file_get_contents(__DIR__ . '/../../../modules/Content/Migrations/0006_create_content_media.sql');
        self::assertIsString($sql, 'the media migration SQL file must be readable');
        $result = pg_query($this->pgConn, $sql);
        self::assertNotFalse($result, 'the media migration must apply cleanly: ' . pg_last_error($this->pgConn));
    }

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
            self::markTestSkipped(
                "PostgreSQL not available at {$host}:{$port} — skipping media projection integration tests."
            );
        }

        return $conn;
    }
}

/**
 * Minimal EventInterface double for media events.
 */
final class FakeMediaEvent implements \HSP\Core\Contracts\EventInterface
{
    private readonly string $id;

    public function __construct(
        private readonly string $eventType,
        private readonly int $aggregateVersion,
    ) {
        $this->id = sprintf('01900000-0000-7000-8000-%012d', $aggregateVersion);
    }

    public function getId(): string            { return $this->id; }
    public function getEventType(): string     { return $this->eventType; }
    public function getEventVersion(): int     { return 1; }
    public function getAggregateType(): string { return 'media'; }
    public function getAggregateId(): string   { return '42'; }
    public function getAggregateVersion(): int { return $this->aggregateVersion; }
    public function getPayload(): array        { return []; }
    public function getChecksum(): string      { return str_repeat('b', 64); }
    public function getSourceUpdatedAt(): \DateTimeImmutable { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCreatedAt(): \DateTimeImmutable       { return new \DateTimeImmutable('2024-06-01T10:00:00Z'); }
    public function getCorrelationId(): string { return '01900000-0000-7000-8000-000000000002'; }
    public function getCausationId(): ?string  { return null; }
}
