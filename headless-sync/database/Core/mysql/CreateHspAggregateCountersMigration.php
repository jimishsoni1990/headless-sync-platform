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

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0002_create_hsp_aggregate_counters.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
