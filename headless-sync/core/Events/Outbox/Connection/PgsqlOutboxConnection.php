<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox\Connection;

use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * PostgreSQL connection for the outbox relay, backed by a pg_connect() handle.
 *
 * Implements OutboxConnectionInterface so the relay strategy can commit the
 * PostgreSQL transaction before marking the outbox row 'relayed' in MySQL.
 */
final class PgsqlOutboxConnection implements OutboxConnectionInterface
{
    /** @var \PgSql\Connection */
    private mixed $conn;

    public function __construct(mixed $conn)
    {
        if (! is_resource($conn) && ! ($conn instanceof \PgSql\Connection)) {
            throw new OutboxWriteException(
                'PgsqlOutboxConnection requires a valid pg_connect() handle.'
            );
        }
        $this->conn = $conn;
    }

    public function execute(string $sql, array $params = []): int
    {
        if (empty($params)) {
            $result = pg_query($this->conn, $sql);
        } else {
            $result = pg_query_params($this->conn, $sql, $params);
        }

        if ($result === false) {
            throw new OutboxWriteException(
                'PostgreSQL outbox execute failed: ' . pg_last_error($this->conn)
            );
        }

        $affected = pg_affected_rows($result);
        pg_free_result($result);

        return $affected;
    }

    public function query(string $sql, array $params = []): array
    {
        if (empty($params)) {
            $result = pg_query($this->conn, $sql);
        } else {
            $result = pg_query_params($this->conn, $sql, $params);
        }

        if ($result === false) {
            throw new OutboxWriteException(
                'PostgreSQL outbox query failed: ' . pg_last_error($this->conn)
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
            throw new OutboxWriteException(
                'PostgreSQL BEGIN failed: ' . pg_last_error($this->conn)
            );
        }
        pg_free_result($result);
    }

    public function commit(): void
    {
        $result = pg_query($this->conn, 'COMMIT');
        if ($result === false) {
            throw new OutboxWriteException(
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
