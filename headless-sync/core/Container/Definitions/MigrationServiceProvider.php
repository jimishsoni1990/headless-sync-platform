<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Migrations\Connection\ConnectionFactory;
use HSP\Core\Migrations\MigrationRunner;

// MySQL concrete migrations
use HSP\Database\Core\Mysql\CreateHspOutboxMigration;
use HSP\Database\Core\Mysql\CreateHspAggregateCountersMigration;

// PostgreSQL concrete migrations
use HSP\Database\Core\Pgsql\CreateSystemSchemaMigration;
use HSP\Database\Core\Pgsql\CreateSystemEventsMigration;
use HSP\Database\Core\Pgsql\CreateSystemQueueJobsMigration;
use HSP\Database\Core\Pgsql\CreateSystemDeadLetterJobsMigration;
use HSP\Database\Core\Pgsql\CreateSystemAggregateVersionsMigration;
use HSP\Database\Core\Pgsql\CreateSystemProcessedEventsMigration;
use HSP\Database\Core\Pgsql\CreateSystemAuditLogMigration;
use HSP\Database\Core\Pgsql\CreateSystemSchemaVersionsMigration;
use HSP\Database\Core\Pgsql\CreateSystemModuleVersionsMigration;
use HSP\Database\Core\Pgsql\CreateSystemSecurityEventsMigration;
use HSP\Database\Core\Pgsql\CreateSystemWorkerHeartbeatsMigration;
use HSP\Database\Core\Pgsql\AddReplayedAtToDeadLetterJobsMigration;

/**
 * Registers the migration engine and the full set of core migrations.
 *
 * Bindings:
 *   'migration.connection.mysql'  — WpdbMysqlConnection (for MySQL migrations)
 *   'migration.connection.pgsql'  — PgsqlConnection     (for PG migrations + schema_versions)
 *   'migration.runner'            — MigrationRunner
 *   'migrations.core'             — array<MigrationInterface> (all core migrations)
 *
 * Constructor injection only — ADR-012.
 * DECISION O (v1.15): PG credentials supplied via CredentialResolver (define→getenv→default).
 *   ConnectionFactory::pgsql() receives a resolver-built config array keyed to match what
 *   it reads ('host', 'port', 'dbname', 'user', 'password') — no DDL-abstraction change.
 */
final class MigrationServiceProvider extends ServiceProvider
{
    public function __construct(
        private readonly array $config,
        private readonly CredentialResolver $resolver,
    ) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('migration.connection.mysql', function () {
            global $wpdb;
            return ConnectionFactory::wpdbMysql($wpdb);
        });

        $container->singleton('migration.connection.pgsql', function () {
            // Build the config array that ConnectionFactory::pgsql() expects using
            // CredentialResolver — resolves define()→getenv()→default (DECISION O).
            // Key names match what ConnectionFactory reads: 'dbname' (not 'name').
            $cfg = [
                'host'     => $this->resolver->pgHost(),
                'port'     => $this->resolver->pgPort(),
                'dbname'   => $this->resolver->pgDbname(),
                'user'     => $this->resolver->pgUser(),
                'password' => $this->resolver->pgPassword(),
            ];
            return ConnectionFactory::pgsql($cfg);
        });

        $container->singleton('migration.runner', function (Container $c) {
            $sqlPath = dirname(__DIR__, 3)
                . '/database/Core/pgsql/0008_create_system_schema_versions.sql';

            return new MigrationRunner($c->get('migration.connection.pgsql'), $sqlPath);
        });

        $container->singleton('migrations.core', function (Container $c) {
            $mysql = $c->get('migration.connection.mysql');
            $pgsql = $c->get('migration.connection.pgsql');

            return [
                new CreateHspOutboxMigration($mysql),
                new CreateHspAggregateCountersMigration($mysql),
                new CreateSystemSchemaMigration($pgsql),
                new CreateSystemEventsMigration($pgsql),
                new CreateSystemQueueJobsMigration($pgsql),
                new CreateSystemDeadLetterJobsMigration($pgsql),
                new CreateSystemAggregateVersionsMigration($pgsql),
                new CreateSystemProcessedEventsMigration($pgsql),
                new CreateSystemAuditLogMigration($pgsql),
                new CreateSystemSchemaVersionsMigration($pgsql),
                new CreateSystemModuleVersionsMigration($pgsql),
                new CreateSystemSecurityEventsMigration($pgsql),
                // OPS-S1 (v1.16): worker health + DLQ replay audit column.
                new CreateSystemWorkerHeartbeatsMigration($pgsql),   // DECISION P
                new AddReplayedAtToDeadLetterJobsMigration($pgsql),  // DECISION S clause (e)
            ];
        });
    }
}
