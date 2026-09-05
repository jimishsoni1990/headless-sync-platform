<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

/**
 * Shared schema support for integration tests that exercise the content query providers.
 *
 * Since P1B-S2 the post/page listings LEFT JOIN content.media to resolve the featured image, so
 * any test that calls a query provider needs that table to exist and needs featured_media_id on
 * the entity projections — even when the test itself cares about neither.
 *
 * The DDL is applied from the REAL migration files rather than copied, which is the whole point:
 * several integration tests hand-copy content DDL, and every schema change then has to be
 * duplicated into each of them by hand. That is the same drift that let migration 0011 ship
 * unwired and unnoticed (FLAG-ONBS2-1). This helper is additive — it never drops or redefines a
 * table a test already created — so it can be dropped into an existing setUp() without
 * disturbing whatever schema that test builds for itself.
 */
final class ContentSchema
{
    private const MIGRATIONS_DIR = __DIR__ . '/../../modules/Content/Migrations/';

    /**
     * Ensure content.media exists and both entity projections carry featured_media_id.
     *
     * Safe to call repeatedly and in any order relative to a test's own createSchema(): every
     * statement involved is IF NOT EXISTS / ADD COLUMN IF NOT EXISTS.
     *
     * @param \PgSql\Connection|resource $conn
     */
    public static function ensureFeaturedMediaSupport(mixed $conn): void
    {
        pg_query($conn, 'CREATE SCHEMA IF NOT EXISTS content');

        self::apply($conn, '0006_create_content_media.sql');

        // 0007 alters BOTH content.posts and content.pages. A test may have created only one of
        // them, so stand the other up as a minimal table first — ADD COLUMN needs a table to
        // attach to, and a bare id column is enough for a projection this test never reads.
        pg_query($conn, 'CREATE TABLE IF NOT EXISTS content.posts (id UUID PRIMARY KEY)');
        pg_query($conn, 'CREATE TABLE IF NOT EXISTS content.pages (id UUID PRIMARY KEY)');

        self::apply($conn, '0007_add_featured_media_to_content_entities.sql');
    }

    /**
     * @param \PgSql\Connection|resource $conn
     */
    private static function apply(mixed $conn, string $file): void
    {
        $sql = file_get_contents(self::MIGRATIONS_DIR . $file);

        if ($sql === false) {
            throw new \RuntimeException("Cannot read migration {$file} for integration schema setup.");
        }

        if (pg_query($conn, $sql) === false) {
            throw new \RuntimeException("Migration {$file} failed in test setup: " . pg_last_error($conn));
        }
    }
}
