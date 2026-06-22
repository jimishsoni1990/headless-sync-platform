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
 * The mysqli handle is injected — its lifecycle (connect/close) belongs to the caller.
 */
final class MysqliOutboxConnection implements OutboxConnectionInterface
{
    public function __construct(private readonly \mysqli $mysqli) {}

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
        if (! $this->mysqli->begin_transaction()) {
            throw new OutboxWriteException(
                'MySQL BEGIN TRANSACTION failed: ' . $this->mysqli->error
            );
        }
    }

    public function commit(): void
    {
        if (! $this->mysqli->commit()) {
            throw new OutboxWriteException(
                'MySQL COMMIT failed: ' . $this->mysqli->error
            );
        }
    }

    public function rollback(): void
    {
        $this->mysqli->rollback();
    }

    private function prepare(string $sql, array $params): \mysqli_stmt
    {
        $stmt = $this->mysqli->prepare($sql);

        if ($stmt === false) {
            throw new OutboxWriteException(
                "MySQL outbox prepare failed: {$this->mysqli->error}\nSQL: {$sql}"
            );
        }

        if (! empty($params)) {
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
        }

        return $stmt;
    }
}
