<?php

declare(strict_types=1);

namespace HSP\Database\Core\Mysql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class CreateHspOutboxMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0001_create_hsp_outbox';
    }

    public function getSchemaContext(): string
    {
        return 'core/mysql';
    }

    /**
     * The outbox table is the capture point (OPEN-6): without it every WordPress write is lost.
     * The schema_versions ledger lives in PostgreSQL, so it survives a WordPress-database restore
     * that drops this table — report actual presence so MigrationRunner re-applies instead of
     * trusting a stale ledger row. `{prefix}` is substituted by the connection.
     */
    public function isSatisfied(): bool
    {
        return $this->connection->query("SHOW TABLES LIKE '{prefix}hsp_outbox'") !== [];
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0001_create_hsp_outbox.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
