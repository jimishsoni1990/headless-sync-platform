<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox\Connection;

/**
 * Database connection contract for the outbox relay path.
 *
 * Distinct from core/Migrations/Connection/ConnectionInterface, which is scoped
 * to DDL-only (migration engine). This interface adds transactional DML methods
 * needed for SELECT … FOR UPDATE SKIP LOCKED claim semantics (OPEN-4/OPEN-6).
 */
interface OutboxConnectionInterface
{
    /**
     * Execute a DML statement (INSERT/UPDATE/DELETE). Returns affected row count.
     *
     * @param list<mixed> $params
     * @throws \HSP\Core\Events\Outbox\Exception\OutboxWriteException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws \HSP\Core\Events\Outbox\Exception\OutboxWriteException
     */
    public function query(string $sql, array $params = []): array;

    /** Begin a database transaction. */
    public function beginTransaction(): void;

    /** Commit the current transaction. */
    public function commit(): void;

    /** Roll back the current transaction. */
    public function rollback(): void;
}
