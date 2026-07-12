<?php

declare(strict_types=1);

namespace HSP\Database\Core\Pgsql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

/**
 * Adds system.dead_letter_jobs.replayed_at (DECISION S clause (e), v1.16).
 *
 * `replayed_at TIMESTAMPTZ NULL` is absent from the frozen OPEN-3 v1.1 schema
 * (migration 0004). This forward migration adds it; 0004 is not edited.
 * Stamped in the single-transaction DLQ replay (DECISION S); NULL = not yet replayed.
 */
final class AddReplayedAtToDeadLetterJobsMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0013_add_replayed_at_to_dead_letter_jobs';
    }

    public function getSchemaContext(): string
    {
        return 'core/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0013_add_replayed_at_to_dead_letter_jobs.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
