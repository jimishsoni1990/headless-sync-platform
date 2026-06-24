<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;

/**
 * Registers the database queue provider and its connection.
 *
 * Bindings:
 *   'queue.connection.pgsql'  — DatabaseQueueConnection (DatabaseConnectionInterface)
 *   QueueProviderInterface    — DatabaseQueueProvider
 *
 * Constructor injection only — ADR-012.
 * DECISION E v1.6: queue collapses fully into DatabaseConnectionInterface.
 * Config keys (under 'queue'): retry_limit, visibility_timeout_seconds,
 *                              backoff_base_seconds, backoff_cap_seconds.
 */
final class QueueServiceProvider extends ServiceProvider
{
    public function __construct(private readonly array $config) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        // Shared runtime PostgreSQL connection for all DML subsystems (DECISION E v1.6).
        // Registered under the interface so query providers, adapters, and other
        // runtime consumers can depend on DatabaseConnectionInterface directly.
        $container->singleton(DatabaseConnectionInterface::class, function () {
            $cfg = $this->config['database']['pgsql'] ?? [];
            $dsn = sprintf(
                'host=%s port=%d dbname=%s user=%s password=%s',
                $cfg['host']     ?? 'localhost',
                (int) ($cfg['port']     ?? 5432),
                $cfg['name']     ?? '',
                $cfg['user']     ?? '',
                $cfg['password'] ?? '',
            );
            $conn = \pg_connect($dsn);

            if ($conn === false) {
                throw new \RuntimeException('PostgreSQL connect failed.');
            }

            return new PostgresDatabaseConnection($conn);
        });

        $container->singleton('queue.connection.pgsql', function (Container $c) {
            // The queue provider wraps the shared connection to translate
            // DatabaseException → QueueException at the queue boundary.
            $cfg  = $this->config['database']['pgsql'] ?? [];
            $dsn  = sprintf(
                'host=%s port=%d dbname=%s user=%s password=%s',
                $cfg['host']     ?? 'localhost',
                (int) ($cfg['port']     ?? 5432),
                $cfg['name']     ?? '',
                $cfg['user']     ?? '',
                $cfg['password'] ?? '',
            );
            $conn = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

            if ($conn === false) {
                throw new \RuntimeException('Queue PostgreSQL connect failed.');
            }

            return new DatabaseQueueConnection($conn);
        });

        $container->singleton(QueueProviderInterface::class, function (Container $c) {
            $queueConfig = $this->config['queue'] ?? [];

            return new DatabaseQueueProvider(
                $c->get('queue.connection.pgsql'),
                $queueConfig,
            );
        });
    }
}
