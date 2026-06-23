<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Migrations;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateContentSchemaMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0001_create_content_schema';
    }

    public function getSchemaContext(): string
    {
        return 'content/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0001_create_content_schema.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
