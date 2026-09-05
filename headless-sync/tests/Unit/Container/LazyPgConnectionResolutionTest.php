<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Container;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\DeliveryServiceProvider;
use HSP\Core\Container\Definitions\DispatcherServiceProvider;
use HSP\Core\Container\Definitions\OutboxServiceProvider;
use HSP\Core\Container\Definitions\QueueServiceProvider;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard (LAZYPG-S1): resolving a PostgreSQL binding must not connect.
 *
 * All four runtime handles used to call pg_connect() inside their singleton factory,
 * so merely RESOLVING one threw when PostgreSQL was unreachable or unconfigured. On the
 * delivery handle that was user-visible: ContentModule::boot() defers the REST registrar
 * to rest_api_init, but that hook fires on EVERY REST request to the site (wp/v2 and the
 * block editor included) and building the registrar resolves the query providers — so an
 * unreachable PostgreSQL fatalled every REST request, not just hsp/v1.
 *
 * Each factory now hands a CONNECTOR closure to the connection wrapper; the socket opens
 * on first real use. This test runs with NO HSP_PG_* configuration, which is the sharpest
 * form of the guard: CredentialResolver::pgHost() throws for a missing credential, so if a
 * factory ever reads the DSN (let alone dials) at resolution time, resolution throws again.
 *
 * The four handles and their PGSQL_CONNECT_FORCE_NEW isolation are unchanged — DECISION K,
 * DECISION L Ruling 0 (four-handle topology), DECISION E (no new pg_* wrapper).
 */
final class LazyPgConnectionResolutionTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
        $config          = [];
        $resolver        = new CredentialResolver();

        (new OutboxServiceProvider($config, $resolver))->register($this->container);
        (new QueueServiceProvider($config, $resolver))->register($this->container);
        (new DeliveryServiceProvider($config, $resolver))->register($this->container);
        (new DispatcherServiceProvider($config, $resolver))->register($this->container);
    }

    public function test_delivery_handle_resolves_without_connecting(): void
    {
        self::assertInstanceOf(
            PostgresDatabaseConnection::class,
            $this->container->get(DatabaseConnectionInterface::class),
        );
    }

    public function test_relay_handle_resolves_without_connecting(): void
    {
        self::assertInstanceOf(
            PgsqlOutboxConnection::class,
            $this->container->get('outbox.connection.pgsql'),
        );
    }

    public function test_queue_claim_handle_resolves_without_connecting(): void
    {
        self::assertInstanceOf(
            DatabaseQueueConnection::class,
            $this->container->get('queue.connection.pgsql'),
        );
    }

    public function test_dispatcher_handle_resolves_without_connecting(): void
    {
        self::assertInstanceOf(
            PostgresDatabaseConnection::class,
            $this->container->get('dispatcher.connection.pgsql'),
        );
    }

    /**
     * The four handles stay four distinct objects — DECISION L Ruling 0 topology.
     */
    public function test_the_four_handles_remain_distinct_objects(): void
    {
        $handles = [
            $this->container->get(DatabaseConnectionInterface::class),
            $this->container->get('outbox.connection.pgsql'),
            $this->container->get('queue.connection.pgsql'),
            $this->container->get('dispatcher.connection.pgsql'),
        ];

        self::assertCount(4, array_unique(array_map(spl_object_id(...), $handles)));
    }
}
