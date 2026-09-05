<?php

declare(strict_types=1);

namespace HSP\Database\Core\Mysql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateHspAggregateCountersMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0002_create_hsp_aggregate_counters';
    }

    public function getSchemaContext(): string
    {
        return 'core/mysql';
    }

    /**
     * Carries the per-aggregate monotonic counter (DECISION 2 v1.1); capture fails without it.
     * Reported for the same reason as the outbox table — the PostgreSQL ledger outlives a
     * WordPress-database restore, so presence must be checked, not assumed.
     */
    public function isSatisfied(): bool
    {
        return $this->connection->query("SHOW TABLES LIKE '{prefix}hsp_aggregate_counters'") !== [];
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0002_create_hsp_aggregate_counters.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
