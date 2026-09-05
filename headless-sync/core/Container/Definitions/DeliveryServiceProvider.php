<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\CredentialResolver;
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
 *   DECISION O (v1.15) — credentials resolved via CredentialResolver (define→getenv→default).
 *   FLAG-P0S5-1 / P0-S5 precedent — PGSQL_CONNECT_FORCE_NEW required wherever
 *     libpq pooling would entangle independent logical connections.
 *   ADR-012 — constructor injection only; no service-locator calls.
 *
 * Lazy connection: a CONNECTOR closure is handed to PostgresDatabaseConnection —
 * no socket is opened at container-resolution time. rest_api_init fires on EVERY
 * REST request to the site (wp/v2 and the block editor included) and building the
 * content registrar resolves this binding, so connecting here made an unreachable
 * PostgreSQL fatal every REST request. The link now opens on first real query and
 * a connect failure surfaces as DatabaseException, not a raw \RuntimeException.
 * Same handle, same FORCE_NEW isolation (DECISION K / DECISION L Ruling 0) — only
 * the moment of connecting changed.
 */
final class DeliveryServiceProvider extends ServiceProvider
{
    public function __construct(
        private readonly array $config,
        private readonly CredentialResolver $resolver,
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton(DatabaseConnectionInterface::class, function (): PostgresDatabaseConnection {
            $resolver = $this->resolver;

            // PGSQL_CONNECT_FORCE_NEW guarantees a distinct physical libpq link
            // from the relay handle (outbox.connection.pgsql) and the queue-claim
            // handle (queue.connection.pgsql). This is the DECISION K requirement.
            $connector = static function () use ($resolver) {
                $conn = \pg_connect($resolver->pgDsn(), PGSQL_CONNECT_FORCE_NEW);

                if ($conn === false) {
                    throw new \RuntimeException('Delivery PostgreSQL connect failed.');
                }

                return $conn;
            };

            return new PostgresDatabaseConnection($connector);
        });
    }
}
