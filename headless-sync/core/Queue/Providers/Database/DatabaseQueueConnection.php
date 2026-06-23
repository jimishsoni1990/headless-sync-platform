<?php

declare(strict_types=1);

namespace HSP\Core\Queue\Providers\Database;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Queue\Exception\QueueException;

/**
 * PostgreSQL connection wrapper for the database queue provider.
 *
 * Implements DatabaseConnectionInterface via composition over
 * PostgresDatabaseConnection and translates DatabaseException to
 * QueueException at the queue boundary (DECISION E v1.6).
 *
 * Accepts either a raw pg_connect() handle (production) or an
 * already-constructed PostgresDatabaseConnection (test injection).
 */
final class DatabaseQueueConnection implements DatabaseConnectionInterface
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
                throw new QueueException(
                    'DatabaseQueueConnection: ' . $e->getMessage(),
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
            throw new QueueException(
                'Queue DB execute failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function query(string $sql, array $params = []): array
    {
        try {
            return $this->inner->query($sql, $params);
        } catch (DatabaseException $e) {
            throw new QueueException(
                'Queue DB query failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function beginTransaction(): void
    {
        try {
            $this->inner->beginTransaction();
        } catch (DatabaseException $e) {
            throw new QueueException(
                'Queue DB BEGIN failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function commit(): void
    {
        try {
            $this->inner->commit();
        } catch (DatabaseException $e) {
            throw new QueueException(
                'Queue DB COMMIT failed: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }
}
