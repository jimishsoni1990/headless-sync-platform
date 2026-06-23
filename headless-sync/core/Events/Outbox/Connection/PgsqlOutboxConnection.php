<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox\Connection;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * PostgreSQL connection for the outbox relay (PG delivery path).
 *
 * Implements DatabaseConnectionInterface via composition over
 * PostgresDatabaseConnection and translates DatabaseException to
 * OutboxWriteException at the relay boundary (DECISION E v1.6).
 *
 * Accepts either a raw pg_connect() handle (production) or an
 * already-constructed PostgresDatabaseConnection (test injection).
 */
final class PgsqlOutboxConnection implements DatabaseConnectionInterface
{
    private PostgresDatabaseConnection $inner;

    public function __construct(mixed $connOrInstance)
    {
        if ($connOrInstance instanceof PostgresDatabaseConnection) {
            $this->inner = $connOrInstance;
        } else {
            try {
                $this->inner = new PostgresDatabaseConnection($connOrInstance);
            } catch (DatabaseException $e) {
                throw new OutboxWriteException(
                    'PgsqlOutboxConnection: ' . $e->getMessage(),
                    previous: $e,
                );
            }
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        try {
            return $this->inner->execute($sql, $params);
        } catch (DatabaseException $e) {
            throw new OutboxWriteException(
                'PostgreSQL outbox execute failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function query(string $sql, array $params = []): array
    {
        try {
            return $this->inner->query($sql, $params);
        } catch (DatabaseException $e) {
            throw new OutboxWriteException(
                'PostgreSQL outbox query failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function beginTransaction(): void
    {
        try {
            $this->inner->beginTransaction();
        } catch (DatabaseException $e) {
            throw new OutboxWriteException(
                'PostgreSQL outbox BEGIN failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function commit(): void
    {
        try {
            $this->inner->commit();
        } catch (DatabaseException $e) {
            throw new OutboxWriteException(
                'PostgreSQL outbox COMMIT failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }
}
