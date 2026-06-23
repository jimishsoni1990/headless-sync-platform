<?php

declare(strict_types=1);

namespace HSP\Core\Database;

use HSP\Core\Database\Exception\DatabaseException;

/**
 * Shared PostgreSQL connection implementation for runtime DML subsystems.
 *
 * Wraps a pg_connect() handle and implements DatabaseConnectionInterface.
 * Replaces the duplicated PgsqlOutboxConnection and DatabaseQueueConnection
 * runtime wrappers per DECISION E (v1.5).
 *
 * The migration engine's PgsqlConnection (core/Migrations/Connection/) is a
 * separate, DDL-only abstraction and is NOT replaced by this class.
 */
class PostgresDatabaseConnection implements DatabaseConnectionInterface
{
    /** @var \PgSql\Connection|resource */
    private mixed $conn;

    public function __construct(mixed $conn)
    {
        if (! is_resource($conn) && ! ($conn instanceof \PgSql\Connection)) {
            throw new DatabaseException(
                'PostgresDatabaseConnection requires a valid pg_connect() handle.'
            );
        }
        $this->conn = $conn;
    }

    public function execute(string $sql, array $params = []): int
    {
        $result = empty($params)
            ? pg_query($this->conn, $sql)
            : pg_query_params($this->conn, $sql, $params);

        if ($result === false) {
            throw new DatabaseException(
                'PostgreSQL execute failed: ' . pg_last_error($this->conn)
            );
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);

        return $affected;
    }

    public function query(string $sql, array $params = []): array
    {
        $result = empty($params)
            ? pg_query($this->conn, $sql)
            : pg_query_params($this->conn, $sql, $params);

        if ($result === false) {
            throw new DatabaseException(
                'PostgreSQL query failed: ' . pg_last_error($this->conn)
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
            throw new DatabaseException(
                'PostgreSQL BEGIN failed: ' . pg_last_error($this->conn)
            );
        }
        pg_free_result($result);
    }

    public function commit(): void
    {
        $result = pg_query($this->conn, 'COMMIT');
        if ($result === false) {
            throw new DatabaseException(
                'PostgreSQL COMMIT failed: ' . pg_last_error($this->conn)
            );
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
