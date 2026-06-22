<?php

declare(strict_types=1);

namespace HSP\Core\Queue\Providers\Database;

use HSP\Core\Queue\Exception\QueueException;

/**
 * Database connection contract for the queue provider.
 *
 * Distinct from OutboxConnectionInterface (which is scoped to the outbox relay path).
 * Implementing this interface allows tests to inject a fake connection without
 * subclassing the concrete DatabaseQueueConnection.
 */
interface QueueConnectionInterface
{
    /**
     * Execute a DML statement and return affected row count.
     *
     * @param list<mixed> $params
     * @throws QueueException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws QueueException
     */
    public function query(string $sql, array $params = []): array;

    /** Begin a database transaction. */
    public function beginTransaction(): void;

    /** Commit the current transaction. */
    public function commit(): void;

    /** Roll back the current transaction. */
    public function rollback(): void;
}
