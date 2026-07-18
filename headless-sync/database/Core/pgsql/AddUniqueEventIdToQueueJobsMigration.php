<?php

declare(strict_types=1);

namespace HSP\Database\Core\Pgsql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

/**
 * Adds the UNIQUE(event_id) constraint on system.queue_jobs (DECISION L v1.12 — dispatcher dedup).
 *
 * Wraps the EXISTING frozen SQL file `0011_add_unique_event_id_to_queue_jobs.sql` in a migration
 * class so the engine actually applies it. `DatabaseQueueProvider::enqueueIdempotent()` uses
 * `ON CONFLICT(event_id) DO NOTHING` as the idempotent dispatch gate, which REQUIRES this UNIQUE
 * constraint; without it the first dispatch fails ("no unique or exclusion constraint matching the
 * ON CONFLICT specification").
 *
 * The SQL file shipped in P1A-S6d but was never given a migration class or wired into
 * `MigrationServiceProvider::migrations.core`, so the migration engine never applied it — integration
 * tests seed schema directly, which masked the gap. It surfaced in the ONB-S2 zero-config
 * fresh-install E2E, the first path that drives the real engine end-to-end and then runs a cycle.
 * This class closes the gap; the frozen SQL is unchanged (additive DDL, forward-only).
 */
final class AddUniqueEventIdToQueueJobsMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0011_add_unique_event_id_to_queue_jobs';
    }

    public function getSchemaContext(): string
    {
        return 'core/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0011_add_unique_event_id_to_queue_jobs.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
