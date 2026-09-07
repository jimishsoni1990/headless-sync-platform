<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Migrations;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateCommerceSchemaMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function getName(): string
    {
        return '0001_create_commerce_schema';
    }

    /**
     * A schema context per owning domain — `commerce/pgsql` cannot collide with
     * `content/pgsql` in system.schema_versions' UNIQUE(migration_name, schema_context),
     * which is what lets both modules number their migrations from 0001.
     */
    public function getSchemaContext(): string
    {
        return 'commerce/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0001_create_commerce_schema.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
