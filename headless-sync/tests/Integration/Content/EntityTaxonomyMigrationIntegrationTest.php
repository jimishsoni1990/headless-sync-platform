<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;
use PHPUnit\Framework\TestCase;

/**
 * Migration 0009 — content.entity_taxonomies re-keyed on the source term identity.
 *
 * DECISION AJ (v1.42) amends a FROZEN shape, so this migration runs against installations that
 * already hold relationship rows in the old `(entity_id, taxonomy_id UUID)` form. Getting the
 * translation wrong would quietly delete working category membership on every site that was not
 * affected by Finding 004 — so the translation, the dangling-row rule and the final constraint set
 * are each asserted rather than assumed.
 *
 * The migration is applied FROM THE REAL FILE, against the real 0003-0008 schema, so this cannot
 * drift from what production runs.
 *
 * Environment variables (test self-skips if DB absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class EntityTaxonomyMigrationIntegrationTest extends TestCase
{
    private const MIGRATIONS_DIR = __DIR__ . '/../../../modules/Content/Migrations/';

    /** Everything up to, but NOT including, the migration under test. */
    private const PRIOR_MIGRATIONS = [
        '0003_create_content_posts.sql',
        '0004_create_content_taxonomies.sql',
        '0005_create_content_entity_taxonomies.sql',
        '0008_align_content_taxonomy_indexes.sql',
    ];

    private const MIGRATION_UNDER_TEST = '0009_align_content_entity_taxonomies_to_source_term_id.sql';

    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);

        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS content');
        foreach (self::PRIOR_MIGRATIONS as $file) {
            $this->apply($file);
        }
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Translation
    // =========================================================================

    public function test_a_resolvable_relationship_survives_as_its_source_term_id(): void
    {
        // The case that matters most: an installation whose relationships were NEVER broken. Its
        // working category membership must come through the amendment intact.
        $post = $this->seedPost(39, 'linked');
        $this->seedTerm(10, 'etf', 'category');
        $this->seedTerm(11, 'sip', 'category');
        $this->linkByUuid($post, 10);
        $this->linkByUuid($post, 11);

        $this->apply(self::MIGRATION_UNDER_TEST);

        self::assertSame([10, 11], $this->linkedTermIds($post), 'both links translated in place');
    }

    public function test_every_valid_relationship_in_a_populated_table_survives(): void
    {
        $posts = [];
        foreach ([39, 40, 41] as $i => $sourceId) {
            $posts[$sourceId] = $this->seedPost($sourceId, 'post-' . $sourceId);
        }
        foreach ([10, 11, 12] as $termId) {
            $this->seedTerm($termId, 'term-' . $termId, 'category');
        }
        $this->seedTerm(20, 'php', 'post_tag');

        $this->linkByUuid($posts[39], 10);
        $this->linkByUuid($posts[39], 11);
        $this->linkByUuid($posts[39], 20);   // a TAG link — the shared table carries both
        $this->linkByUuid($posts[40], 12);
        // post 41 deliberately carries none.

        self::assertSame(4, $this->rowCount());

        $this->apply(self::MIGRATION_UNDER_TEST);

        self::assertSame(4, $this->rowCount(), 'no row lost');
        self::assertSame([10, 11, 20], $this->linkedTermIds($posts[39]), 'categories AND tags translated');
        self::assertSame([12], $this->linkedTermIds($posts[40]));
        self::assertSame([], $this->linkedTermIds($posts[41]));
    }

    public function test_a_dangling_relationship_is_removed_not_given_an_invented_identity(): void
    {
        // A link pointing at a taxonomy row that no longer exists has no source identity to
        // recover — inventing one (0, or a synthetic id) would fabricate membership. It is dropped,
        // and the removal is confined to the dangling row.
        $post = $this->seedPost(39, 'partly-dangling');
        $this->seedTerm(10, 'etf', 'category');
        $this->linkByUuid($post, 10);

        $this->db->execute(
            'INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id)
             VALUES ($1::uuid, gen_random_uuid())',
            [$post]
        );
        self::assertSame(2, $this->rowCount());

        $this->apply(self::MIGRATION_UNDER_TEST);

        self::assertSame([10], $this->linkedTermIds($post), 'the resolvable link survives alone');
        self::assertSame(1, $this->rowCount(), 'the dangling row is gone, not translated to a fake id');
    }

    public function test_an_empty_relationship_table_migrates_cleanly(): void
    {
        // The Finding 004 installation's actual state: zero rows. The migration must still reshape
        // the table rather than erroring on an empty translation.
        self::assertSame(0, $this->rowCount());

        $this->apply(self::MIGRATION_UNDER_TEST);

        self::assertSame(0, $this->rowCount());
        self::assertSame(['entity_id', 'source_term_id'], $this->columns());
    }

    // =========================================================================
    // Final shape
    // =========================================================================

    public function test_the_migrated_table_has_exactly_the_ruled_columns_keys_and_indexes(): void
    {
        $this->apply(self::MIGRATION_UNDER_TEST);

        // Columns: the pure relationship table of FLAG-P1AS4-1, with DECISION AJ's identity.
        // No timestamps, no checksum, no metadata, no surrogate id.
        self::assertSame(['entity_id', 'source_term_id'], $this->columns());
        self::assertSame('uuid',   $this->columnType('entity_id'));
        self::assertSame('bigint', $this->columnType('source_term_id'));
        self::assertSame(['entity_id', 'source_term_id'], $this->notNullColumns(), 'both columns NOT NULL');

        // Primary key, in the ruled column order — entity_id leads, which is what the adapter's
        // relationship-set read rides.
        self::assertSame(
            ['entity_id', 'source_term_id'],
            $this->primaryKeyColumns(),
            'PRIMARY KEY (entity_id, source_term_id)'
        );

        // Exactly two access paths: the PK and the reverse lookup. The obsolete UUID index is gone.
        $indexes = $this->indexNames();
        self::assertContains('pk_content_entity_taxonomies', $indexes);
        self::assertContains('idx_content_entity_taxonomies_term_entity', $indexes);
        self::assertNotContains('idx_content_entity_taxonomies_taxonomy_entity', $indexes, 'obsolete index dropped');
        self::assertNotContains('idx_content_entity_taxonomies_taxonomy_id', $indexes);
        self::assertCount(2, $indexes, 'no index left behind and none added');

        // No foreign keys — either side may legitimately arrive later (ADR-013).
        self::assertSame([], $this->foreignKeyNames());
    }

    public function test_the_reverse_index_covers_the_term_to_entities_direction(): void
    {
        $this->apply(self::MIGRATION_UNDER_TEST);

        $def = (string) $this->db->query(
            "SELECT indexdef FROM pg_indexes
             WHERE schemaname = 'content' AND indexname = \$1",
            ['idx_content_entity_taxonomies_term_entity']
        )[0]['indexdef'];

        // Both columns, source_term_id leading — an index on source_term_id alone would force a
        // heap lookup per candidate row on every filtered listing.
        self::assertStringContainsString('(source_term_id, entity_id)', $def);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function apply(string $file): void
    {
        $sql = file_get_contents(self::MIGRATIONS_DIR . $file);
        self::assertIsString($sql, "migration {$file} must be readable");
        self::assertNotFalse(
            pg_query($this->pgConn, $sql),
            "migration {$file} must apply: " . pg_last_error($this->pgConn)
        );
    }

    private function seedPost(int $sourceId, string $slug): string
    {
        return (string) $this->db->query(
            "INSERT INTO content.posts
                (id, source_post_id, source_entity_type, slug, title, content, excerpt, status,
                 author, published_at, updated_at, checksum, meta_jsonb, created_at, synced_at)
             VALUES (gen_random_uuid(), \$1, 'post', \$2, 'Post', '', '', 'publish', 'editor',
                     now(), now(), repeat('a', 64), '{}'::jsonb, now(), now())
             RETURNING id",
            [$sourceId, $slug]
        )[0]['id'];
    }

    private function seedTerm(int $sourceTermId, string $slug, string $type): string
    {
        return (string) $this->db->query(
            "INSERT INTO content.taxonomies
                (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                 checksum, created_at, updated_at, synced_at)
             VALUES (gen_random_uuid(), \$1, \$2, \$3, 'Term', '', 0, 0,
                     repeat('c', 64), now(), now(), now())
             RETURNING id",
            [$sourceTermId, $type, $slug]
        )[0]['id'];
    }

    /** Link in the PRE-migration shape, by the term's projection UUID. */
    private function linkByUuid(string $entityId, int $sourceTermId): void
    {
        $this->db->execute(
            'INSERT INTO content.entity_taxonomies (entity_id, taxonomy_id)
             SELECT $1::uuid, t.id FROM content.taxonomies t WHERE t.source_term_id = $2',
            [$entityId, $sourceTermId]
        );
    }

    /** @return list<int> */
    private function linkedTermIds(string $entityId): array
    {
        $rows = $this->db->query(
            'SELECT source_term_id FROM content.entity_taxonomies
             WHERE entity_id = $1::uuid ORDER BY source_term_id',
            [$entityId]
        );

        return array_map(static fn (array $r): int => (int) $r['source_term_id'], $rows);
    }

    private function rowCount(): int
    {
        return (int) $this->db->query('SELECT count(*) AS c FROM content.entity_taxonomies')[0]['c'];
    }

    /** @return list<string> */
    private function columns(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['column_name'],
            $this->db->query(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'content' AND table_name = 'entity_taxonomies'
                 ORDER BY column_name"
            )
        );
    }

    private function columnType(string $column): string
    {
        return (string) $this->db->query(
            "SELECT data_type FROM information_schema.columns
             WHERE table_schema = 'content' AND table_name = 'entity_taxonomies'
               AND column_name = \$1",
            [$column]
        )[0]['data_type'];
    }

    /** @return list<string> */
    private function notNullColumns(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['column_name'],
            $this->db->query(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = 'content' AND table_name = 'entity_taxonomies'
                   AND is_nullable = 'NO'
                 ORDER BY column_name"
            )
        );
    }

    /** @return list<string> in key order */
    private function primaryKeyColumns(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['attname'],
            $this->db->query(
                "SELECT a.attname
                 FROM pg_index i
                 JOIN pg_class c   ON c.oid = i.indrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 JOIN unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord) ON TRUE
                 JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = k.attnum
                 WHERE n.nspname = 'content' AND c.relname = 'entity_taxonomies' AND i.indisprimary
                 ORDER BY k.ord"
            )
        );
    }

    /** @return list<string> */
    private function indexNames(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['indexname'],
            $this->db->query(
                "SELECT indexname FROM pg_indexes
                 WHERE schemaname = 'content' AND tablename = 'entity_taxonomies'
                 ORDER BY indexname"
            )
        );
    }

    /** @return list<string> */
    private function foreignKeyNames(): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['conname'],
            $this->db->query(
                "SELECT con.conname
                 FROM pg_constraint con
                 JOIN pg_class c     ON c.oid = con.conrelid
                 JOIN pg_namespace n ON n.oid = c.relnamespace
                 WHERE n.nspname = 'content' AND c.relname = 'entity_taxonomies'
                   AND con.contype = 'f'
                 ORDER BY con.conname"
            )
        );
    }

    private function connectPgsql(): mixed
    {
        if (! extension_loaded('pgsql')) {
            self::markTestSkipped('pgsql extension not loaded');
        }

        $host = getenv('HSP_TEST_PGSQL_HOST');
        $db   = getenv('HSP_TEST_PGSQL_DATABASE');

        if ($host === false || $db === false) {
            self::markTestSkipped('HSP_TEST_PGSQL_* not configured');
        }

        $conn = @pg_connect(sprintf(
            'host=%s port=%s dbname=%s user=%s password=%s',
            $host,
            getenv('HSP_TEST_PGSQL_PORT') ?: '5432',
            $db,
            getenv('HSP_TEST_PGSQL_USER') ?: 'postgres',
            getenv('HSP_TEST_PGSQL_PASSWORD') ?: ''
        ));

        if ($conn === false) {
            self::markTestSkipped('PostgreSQL not reachable');
        }

        return $conn;
    }
}
