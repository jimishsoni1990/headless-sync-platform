<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox\Connection;

use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * MySQL-scoped connection contract for the outbox capture path.
 *
 * Covers the MySQL side of the relay only: BEGIN / SKIP LOCKED SELECT /
 * mark-relayed UPDATE / COMMIT / ROLLBACK on wp_hsp_outbox.
 *
 * Does NOT extend or reference DatabaseConnectionInterface —
 * DatabaseConnectionInterface is PostgreSQL-only (DECISION E v1.6).
 *
 * Authority: DECISION E v1.6 — outbox split; MySQL capture path.
 */
interface MysqlOutboxConnectionInterface
{
    /**
     * Execute a DML statement (INSERT/UPDATE/DELETE). Returns affected row count.
     *
     * @param list<mixed> $params
     * @throws OutboxWriteException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     * @throws OutboxWriteException
     */
    public function query(string $sql, array $params = []): array;

    /** @throws OutboxWriteException */
    public function beginTransaction(): void;

    /** @throws OutboxWriteException */
    public function commit(): void;

    public function rollback(): void;
}
