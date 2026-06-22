<?php

declare(strict_types=1);

namespace HSP\Database\Core\Pgsql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateSystemSchemaMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0001_create_system_schema';
    }

    public function getSchemaContext(): string
    {
        return 'core/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0001_create_system_schema.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
