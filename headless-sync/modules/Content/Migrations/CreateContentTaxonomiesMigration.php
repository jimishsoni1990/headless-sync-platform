<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Migrations;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateContentTaxonomiesMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0004_create_content_taxonomies';
    }

    public function getSchemaContext(): string
    {
        return 'content/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0004_create_content_taxonomies.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
