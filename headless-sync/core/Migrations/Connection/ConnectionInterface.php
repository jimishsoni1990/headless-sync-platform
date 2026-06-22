<?php

declare(strict_types=1);

namespace HSP\Core\Migrations\Connection;

/**
 * Minimal database connection contract used exclusively by the migration engine.
 *
 * Not a general-purpose DB abstraction — scope is limited to:
 *   - executing DDL statements
 *   - reading/writing system.schema_versions
 */
interface ConnectionInterface
{
    /**
     * Execute a SQL statement. Throws on error.
     *
     * @throws \HSP\Core\Migrations\Exception\MigrationException
     */
    public function execute(string $sql): void;

    /**
     * Execute a query and return all rows as associative arrays.
     *
     * @return array<int, array<string, mixed>>
     * @throws \HSP\Core\Migrations\Exception\MigrationException
     */
    public function query(string $sql, array $params = []): array;

    /**
     * Execute an INSERT/UPSERT and return affected row count.
     *
     * @throws \HSP\Core\Migrations\Exception\MigrationException
     */
    public function insert(string $sql, array $params = []): int;
}
