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
 *
 * Lazy connection: the constructor accepts EITHER an already-open pg_connect()
 * handle (test injection, and any caller that already holds one) OR a
 * `\Closure(): handle` CONNECTOR. With a connector, no socket is opened at
 * construction or container-resolution time — it opens on first real use
 * (execute / query / beginTransaction / commit), then is memoized. Any connect
 * failure is translated to DatabaseException at THIS boundary, so subsystems
 * keep translating a single exception type (DECISION E v1.6 error semantics)
 * instead of a raw \RuntimeException escaping from a container factory.
 * This mirrors the MysqliOutboxConnection connector hotfix on the capture path.
 */
class PostgresDatabaseConnection implements DatabaseConnectionInterface
{
    /** @var \PgSql\Connection|resource|null Memoized handle; null until a connector opens it. */
    private mixed $conn = null;

    /** @var (\Closure(): mixed)|null Opens and returns a ready handle; invoked at most once. */
    private ?\Closure $connector = null;

    /**
     * @param \PgSql\Connection|resource|\Closure(): mixed $connOrConnector
     *        An open pg_connect() handle, or a connector closure returning one.
     */
    public function __construct(mixed $connOrConnector)
    {
        if ($connOrConnector instanceof \Closure) {
            $this->connector = $connOrConnector;

            return;
        }

        if (! is_resource($connOrConnector) && ! ($connOrConnector instanceof \PgSql\Connection)) {
            throw new DatabaseException(
                'PostgresDatabaseConnection requires a valid pg_connect() handle.'
            );
        }

        $this->conn = $connOrConnector;
    }

    /**
     * Return the memoized handle, invoking the connector on first call.
     *
     * @return \PgSql\Connection|resource
     * @throws DatabaseException on any connect failure (translated at this boundary).
     */
    private function connection(): mixed
    {
        if ($this->conn !== null) {
            return $this->conn;
        }

        if ($this->connector === null) {
            throw new DatabaseException('PostgresDatabaseConnection has no handle and no connector.');
        }

        try {
            $conn = ($this->connector)();
        } catch (\Throwable $e) {
            throw new DatabaseException(
                'PostgreSQL connect failed: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if (! is_resource($conn) && ! ($conn instanceof \PgSql\Connection)) {
            throw new DatabaseException(
                'PostgreSQL connect failed: connector did not return a valid pg_connect() handle.'
            );
        }

        return $this->conn = $conn;
    }

    public function execute(string $sql, array $params = []): int
    {
        $conn = $this->connection();

        $result = empty($params)
            ? pg_query($conn, $sql)
            : pg_query_params($conn, $sql, $params);

        if ($result === false) {
            throw new DatabaseException(
                'PostgreSQL execute failed: ' . pg_last_error($conn)
            );
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);

        return $affected;
    }

    public function query(string $sql, array $params = []): array
    {
        $conn = $this->connection();

        $result = empty($params)
            ? pg_query($conn, $sql)
            : pg_query_params($conn, $sql, $params);

        if ($result === false) {
            throw new DatabaseException(
                'PostgreSQL query failed: ' . pg_last_error($conn)
            );
        }

        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);

        return $rows;
    }

    public function beginTransaction(): void
    {
        $conn   = $this->connection();
        $result = pg_query($conn, 'BEGIN');

        if ($result === false) {
            throw new DatabaseException(
                'PostgreSQL BEGIN failed: ' . pg_last_error($conn)
            );
        }
        pg_free_result($result);
    }

    public function commit(): void
    {
        $conn   = $this->connection();
        $result = pg_query($conn, 'COMMIT');

        if ($result === false) {
            throw new DatabaseException(
                'PostgreSQL COMMIT failed: ' . pg_last_error($conn)
            );
        }
        pg_free_result($result);
    }

    public function rollback(): void
    {
        // Best-effort, never throws (DECISION E v1.6). A connection that was never
        // opened has no transaction to roll back — do NOT dial the socket here.
        if ($this->conn === null) {
            return;
        }

        $result = pg_query($this->conn, 'ROLLBACK');
        if ($result !== false) {
            pg_free_result($result);
        }
    }
}
