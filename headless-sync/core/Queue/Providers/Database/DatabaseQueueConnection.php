<?php

declare(strict_types=1);

namespace HSP\Core\Queue\Providers\Database;

use HSP\Core\Queue\Exception\QueueException;

/**
 * PostgreSQL connection wrapper for the database queue provider.
 *
 * Wraps a pg_connect() handle and exposes execute/query/transaction semantics
 * matching the queue provider's needs.  Uses $N positional parameters
 * (native libpq protocol) — same convention as PgsqlOutboxConnection.
 */
final class DatabaseQueueConnection implements QueueConnectionInterface
{
    /** @var \PgSql\Connection|resource */
    private mixed $conn;

    public function __construct(mixed $conn)
    {
        if (! is_resource($conn) && ! ($conn instanceof \PgSql\Connection)) {
            throw new QueueException(
                'DatabaseQueueConnection requires a valid pg_connect() handle.'
            );
        }
        $this->conn = $conn;
    }

    /**
     * Execute a DML statement and return affected row count.
     *
     * @param list<mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $result = empty($params)
            ? pg_query($this->conn, $sql)
            : pg_query_params($this->conn, $sql, $params);

        if ($result === false) {
            throw new QueueException(
                'Queue DB execute failed: ' . pg_last_error($this->conn)
            );
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);

        return $affected;
    }

    /**
     * Execute a SELECT and return all rows as associative arrays.
     *
     * @param list<mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql, array $params = []): array
    {
        $result = empty($params)
            ? pg_query($this->conn, $sql)
            : pg_query_params($this->conn, $sql, $params);

        if ($result === false) {
            throw new QueueException(
                'Queue DB query failed: ' . pg_last_error($this->conn)
            );
        }

        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);

        return $rows;
    }

    public function beginTransaction(): void
    {
        $result = pg_query($this->conn, 'BEGIN');
        if ($result === false) {
            throw new QueueException('Queue DB BEGIN failed: ' . pg_last_error($this->conn));
        }
        pg_free_result($result);
    }

    public function commit(): void
    {
        $result = pg_query($this->conn, 'COMMIT');
        if ($result === false) {
            throw new QueueException('Queue DB COMMIT failed: ' . pg_last_error($this->conn));
        }
        pg_free_result($result);
    }

    public function rollback(): void
    {
        $result = pg_query($this->conn, 'ROLLBACK');
        if ($result !== false) {
            pg_free_result($result);
        }
    }
}
