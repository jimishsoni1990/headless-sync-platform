<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Events\Dispatcher\DispatcherWorkerStrategy;
use HSP\Core\Events\Dispatcher\EventDispatcher;
use HSP\Core\Queue\Providers\Database\DatabaseQueueProvider;

/**
 * Registers the Dispatcher stage (system.events → system.queue_jobs).
 *
 * Bindings:
 *   'dispatcher.connection.pgsql' — PostgresDatabaseConnection, FORCE_NEW
 *   'dispatcher.strategy'         — DispatcherWorkerStrategy
 *
 * ADR-054 (DECISION X, v1.24): dispatch is a STAGE of the one Processing Engine cycle, not a
 * standalone daemon. The former 'dispatcher.engine' (a standalone WorkerEngine daemon driven
 * by DispatcherWorkerStrategy) is RETIRED from the execution path — WorkerServiceProvider's
 * single cycle engine composes 'dispatcher.strategy' as its dispatch stage. The strategy +
 * its dedicated connection are kept.
 *
 * Connection wiring (DECISION L v1.12):
 *   The Dispatcher is relay/queue-side system DML — it MUST NOT use the DECISION K
 *   delivery handle (DatabaseConnectionInterface singleton). It opens its own dedicated
 *   FORCE_NEW connection ('dispatcher.connection.pgsql') for the system.events anti-join
 *   read, following the same pattern as DeliveryServiceProvider (DECISION K).
 *   This guarantees the dispatcher handle is physically distinct from both the delivery
 *   handle and the relay/queue handles.
 *
 *   No new raw pg_* wrapper class introduced (DECISION E v1.6: the constraint is on wrapper
 *   classes, not on pg_connect() calls; PostgresDatabaseConnection is an existing class).
 *
 *   EventDispatcher receives DatabaseQueueProvider (queue-claim handle) for enqueue writes.
 *
 * Registration order constraint:
 *   QueueServiceProvider MUST be registered before this provider
 *   (DatabaseQueueProvider must already be bound).
 *   DeliveryServiceProvider need NOT be registered first — dispatcher does NOT use
 *   DatabaseConnectionInterface::class.
 *
 * Authority: DECISION L (v1.12, 2026-06-25); DECISION E (v1.6); DECISION K (v1.11)
 *            (pattern followed; delivery handle NOT reused); CLAUDE.md Rule 7.
 *            DECISION O (v1.15): credentials resolved via CredentialResolver.
 */
final class DispatcherServiceProvider extends ServiceProvider
{
    public function __construct(
        private readonly array $config,
        private readonly CredentialResolver $resolver,
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('dispatcher.connection.pgsql', function (): PostgresDatabaseConnection {
            $dsn = $this->resolver->pgDsn();

            // FORCE_NEW guarantees a distinct physical libpq link from the delivery handle
            // (DatabaseConnectionInterface, DECISION K) and the relay/queue handles.
            $conn = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

            if ($conn === false) {
                throw new \RuntimeException('Dispatcher PostgreSQL connect failed.');
            }

            return new PostgresDatabaseConnection($conn);
        });

        $container->singleton('dispatcher.strategy', function (Container $c): DispatcherWorkerStrategy {
            // ADR-054: the dispatch stage's per-cycle max batch size is config-driven
            // (processing.dispatch_batch_size — Doc 8 v2.0 §9). Default 100 if unset.
            $processing = $this->config['worker']['processing'] ?? [];
            $batchSize  = (int) ($processing['dispatch_batch_size'] ?? 100);

            return new DispatcherWorkerStrategy(
                new EventDispatcher(
                    $c->get('dispatcher.connection.pgsql'),
                    $c->get(DatabaseQueueProvider::class),
                    $batchSize > 0 ? $batchSize : 100,
                ),
            );
        });
    }
}
