<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Gate;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use HSP\Modules\Content\SourceModels\PostSourceModel;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Tests\Unit\Content\Adapters\FakeAdapterEvent;
use PHPUnit\Framework\TestCase;
use HSP\Tests\Support\ContentSchema;

/**
 * GATE-S4 — Architecture Validation Gate: Extensibility Validation.
 *
 * IMPLEMENTATION_PLAN.md §4 → Extensibility Validation criteria (verbatim):
 *   1. Add a new content field to a projection without modifying `core/`.
 *   2. Add a new projection column without modifying transformer or canonical model.
 *   3. Add a new API resource without modifying existing endpoints.
 *
 * This is a GATE session: EVIDENCE ONLY, no production code changes. The point is to prove
 * the extension SEAMS exist — that each of the three additions is achievable purely by
 * ADDING module-scoped code that rides the EXISTING, UNMODIFIED core contracts, without
 * editing the components the criterion names. Per the brief, each test must ASSERT the
 * NEGATIVE half of its criterion (evidence, not assumption):
 *
 *   - criterion 1 asserts ZERO changes under `core/` (the field flowed through module code
 *     only, on the existing CanonicalModelInterface / AdapterInterface seam).
 *   - criterion 2 asserts ZERO changes to the real PostTransformer + CanonicalPost (the new
 *     projection column was populated by an adapter from the UNMODIFIED canonical output).
 *   - criterion 3 asserts ZERO changes to the existing REST endpoint code (registrar,
 *     Resources, Query Providers); the new resource rides the existing ResourceInterface /
 *     QueryProviderInterface contracts.
 *
 * The negative half is proven mechanically with `git status --porcelain -- <path>`: it
 * reports BOTH modifications to tracked files AND new untracked files inside the guarded
 * directory. An empty result is the evidence that the demonstration touched nothing there.
 * (A guarded-path check that could not run — e.g. no git — self-skips, never silently
 * passes.) All additions in this file are test-scoped throwaways (test schemas, test
 * doubles): the extension SEAM is what is under test, not a Phase 1B feature.
 *
 * The positive half runs against LIVE PostgreSQL using the REAL runtime where the criterion
 * names a real component:
 *   - criterion 1 persists through the real PostAdapter into a real-shaped content.posts.
 *   - criterion 2 drives the real, unmodified PostTransformer → CanonicalPost, then a
 *     test adapter derives + writes a NEW real column into a throwaway projection table.
 *   - criterion 3 reads a live content.authors table through a new Query Provider + Resource
 *     that implement the real core contracts.
 *
 * Environment (self-skips if a DB is genuinely absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class ExtensibilityValidationTest extends TestCase
{
    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    /** Repository root — resolved once; used by the guarded-path negative assertions. */
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->pgConn   = $this->connectPgsql();
        $this->db       = new PostgresDatabaseConnection($this->pgConn);
        $this->repoRoot = $this->resolveRepoRoot();

        $this->createPgsqlSchema();
        // P1B-S2: the content query providers LEFT JOIN content.media to resolve the
        // featured image, so any test touching them needs that table plus the
        // featured_media_id column present.
        ContentSchema::ensureFeaturedMediaSupport($this->pgConn);
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
    // Criterion 1 — Add a new content field to a projection without modifying core/.
    //   §4: "Add a new content field to a projection without modifying core/."
    //   Seam: a content field flows SourceModel → Transformer → CanonicalModel → Adapter →
    //   projection entirely within the module. The canonical model carries the field on the
    //   EXISTING CanonicalModelInterface (a marker contract — getSourceId/getChecksum only),
    //   so a new field requires NO new core method and NO core edit. Here a test-scoped
    //   canonical model + transformer add a `reading_time` content field; it is carried into
    //   the real content.posts projection via meta_jsonb by the REAL PostAdapter — no new
    //   column, no core change. The field is then visible on read-back.
    // =========================================================================

    public function test_criterion1_a_new_content_field_reaches_the_projection_without_touching_core(): void
    {
        // A new content field ("reading_time") added purely in a module-scoped transformer.
        // It rides the EXISTING CanonicalPost.meta map — a first-class extension point already
        // present on the canonical model — so it needs NO new canonical field, NO new column,
        // and NO core change. The REAL PostAdapter already projects meta into meta_jsonb, so it
        // carries the new field with no adapter change either.
        $source    = $this->postSourceModel(2000, 'extensible-field');
        $canonical = (new ContentFieldExtendingTransformer())->transform($source);

        self::assertInstanceOf(
            CanonicalModelInterface::class,
            $canonical,
            'the extended content rides the EXISTING core contract (no new core interface)'
        );
        self::assertSame(CanonicalPost::class, $canonical::class, 'the field rides the real CanonicalPost — no new canonical class needed');
        self::assertArrayHasKey('reading_time', $canonical->meta, 'the new content field is present on the canonical model');

        // Persist through the REAL PostAdapter into the REAL content.posts projection.
        $adapter = new PostAdapter($this->db);
        $event   = new FakeAdapterEvent(
            id: $this->uuidv7(),
            eventType: 'content.post.created',
            aggregateType: 'post',
            aggregateId: '2000',
            aggregateVersion: 1,
        );
        $adapter->persist($canonical, $event);

        // The new content field reached the projection (carried in meta_jsonb — no new column).
        $row = $this->fetchRow('content.posts', 'source_post_id', 2000);
        self::assertNotNull($row, 'the post projected via the real adapter');
        $meta = json_decode((string) $row['meta_jsonb'], true);
        self::assertIsArray($meta);
        self::assertArrayHasKey('reading_time', $meta, 'the NEW content field reached the projection');
        self::assertSame('7', (string) $meta['reading_time'], 'the new content field value is projected faithfully');

        // ---- NEGATIVE HALF: adding the field required ZERO changes under core/. ----
        $this->assertPathUnchanged('headless-sync/core/', 'a new content field was added without modifying core/');
    }

    // =========================================================================
    // Criterion 2 — Add a new projection column without modifying transformer or canonical model.
    //   §4: "Add a new projection column without modifying transformer or canonical model."
    //   Seam: the adapter maps canonical output → columns; a NEW stored column can be added
    //   to a projection and populated by an adapter FROM the unmodified canonical output,
    //   with the transformer and canonical model byte-identical. Here a throwaway projection
    //   table (real content.posts shape + a NEW real column `word_count`) is written by a
    //   test-scoped adapter that derives word_count from the canonical model produced by the
    //   REAL, UNMODIFIED PostTransformer + CanonicalPost.
    // =========================================================================

    public function test_criterion2_a_new_projection_column_is_populated_from_the_unmodified_canonical_model(): void
    {
        // Drive the REAL, UNMODIFIED transformer + canonical model.
        $transformer = new PostTransformer();
        $source      = $this->postSourceModel(2100, 'new-column', content: 'one two three four five');
        $canonical   = $transformer->transform($source);

        self::assertInstanceOf(CanonicalPost::class, $canonical, 'the real production transformer produced the real CanonicalPost');
        self::assertSame(CanonicalPost::class, $canonical::class, 'no canonical subclass was introduced — the real class is used as-is');

        // A test-scoped adapter writes a NEW real column (word_count) into a throwaway
        // projection table, deriving it from the canonical model — the canonical model and
        // transformer are untouched; the new column lives only in the adapter + schema.
        $adapter = new ProjectionColumnExtendingAdapter($this->db);
        $event   = new FakeAdapterEvent(
            id: $this->uuidv7(),
            eventType: 'content.post.created',
            aggregateType: 'post',
            aggregateId: '2100',
            aggregateVersion: 1,
        );
        $adapter->persist($canonical, $event);

        // The new column is populated in the projection, derived from unmodified canonical output.
        $row = $this->fetchRow('content.posts_ext', 'source_post_id', 2100);
        self::assertNotNull($row, 'the extended projection row was written');
        self::assertArrayHasKey('word_count', $row, 'the NEW projection column exists');
        self::assertSame(5, (int) $row['word_count'], 'the new column was populated from the unmodified canonical model');
        self::assertSame('new-column', $row['slug'], 'existing canonical fields still project unchanged');

        // ---- NEGATIVE HALF: the transformer and canonical model were NOT modified. ----
        $this->assertPathUnchanged(
            'headless-sync/modules/Content/Transformers/PostTransformer.php',
            'a new projection column was added without modifying the transformer'
        );
        $this->assertPathUnchanged(
            'headless-sync/modules/Content/CanonicalModels/CanonicalPost.php',
            'a new projection column was added without modifying the canonical model'
        );
    }

    // =========================================================================
    // Criterion 3 — Add a new API resource without modifying existing endpoints.
    //   §4: "Add a new API resource without modifying existing endpoints."
    //   Seam: core owns ResourceInterface + QueryProviderInterface; endpoints are added by
    //   IMPLEMENTING those contracts + registering a new route (Doc 9 §6/§8/§11). A brand-new
    //   resource ("authors") is added by a test-scoped Query Provider + Resource that
    //   implement the real core contracts and read a live content.authors table — the
    //   existing registrar, Resources, and Query Providers are never touched.
    // =========================================================================

    public function test_criterion3_a_new_api_resource_is_served_without_touching_existing_endpoints(): void
    {
        // Seed a live content.authors projection (the new resource's backing table).
        $this->seedAuthor('ada-lovelace', 'Ada Lovelace', 3);
        $this->seedAuthor('alan-turing', 'Alan Turing', 1);

        // A NEW Query Provider + Resource, each implementing the EXISTING core contracts.
        $query    = new AuthorQueryProvider($this->db);
        $resource = new AuthorResource();

        self::assertInstanceOf(QueryProviderInterface::class, $query, 'the new query provider rides the EXISTING core contract');
        self::assertInstanceOf(ResourceInterface::class, $resource, 'the new resource rides the EXISTING core contract');

        // Listing endpoint behaviour: contract-shaped envelope from projection rows.
        $page       = $query->list(new FilterSet());
        $collection = $resource->toCollection($page->rows, $page->nextCursor);

        self::assertArrayHasKey('data', $collection, 'collection envelope has data (ResourceInterface contract)');
        self::assertArrayHasKey('next_cursor', $collection, 'collection envelope has next_cursor (ResourceInterface contract)');
        self::assertCount(2, $collection['data'], 'both seeded authors are served by the new resource');
        self::assertSame(['name', 'post_count', 'slug'], $this->sortedKeys($collection['data'][0]), 'the new resource shapes its own contract fields');

        // Single-resource endpoint behaviour via the contract's findBySlug + toArray.
        $single = $query->findBySlug('ada-lovelace');
        self::assertNotNull($single, 'the new resource resolves a single item by slug');
        $body = $resource->toArray($single);
        self::assertSame('Ada Lovelace', $body['name']);
        self::assertSame(3, $body['post_count']);

        // ---- NEGATIVE HALF: existing endpoints (registrar, Resources, Query Providers) untouched. ----
        $this->assertPathUnchanged(
            'headless-sync/modules/Content/Rest/ContentRestRegistrar.php',
            'a new API resource was added without modifying the existing endpoint registrar'
        );
        $this->assertPathUnchanged(
            'headless-sync/modules/Content/Resources/',
            'a new API resource was added without modifying existing Resources'
        );
        $this->assertPathUnchanged(
            'headless-sync/modules/Content/Queries/',
            'a new API resource was added without modifying existing Query Providers'
        );
    }

    // =========================================================================
    // Negative-half mechanism — guarded-path unchanged assertion.
    // =========================================================================

    /**
     * Assert that a repo-relative path has NO working-tree changes: no modification to a
     * tracked file and no new untracked file within it. `git status --porcelain -- <path>`
     * reports both, so an empty result is the evidence the demonstration touched nothing there.
     *
     * If git cannot be consulted (not a repo / git absent), the guarded assertion cannot be
     * proven, so the test SKIPS rather than passing on an unverifiable claim.
     */
    private function assertPathUnchanged(string $repoRelativePath, string $because): void
    {
        $porcelain = $this->gitPorcelain($repoRelativePath);
        if ($porcelain === null) {
            self::markTestSkipped('git unavailable — cannot prove the guarded-path negative assertion.');
        }
        self::assertSame(
            '',
            $porcelain,
            "{$because}: expected no working-tree changes under {$repoRelativePath}, got:\n{$porcelain}"
        );
    }

    /** @return string|null trimmed porcelain output, or null if git could not be run. */
    private function gitPorcelain(string $repoRelativePath): ?string
    {
        $cmd = sprintf(
            'git -C %s status --porcelain -- %s 2>&1',
            escapeshellarg($this->repoRoot),
            escapeshellarg($repoRelativePath),
        );
        $output   = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return null;
        }
        return trim(implode("\n", $output));
    }

    private function resolveRepoRoot(): string
    {
        // tests/Integration/Gate/ → repo root is four levels up from this file's dir,
        // but resolve via git to be robust to layout.
        $dir      = __DIR__;
        $output   = [];
        $exitCode = 1;
        exec(sprintf('git -C %s rev-parse --show-toplevel 2>&1', escapeshellarg($dir)), $output, $exitCode);
        if ($exitCode === 0 && isset($output[0]) && $output[0] !== '') {
            return trim($output[0]);
        }
        // Fallback: headless-sync/tests/Integration/Gate → up 4 = repo root.
        return dirname(__DIR__, 4);
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function postSourceModel(int $postId, string $slug, string $content = 'body'): PostSourceModel
    {
        return new PostSourceModel(
            postId:      $postId,
            title:       'Title ' . $postId,
            content:     $content,
            excerpt:     'excerpt',
            slug:        $slug,
            status:      'publish',
            author:      'author-login',
            publishedAt: new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            modifiedAt:  new \DateTimeImmutable('2026-07-02T00:00:00Z'),
            categoryIds: [],
            meta:        [],
        );
    }

    private function seedAuthor(string $slug, string $name, int $postCount): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO content.authors (id, slug, name, post_count, created_at, synced_at)
             VALUES ($1::uuid, $2, $3, $4, now(), now())",
            [$this->uuidv7(), $slug, $name, $postCount],
        );
    }

    /** @return array<string,mixed>|null */
    private function fetchRow(string $table, string $keyCol, int $keyVal): ?array
    {
        $r = pg_query_params($this->pgConn, "SELECT * FROM {$table} WHERE {$keyCol} = \$1", [$keyVal]);
        return pg_fetch_assoc($r) ?: null;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return list<string> sorted keys of the first item (order-independent shape check)
     */
    private function sortedKeys(array $items): array
    {
        $keys = array_keys($items);
        sort($keys);
        return $keys;
    }

    // =========================================================================
    // Schema — real content.posts shape + throwaway extension tables.
    // =========================================================================

    private function createPgsqlSchema(): void
    {
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        pg_query($this->pgConn, 'CREATE SCHEMA system');
        pg_query($this->pgConn, 'CREATE SCHEMA content');

        // system tables the real PostAdapter (DECISION 3) writes to.
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

        // Real content.posts shape (criterion 1 persists through the real PostAdapter).
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
            CREATE TABLE content.entity_taxonomies (
                entity_id UUID NOT NULL, taxonomy_id UUID NOT NULL,
                CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
            )
        ");

        // Throwaway projection table with a NEW real column `word_count` (criterion 2).
        pg_query($this->pgConn, "
            CREATE TABLE content.posts_ext (
                id UUID NOT NULL, source_post_id BIGINT NOT NULL,
                slug VARCHAR(255) NOT NULL, title TEXT NOT NULL,
                word_count INTEGER NOT NULL,
                checksum VARCHAR(64) NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_posts_ext PRIMARY KEY (id),
                CONSTRAINT uq_content_posts_ext_source_post_id UNIQUE (source_post_id)
            )
        ");

        // Throwaway projection table backing the NEW /authors resource (criterion 3).
        pg_query($this->pgConn, "
            CREATE TABLE content.authors (
                id UUID NOT NULL, slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL,
                post_count INTEGER NOT NULL DEFAULT 0, deleted_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL, synced_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_content_authors PRIMARY KEY (id),
                CONSTRAINT uq_content_authors_slug UNIQUE (slug)
            )
        ");
    }

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_PGSQL_PORT') ?: 5432);
        $user = getenv('HSP_TEST_PGSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            $this->markTestSkipped('PostgreSQL env vars not set (HSP_TEST_PGSQL_USER, HSP_TEST_PGSQL_DATABASE).');
        }

        $conn = @pg_connect("host={$host} port={$port} dbname={$db} user={$user} password={$pass}", PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            $this->markTestSkipped("PostgreSQL connect failed: host={$host} port={$port} dbname={$db}");
        }
        return $conn;
    }

    private function uuidv7(): string
    {
        $ms      = (int) (microtime(true) * 1000);
        $bytes   = random_bytes(10);
        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));
        $hex     = $tsHex . $b67hex . $b89hex . $tailHex;
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}

// =============================================================================
// Test-scoped extension demonstrations. These are the "additions" each criterion
// requires — deliberately living in the test file (throwaways) so the proof is that
// the SEAM exists, not that a Phase 1B feature is shipped. Each rides an EXISTING core
// contract and touches none of the components its criterion names.
// =============================================================================

/**
 * Criterion 1 — a transformer that adds a new "reading_time" content field. Pure, like the
 * real transformers; module-scoped; touches no core code. The field is added to the EXISTING
 * CanonicalPost.meta map (a first-class extension point already on the canonical model), so
 * the real PostAdapter carries it into content.posts.meta_jsonb with no adapter or core edit.
 */
final class ContentFieldExtendingTransformer
{
    public function transform(PostSourceModel $source): CanonicalPost
    {
        // The new content field. A fixed value keeps the assertion deterministic — the point
        // is that a new field flows through the module to the projection, not the formula.
        $meta                 = $source->meta;
        $meta['reading_time'] = '7';

        return new CanonicalPost(
            postId:      $source->postId,
            title:       $source->title,
            content:     $source->content,
            excerpt:     $source->excerpt,
            slug:        $source->slug,
            status:      $source->status,
            author:      $source->author,
            publishedAt: $source->publishedAt,
            modifiedAt:  $source->modifiedAt,
            categoryIds: $source->categoryIds,
            meta:        $meta,
        );
    }
}

/**
 * Criterion 2 — an adapter that writes a NEW real projection column (word_count) derived from
 * the UNMODIFIED CanonicalPost. Module-scoped; the real transformer + canonical model are not
 * touched. Deliberately minimal (single-statement upsert) — the seam under test is "a new
 * column can be added and populated by an adapter", not DECISION 3 atomicity (proven elsewhere).
 */
final class ProjectionColumnExtendingAdapter
{
    public function __construct(
        private readonly PostgresDatabaseConnection $db,
    ) {}

    public function persist(CanonicalPost $model, EventInterface $event): void
    {
        $wordCount = count(array_filter(preg_split('/\s+/', trim($model->content)) ?: []));
        $now       = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s+00');

        $this->db->execute(
            'INSERT INTO content.posts_ext (id, source_post_id, slug, title, word_count, checksum, synced_at)
             VALUES ($1::uuid, $2, $3, $4, $5, $6, $7::timestamptz)
             ON CONFLICT (source_post_id) DO UPDATE SET
                slug = EXCLUDED.slug, title = EXCLUDED.title,
                word_count = EXCLUDED.word_count, checksum = EXCLUDED.checksum, synced_at = EXCLUDED.synced_at',
            [
                $this->uuidv7(),
                $model->postId,
                $model->slug,
                $model->title,
                $wordCount,
                $model->getChecksum(),
                $now,
            ],
        );
    }

    private function uuidv7(): string
    {
        $ms      = (int) (microtime(true) * 1000);
        $bytes   = random_bytes(10);
        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));
        $hex     = $tsHex . $b67hex . $b89hex . $tailHex;
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}

/**
 * Criterion 3 — a NEW Query Provider for a brand-new "authors" resource. Implements the
 * EXISTING core QueryProviderInterface; reads a live content.authors projection. Existing
 * Query Providers are untouched.
 */
final class AuthorQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly PostgresDatabaseConnection $db,
    ) {}

    public function list(FilterSet $filters): CursorPage
    {
        $limit = min($filters->limit ?? 20, 100);
        $rows  = $this->db->query(
            'SELECT id, slug, name, post_count FROM content.authors
             WHERE deleted_at IS NULL
             ORDER BY name ASC, id DESC
             LIMIT $1',
            [$limit],
        );
        return new CursorPage($rows, null);
    }

    public function findBySlug(string $slug): ?array
    {
        $rows = $this->db->query(
            'SELECT id, slug, name, post_count FROM content.authors
             WHERE slug = $1 AND deleted_at IS NULL
             LIMIT 1',
            [$slug],
        );
        return $rows[0] ?? null;
    }
}

/**
 * Criterion 3 — a NEW Resource serializing content.authors rows to the /authors contract.
 * Implements the EXISTING core ResourceInterface; existing Resources are untouched.
 */
final class AuthorResource implements ResourceInterface
{
    public function toArray(array $row): array
    {
        return [
            'slug'       => $row['slug'],
            'name'       => $row['name'],
            'post_count' => (int) $row['post_count'],
        ];
    }

    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map($this->toArray(...), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }
}
