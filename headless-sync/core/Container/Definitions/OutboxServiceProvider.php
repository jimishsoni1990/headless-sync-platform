<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

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
 */
final class OutboxServiceProvider extends ServiceProvider
{
    public function __construct(private readonly array $config) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('outbox.connection.mysql', function () {
            $cfg    = $this->config['database']['mysql'] ?? [];
            $mysqli = new \mysqli(
                $cfg['host']     ?? 'localhost',
                $cfg['user']     ?? '',
                $cfg['password'] ?? '',
                $cfg['name']     ?? '',
                (int) ($cfg['port'] ?? 3306),
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
            $cfg  = $this->config['database']['pgsql'] ?? [];
            $dsn  = sprintf(
                'host=%s port=%d dbname=%s user=%s password=%s',
                $cfg['host']     ?? 'localhost',
                $cfg['port']     ?? 5432,
                $cfg['name']     ?? '',
                $cfg['user']     ?? '',
                $cfg['password'] ?? '',
            );
            $conn = pg_connect($dsn);

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
