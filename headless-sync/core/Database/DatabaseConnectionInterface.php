<?php

declare(strict_types=1);

namespace HSP\Core\Database;

use HSP\Core\Database\Exception\DatabaseException;

/**
 * Shared runtime PostgreSQL connection abstraction for DML subsystems.
 *
 * Used by: outbox relay, queue provider, worker infrastructure, and any future
 * runtime DML service. The migration engine is explicitly excluded — it retains
 * its own DDL-only abstraction (core/Migrations/Connection/ConnectionInterface).
 *
 * Authority: DECISION E (v1.5) — P0-S7 authorized scope.
 */
interface DatabaseConnectionInterface
{
    /**
     * Execute a DML statement (INSERT/UPDATE/DELETE). Returns affected row count.
     *
     * @param list<mixed> $params
     * @throws DatabaseException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws DatabaseException
     */
    public function query(string $sql, array $params = []): array;

    /** @throws DatabaseException */
    public function beginTransaction(): void;

    /** @throws DatabaseException */
    public function commit(): void;

    public function rollback(): void;
}
