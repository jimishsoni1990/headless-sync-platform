<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox\Connection;

use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * MySQL connection for the outbox relay, backed by a raw mysqli handle.
 *
 * Uses mysqli directly (not $wpdb) so that explicit transaction control
 * (BEGIN / COMMIT / ROLLBACK) is available for SELECT … FOR UPDATE SKIP LOCKED.
 * $wpdb does not expose transaction primitives, making it unsuitable for the
 * SKIP LOCKED claim protocol required by OPEN-4 / OPEN-6.
 *
 * Implements MysqlOutboxConnectionInterface — the MySQL-only capture-path
 * contract (DECISION E v1.6). Does NOT implement DatabaseConnectionInterface,
 * which is PostgreSQL-only.
 *
 * Lazy connection (HOTFIX): a `\Closure(): \mysqli` CONNECTOR is injected rather than
 * an already-open handle. The socket is opened on FIRST real use (execute / query /
 * beginTransaction), then memoized — so container resolution and worker/cron wiring do
 * NOT open a connection, and a wp-admin page load never fatals on a MySQL outage. Any
 * connect failure (connector throwing, or a returned handle carrying connect_errno) is
 * translated to OutboxWriteException at THIS boundary (DECISION E v1.6 error semantics),
 * never surfaced as a raw \RuntimeException or mysqli exception.
 */
final class MysqliOutboxConnection implements MysqlOutboxConnectionInterface
{
    /** Memoized handle; null until the first real use triggers connect(). */
    private ?\mysqli $mysqli = null;

    /**
     * @param \Closure(): \mysqli $connector Opens and returns a ready mysqli handle.
     *        Invoked at most once, on first use. Its own connect failures are caught
     *        here and re-thrown as OutboxWriteException.
     */
    public function __construct(private readonly \Closure $connector) {}

    /**
     * Return the memoized mysqli handle, opening it on first call.
     *
     * @throws OutboxWriteException on any connect failure (translated at this boundary).
     */
    private function connection(): \mysqli
    {
        if ($this->mysqli !== null) {
            return $this->mysqli;
        }

        try {
            $mysqli = ($this->connector)();
        } catch (\Throwable $e) {
            // mysqli may throw (mysqli_sql_exception under MYSQLI_REPORT_STRICT) or the
            // connector may raise; either way it is a capture-path connect failure.
            throw new OutboxWriteException(
                'Outbox MySQL connect failed: ' . $e->getMessage(),
                previous: $e,
            );
        }

        if ($mysqli->connect_errno) {
            throw new OutboxWriteException(
                'Outbox MySQL connect failed: ' . $mysqli->connect_error
            );
        }

        return $this->mysqli = $mysqli;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->prepare($sql, $params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt   = $this->prepare($sql, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result === false) {
            $error = $stmt->error;
            $stmt->close();
            throw new OutboxWriteException("MySQL outbox query failed: {$error}");
        }

        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    public function beginTransaction(): void
    {
        $mysqli = $this->connection();
        if (! $mysqli->begin_transaction()) {
            throw new OutboxWriteException(
                'MySQL BEGIN TRANSACTION failed: ' . $mysqli->error
            );
        }
    }

    public function commit(): void
    {
        $mysqli = $this->connection();
        if (! $mysqli->commit()) {
            throw new OutboxWriteException(
                'MySQL COMMIT failed: ' . $mysqli->error
            );
        }
    }

    public function rollback(): void
    {
        // Only roll back if a connection was actually opened; a rollback before first
        // use (e.g. after a failed relay claim that never connected) is a no-op.
        $this->mysqli?->rollback();
    }

    private function prepare(string $sql, array $params): \mysqli_stmt
    {
        $mysqli = $this->connection();
        $stmt   = $mysqli->prepare($sql);

        if ($stmt === false) {
            throw new OutboxWriteException(
                "MySQL outbox prepare failed: {$mysqli->error}\nSQL: {$sql}"
            );
        }

        if (! empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        return $stmt;
    }
}
