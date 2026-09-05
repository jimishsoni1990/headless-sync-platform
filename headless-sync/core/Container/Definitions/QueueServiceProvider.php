<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Cli\DlqCommand;
use HSP\Core\Contracts\QueueProviderInterface;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Queue\DeadLetterRepository;
use HSP\Core\Queue\Providers\Database\DatabaseQueueConnection;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;

/**
 * Registers the database queue provider and its own FORCE_NEW claim connection.
 *
 * Bindings:
 *   'queue.connection.pgsql'  — DatabaseQueueConnection (its own FORCE_NEW handle)
 *   QueueProviderInterface    — DatabaseQueueProvider
 *
 * DatabaseConnectionInterface is NOT bound here. It is bound exclusively in
 * DeliveryServiceProvider (DECISION K v1.11) so the delivery/Resolve-stage
 * connection is guaranteed physically separate from the queue-claim handle.
 *
 * Constructor injection only — ADR-012.
 * DECISION E v1.6: queue collapses fully into DatabaseConnectionInterface contract.
 * DECISION O (v1.15): credentials resolved via CredentialResolver (define→getenv→default).
 * Config keys (under 'queue'): retry_limit, visibility_timeout_seconds,
 *                              backoff_base_seconds, backoff_cap_seconds.
 */
final class QueueServiceProvider extends ServiceProvider
{
    public function __construct(
        private readonly array $config,
        private readonly CredentialResolver $resolver,
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('queue.connection.pgsql', function () {
            // Lazy connection: the CONNECTOR closure is handed down to
            // PostgresDatabaseConnection, so container resolution opens no socket and a
            // connect failure surfaces as QueueException on first real use instead of a
            // raw \RuntimeException escaping the factory. Same handle, same FORCE_NEW
            // isolation (DECISION K / DECISION L Ruling 0) — only the timing changed.
            $resolver = $this->resolver;

            $connector = static function () use ($resolver) {
                $conn = \pg_connect($resolver->pgDsn(), PGSQL_CONNECT_FORCE_NEW);

                if ($conn === false) {
                    throw new \RuntimeException('Queue PostgreSQL connect failed.');
                }

                return $conn;
            };

            return new DatabaseQueueConnection($connector);
        });

        $container->singleton(QueueProviderInterface::class, function (Container $c) {
            $queueConfig = $this->config['queue'] ?? [];

            return new DatabaseQueueProvider(
                $c->get('queue.connection.pgsql'),
                $queueConfig,
            );
        });

        // Concrete alias so DispatcherServiceProvider can resolve DatabaseQueueProvider
        // directly and call enqueueIdempotent() (not on QueueProviderInterface — DECISION L).
        $container->singleton(
            DatabaseQueueProvider::class,
            fn (Container $c) => $c->get(QueueProviderInterface::class),
        );

        // DLQ read/replay surface (DECISION S). System-side DML on the queue tables —
        // uses the runtime queue/worker handle (DECISION L Ruling 0 — no new handle).
        $container->singleton(
            DeadLetterRepository::class,
            fn (Container $c) => new DeadLetterRepository($c->get('queue.connection.pgsql')),
        );

        // WP-CLI DLQ command surface (DECISION S clause (d)). Registered with WP_CLI in
        // headless-sync.php; bound here so it resolves from the composition root.
        $container->singleton(
            DlqCommand::class,
            fn (Container $c) => new DlqCommand(
                $c->get(DeadLetterRepository::class),
                $c->get(StructuredLogger::class),
            ),
        );
    }
}
