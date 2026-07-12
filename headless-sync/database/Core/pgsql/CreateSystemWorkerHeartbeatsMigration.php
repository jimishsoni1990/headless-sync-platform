<?php

declare(strict_types=1);

namespace HSP\Database\Core\Pgsql;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

/**
 * Creates system.worker_heartbeats (DECISION P, ARCHITECTURE_DECISIONS.md v1.16).
 *
 * Single current-state table; one row per worker, upserted per tick. No history.
 * Migration authorized for OPS-S1 by DECISION P.
 */
final class CreateSystemWorkerHeartbeatsMigration extends AbstractSqlMigration
{
    public function __construct(private readonly ConnectionInterface $connection) {}

    public function getName(): string
    {
        return '0012_create_system_worker_heartbeats';
    }

    public function getSchemaContext(): string
    {
        return 'core/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0012_create_system_worker_heartbeats.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
