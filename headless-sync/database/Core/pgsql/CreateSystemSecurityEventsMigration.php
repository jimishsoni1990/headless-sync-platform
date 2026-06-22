<?php

declare(strict_types=1);

namespace HSP\Database\Core\Pgsql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateSystemSecurityEventsMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0010_create_system_security_events';
    }

    public function getSchemaContext(): string
    {
        return 'core/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0010_create_system_security_events.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
