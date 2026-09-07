<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Migrations;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateCommerceProductVariationsMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    public function getName(): string
    {
        return '0006_create_commerce_product_variations';
    }

    public function getSchemaContext(): string
    {
        return 'commerce/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0006_create_commerce_product_variations.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
