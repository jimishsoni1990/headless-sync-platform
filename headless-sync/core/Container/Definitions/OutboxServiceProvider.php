<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\AggregateVersionCounterInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Events\Outbox\AggregateVersionCounter;
use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Events\Outbox\OutboxWriter;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;

/**
 * Registers outbox capture and relay bindings.
 *
 * Bindings:
 *   'outbox.connection.mysql'  — MysqliOutboxConnection (MysqlOutboxConnectionInterface)
 *   'outbox.connection.pgsql'  — PgsqlOutboxConnection  (DatabaseConnectionInterface)
 *   AggregateVersionCounterInterface — AggregateVersionCounter
 *   OutboxWriterInterface            — OutboxWriter
 *   'relay.worker'             — RelayWorkerStrategy
 *
 * Constructor injection only — ADR-012.
 * DECISION E v1.6: MySQL capture path and PG delivery path are distinct contracts.
 * DECISION O (v1.15): credentials resolved via CredentialResolver (define→getenv→default).
 */
final class OutboxServiceProvider extends ServiceProvider
{
    public function __construct(
        private readonly array $config,
        private readonly CredentialResolver $resolver,
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('outbox.connection.mysql', function () {
            $mysqli = new \mysqli(
                $this->resolver->mysqlHost(),
                $this->resolver->mysqlUser(),
                $this->resolver->mysqlPassword(),
                $this->resolver->mysqlDbname(),
                $this->resolver->mysqlPort(),
            );

            if ($mysqli->connect_errno) {
                throw new \RuntimeException(
                    'Outbox MySQL connect failed: ' . $mysqli->connect_error
                );
            }

            $mysqli->set_charset('utf8mb4');

            return new MysqliOutboxConnection($mysqli);
        });

        $container->singleton('outbox.connection.pgsql', function () {
            $dsn  = $this->resolver->pgDsn();
            $conn = \pg_connect($dsn);

            if ($conn === false) {
                throw new \RuntimeException('Outbox PostgreSQL connect failed.');
            }

            return new PgsqlOutboxConnection($conn);
        });

        $container->singleton(AggregateVersionCounterInterface::class, function () {
            global $wpdb;
            return new AggregateVersionCounter($wpdb);
        });

        $container->singleton(OutboxWriterInterface::class, function (Container $c) {
            global $wpdb;
            return new OutboxWriter($wpdb, $c->get(AggregateVersionCounterInterface::class));
        });

        $container->singleton('relay.worker', function (Container $c) {
            global $wpdb;
            $batchSize = (int) ($this->config['relay']['batch_size'] ?? 100);

            return new RelayWorkerStrategy(
                $c->get('outbox.connection.mysql'),
                $c->get('outbox.connection.pgsql'),
                $wpdb->prefix,
                $batchSize,
            );
        });
    }
}
