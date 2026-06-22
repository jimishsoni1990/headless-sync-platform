<?php

declare(strict_types=1);

namespace HSP\Core\Migrations\Connection;

use HSP\Core\Migrations\Exception\MigrationException;

/**
 * PostgreSQL connection adapter for the migration engine.
 *
 * Uses PHP's native pg_* functions — no composer library required.
 * Connection lifecycle (connect/close) is owned by the caller.
 */
final class PgsqlConnection implements ConnectionInterface
{
    /** @var \PgSql\Connection */
    private mixed $conn;

    public function __construct(mixed $conn)
    {
        if (! is_resource($conn) && ! ($conn instanceof \PgSql\Connection)) {
            throw new MigrationException('PgsqlConnection requires a valid pg_connect() handle.');
        }
        $this->conn = $conn;
    }

    public function execute(string $sql): void
    {
        $result = pg_query($this->conn, $sql);

        if ($result === false) {
            throw new MigrationException(
                'PostgreSQL migration DDL failed: ' . pg_last_error($this->conn) . "\nSQL: {$sql}"
            );
        }

        pg_free_result($result);
    }

    public function query(string $sql, array $params = []): array
    {
        if (empty($params)) {
            $result = pg_query($this->conn, $sql);
        } else {
            $result = pg_query_params($this->conn, $sql, $params);
        }

        if ($result === false) {
            throw new MigrationException('PostgreSQL query failed: ' . pg_last_error($this->conn));
        }

        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);

        return $rows;
    }

    public function insert(string $sql, array $params = []): int
    {
        if (empty($params)) {
            $result = pg_query($this->conn, $sql);
        } else {
            $result = pg_query_params($this->conn, $sql, $params);
        }

        if ($result === false) {
            throw new MigrationException('PostgreSQL insert failed: ' . pg_last_error($this->conn));
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);

        return $affected;
    }
}
