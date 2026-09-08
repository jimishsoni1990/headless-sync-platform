<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

/**
 * Applies the Commerce module's migrations to a live test database, ALL of them, in order.
 *
 * WHY THIS EXISTS: six integration tests each carried their own hand-written list of migration
 * filenames, and every session that added a table had to remember to update whichever of them
 * happened to touch the new relation. P2-S6 is where that stopped scaling — adding the inventory
 * join to the product query broke four unrelated test classes at once, each with a list that was
 * accurate when it was written.
 *
 * Reading the directory means a new migration is applied everywhere the moment it exists, and a
 * test can never pass against a schema the real engine would not produce. That is the same
 * reasoning that made these tests run the real SQL files in the first place: hand-seeded DDL is
 * what let migration 0011 ship unwired while every test was green (FLAG-ONBS2-1).
 */
final class CommerceSchema
{
    private function __construct()
    {
    }

    /**
     * @param resource|\PgSql\Connection $pgConn
     * @return list<string> the migration files applied, in order
     */
    public static function applyAll(mixed $pgConn): array
    {
        $dir   = \dirname(__DIR__, 2) . '/modules/Commerce/Migrations';
        $files = glob($dir . '/*.sql') ?: [];

        // Numeric filename prefixes, so ordering is the migration order rather than whatever the
        // filesystem happens to return.
        sort($files);

        $applied = [];

        foreach ($files as $file) {
            $sql = file_get_contents($file);

            if ($sql === false) {
                throw new \RuntimeException("could not read migration {$file}");
            }

            pg_query($pgConn, $sql);
            $applied[] = basename($file);
        }

        return $applied;
    }

    /**
     * The `system` tables every adapter's DECISION 3 transaction writes.
     *
     * Core-owned rather than Commerce-owned, and created here only because these tests exercise
     * an adapter in isolation rather than through a booted container.
     *
     * @param resource|\PgSql\Connection $pgConn
     */
    public static function applySystemTables(mixed $pgConn): void
    {
        pg_query($pgConn, 'CREATE SCHEMA IF NOT EXISTS system');
        pg_query($pgConn, '
            CREATE TABLE IF NOT EXISTS system.processed_events (
                event_id     UUID        NOT NULL,
                checksum     VARCHAR(64) NOT NULL,
                processed_at TIMESTAMPTZ NOT NULL,
                CONSTRAINT pk_system_processed_events PRIMARY KEY (event_id)
            )
        ');
        pg_query($pgConn, '
            CREATE TABLE IF NOT EXISTS system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_system_aggregate_versions PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ');
    }
}
