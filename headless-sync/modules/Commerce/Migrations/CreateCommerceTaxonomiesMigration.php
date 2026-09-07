<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Migrations;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateCommerceTaxonomiesMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function getName(): string
    {
        return '0003_create_commerce_taxonomies';
    }

    public function getSchemaContext(): string
    {
        return 'commerce/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0003_create_commerce_taxonomies.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
