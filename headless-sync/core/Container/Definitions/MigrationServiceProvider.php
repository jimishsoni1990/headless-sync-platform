<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

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
 */
final class MigrationServiceProvider extends ServiceProvider
{
    public function __construct(private readonly array $config) {}

    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton('migration.connection.mysql', function () {
            global $wpdb;
            return ConnectionFactory::wpdbMysql($wpdb);
        });

        $container->singleton('migration.connection.pgsql', function () {
            $cfg = $this->config['database']['pgsql'] ?? [];
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
            ];
        });
    }
}
