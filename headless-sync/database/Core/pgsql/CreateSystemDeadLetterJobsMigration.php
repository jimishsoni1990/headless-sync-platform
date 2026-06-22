<?php

declare(strict_types=1);

namespace HSP\Database\Core\Pgsql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateSystemDeadLetterJobsMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0004_create_system_dead_letter_jobs';
    }

    public function getSchemaContext(): string
    {
        return 'core/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0004_create_system_dead_letter_jobs.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
