<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\PostgresDatabaseConnection;

/**
 * Registers the dedicated delivery PostgreSQL connection.
 *
 * Bindings:
 *   DatabaseConnectionInterface — PostgresDatabaseConnection opened with
 *                                 PGSQL_CONNECT_FORCE_NEW (guaranteed distinct
 *                                 physical link from relay and queue handles).
 *
 * Authority:
 *   DECISION K (v1.11) — delivery reads (REST query providers), Resolve-stage
 *     reads (EventWorkerStrategy), and adapter persistence all share this one
 *     dedicated connection. Cross-sharing with 'outbox.connection.pgsql'
 *     (relay) or 'queue.connection.pgsql' (queue claim) is prohibited.
 *   DECISION E (v1.6) — no new raw pg_* wrapper; PostgresDatabaseConnection reused.
 *   FLAG-P0S5-1 / P0-S5 precedent — PGSQL_CONNECT_FORCE_NEW required wherever
 *     libpq pooling would entangle independent logical connections.
 *   ADR-012 — constructor injection only; no service-locator calls.
 */
final class DeliveryServiceProvider extends ServiceProvider
{
    public function __construct(private readonly array $config) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton(DatabaseConnectionInterface::class, function (): PostgresDatabaseConnection {
            $cfg = $this->config['database']['pgsql'] ?? [];
            $dsn = sprintf(
                'host=%s port=%d dbname=%s user=%s password=%s',
                $cfg['host']     ?? 'localhost',
                (int) ($cfg['port']     ?? 5432),
                $cfg['name']     ?? '',
                $cfg['user']     ?? '',
                $cfg['password'] ?? '',
            );

            // PGSQL_CONNECT_FORCE_NEW guarantees a distinct physical libpq link
            // from the relay handle (outbox.connection.pgsql) and the queue-claim
            // handle (queue.connection.pgsql). This is the DECISION K requirement.
            $conn = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

            if ($conn === false) {
                throw new \RuntimeException('Delivery PostgreSQL connect failed.');
            }

            return new PostgresDatabaseConnection($conn);
        });
    }
}
