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
 *
 * HOTFIX: the MySQL binding builds a CONNECTOR closure and hands it to
 * MysqliOutboxConnection — NO socket is opened at container-resolution or worker-wiring
 * time. The connection opens lazily on first real relay use, and any connect failure is
 * translated to OutboxWriteException at the connection boundary rather than fataling the
 * WordPress request (previously an uncaught \RuntimeException at plugins_loaded).
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
            // HOTFIX: build a CONNECTOR closure — no socket is opened here, at container
            // resolution time. MysqliOutboxConnection invokes it lazily on first real use
            // and translates any connect failure to OutboxWriteException (DECISION E v1.6).
            // Credentials/host/port/socket derive from the CredentialResolver (DECISION O):
            //   - host/port/socket are split from DB_HOST (wpdb::parse_db_host parity)
            //   - port 0 + socket null let mysqli fall back to mysqli.default_port /
            //     mysqli.default_socket (how $wpdb reaches non-3306 stacks like Local).
            $resolver = $this->resolver;

            $connector = static function () use ($resolver): \mysqli {
                $host   = $resolver->mysqlHost();
                $socket = $resolver->mysqlSocket();

                // A socket-only DB_HOST (":/path.sock") yields an empty host; mysqli wants
                // null there so it uses the socket rather than an empty TCP hostname.
                $mysqli = new \mysqli(
                    $host !== '' ? $host : null,
                    $resolver->mysqlUser(),
                    $resolver->mysqlPassword(),
                    $resolver->mysqlDbname(),
                    $resolver->mysqlPort(),   // 0 → defer to mysqli.default_port
                    $socket,                  // null → defer to mysqli.default_socket
                );

                $mysqli->set_charset('utf8mb4');

                return $mysqli;
            };

            return new MysqliOutboxConnection($connector);
        });

        $container->singleton('outbox.connection.pgsql', function () {
            // Lazy connection, matching the MySQL side above: the CONNECTOR closure is
            // invoked on first real relay use, so container resolution and cron wiring
            // open no socket, and a connect failure surfaces as OutboxWriteException at
            // the relay boundary instead of a raw \RuntimeException from the factory.
            $resolver = $this->resolver;

            $connector = static function () use ($resolver) {
                $conn = \pg_connect($resolver->pgDsn());

                if ($conn === false) {
                    throw new \RuntimeException('Outbox PostgreSQL connect failed.');
                }

                return $conn;
            };

            return new PgsqlOutboxConnection($connector);
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
            // ADR-054: the relay stage's per-cycle max batch size is the config-driven
            // processing.relay_batch_size (Doc 8 v2.0 §9). Falls back to the legacy
            // relay.batch_size, then a sensible default.
            $processing = $this->config['worker']['processing'] ?? [];
            $batchSize  = (int) ($processing['relay_batch_size']
                ?? $this->config['relay']['batch_size']
                ?? 100);

            return new RelayWorkerStrategy(
                $c->get('outbox.connection.mysql'),
                $c->get('outbox.connection.pgsql'),
                $wpdb->prefix,
                $batchSize,
            );
        });
    }
}
