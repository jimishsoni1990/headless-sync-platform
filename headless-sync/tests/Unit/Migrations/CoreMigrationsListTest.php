<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Migrations;

use HSP\Bootstrap\CredentialResolver;
use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\MigrationServiceProvider;
use HSP\Core\Contracts\MigrationInterface;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the core migration delegate list (`migrations.core`).
 *
 * The onboarding MigrationApplier applies EXACTLY this list (plus module migrations) through the
 * engine, so any pipeline-critical migration missing here means a fresh install never applies it —
 * masked by integration tests that seed schema directly. This surfaced in the ONB-S2 fresh-install
 * E2E: `0011_add_unique_event_id_to_queue_jobs` shipped as a raw SQL file but was never wired into
 * this list, so `ON CONFLICT(event_id)` on `system.queue_jobs` failed on the first dispatch.
 *
 * These tests assert every pipeline-critical migration is present. Resolving `migrations.core` does
 * NOT open any DB connection (the migration objects are constructed with a lazy connection but not
 * run here), so this stays a pure unit test.
 */
final class CoreMigrationsListTest extends TestCase
{
    /** Migrations without which the pipeline cannot run on a fresh install. */
    private const PIPELINE_CRITICAL = [
        '0001_create_system_schema',
        '0002_create_system_events',
        '0003_create_system_queue_jobs',
        '0011_add_unique_event_id_to_queue_jobs', // UNIQUE(event_id) — dispatcher ON CONFLICT gate.
        '0005_create_system_aggregate_versions',
        '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
    ];

    public function test_core_migration_list_contains_every_pipeline_critical_migration(): void
    {
        $names = $this->coreMigrationNames();

        foreach (self::PIPELINE_CRITICAL as $required) {
            self::assertContains(
                $required,
                $names,
                "migrations.core is missing pipeline-critical migration '{$required}' — a fresh "
                    . 'install would never apply it (regression guard for the ONB-S2 0011 gap).',
            );
        }
    }

    public function test_core_migration_names_are_unique(): void
    {
        $names = $this->coreMigrationNames();

        self::assertSame(
            array_values(array_unique($names)),
            array_values($names),
            'migrations.core has a duplicate migration name.',
        );
    }

    /** @return list<string> getName() of every core migration, without opening a DB connection. */
    private function coreMigrationNames(): array
    {
        $container = new Container();

        (new MigrationServiceProvider([], new CredentialResolver()))->register($container);

        // The connections are only USED when a migration RUNS; constructing the list only stores
        // them. Override the provider's connection bindings with ConnectionInterface stubs (AFTER
        // register(), so ours win) so list assembly resolves without a real DB / wpdb.
        $stub = static fn () => new class implements \HSP\Core\Migrations\Connection\ConnectionInterface {
            public function execute(string $sql): void {}
            public function query(string $sql, array $params = []): array { return []; }
            public function insert(string $sql, array $params = []): int { return 0; }
        };
        $container->singleton('migration.connection.mysql', $stub);
        $container->singleton('migration.connection.pgsql', $stub);

        /** @var list<MigrationInterface> $migrations */
        $migrations = $container->get('migrations.core');

        return array_map(static fn (MigrationInterface $m) => $m->getName(), $migrations);
    }
}
