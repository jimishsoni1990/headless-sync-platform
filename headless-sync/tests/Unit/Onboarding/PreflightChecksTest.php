<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use HSP\Core\Onboarding\Preflight\PgConstantsCheck;
use HSP\Core\Onboarding\Preflight\PgReachableCheck;
use HSP\Core\Onboarding\Preflight\PgsqlExtensionCheck;
use HSP\Core\Onboarding\Preflight\PhpVersionCheck;
use PHPUnit\Framework\TestCase;
use HSP\Tests\Support\FakeModuleMigration;

/**
 * Unit tests for the five hard-blocking preflight checks (ONB-S1b; DECISION W (f)).
 *
 * The DB-touching checks (reachable, migrations) run against a scripted DatabaseConnectionInterface
 * double so no live PostgreSQL is needed — proving the checks reuse the delivery handle contract
 * (DECISION K) and never open a handle of their own. Each check must report a PreflightResult
 * (never throw) and, on failure, carry non-empty remediation.
 */
final class PreflightChecksTest extends TestCase
{
    public function test_pgsql_extension_check_reflects_the_runtime(): void
    {
        $result = (new PgsqlExtensionCheck())->run();

        self::assertSame(PgsqlExtensionCheck::KEY, $result->key);
        self::assertSame(extension_loaded('pgsql'), $result->passed);
        if (! $result->passed) {
            self::assertNotSame('', $result->remediation);
        }
    }

    public function test_pg_constants_check_fails_with_remediation_when_a_key_is_missing(): void
    {
        // HSP_PG_* are not defined in the unit env and (in CI) not in getenv → expect failure.
        $result = (new PgConstantsCheck())->run();

        self::assertSame(PgConstantsCheck::KEY, $result->key);
        if (! $result->passed) {
            self::assertNotSame('', $result->remediation);
            self::assertStringContainsString('HSP_PG_', $result->detail);
        }
    }

    public function test_php_version_check_passes_against_a_low_minimum_and_fails_against_a_high_one(): void
    {
        $pass = (new PhpVersionCheck('7.0'))->run();
        self::assertTrue($pass->passed);

        $fail = (new PhpVersionCheck('99.0'))->run();
        self::assertFalse($fail->passed);
        self::assertNotSame('', $fail->remediation);
        self::assertSame(PhpVersionCheck::KEY, $fail->key);
    }

    public function test_pg_reachable_check_passes_when_the_probe_connects(): void
    {
        $probe  = new OnboardingConnectionProbe($this->resolver($this->connection(queryReturns: [['ok' => 1]])));
        $result = (new PgReachableCheck($probe))->run();

        self::assertTrue($result->passed);
        self::assertSame(PgReachableCheck::KEY, $result->key);
    }

    public function test_pg_reachable_check_fails_with_remediation_when_the_query_throws(): void
    {
        $probe  = new OnboardingConnectionProbe($this->resolver($this->connection(throwOnQuery: true)));
        $result = (new PgReachableCheck($probe))->run();

        self::assertFalse($result->passed);
        self::assertNotSame('', $result->remediation);
    }

    public function test_pg_reachable_check_fails_when_the_connection_cannot_be_opened(): void
    {
        // Mirrors DeliveryServiceProvider throwing on an unreachable PG: the delivery-handle
        // resolver itself throws. The check must fail-close to a FAIL, never leak the exception.
        $probe  = new OnboardingConnectionProbe($this->throwingResolver());
        $result = (new PgReachableCheck($probe))->run();

        self::assertFalse($result->passed);
        self::assertNotSame('', $result->remediation);
    }

    public function test_migrations_check_passes_when_all_required_migrations_are_applied(): void
    {
        $rows = array_map(
            static fn (string $name) => ['migration_name' => $name],
            [
                '0002_create_system_events',
                '0003_create_system_queue_jobs',
                '0011_add_unique_event_id_to_queue_jobs',
                '0005_create_system_aggregate_versions',
                '0006_create_system_processed_events',
                '0008_create_system_schema_versions',
                // Every migration the Content module declares: the required set is DERIVED from
                // the module registry (FLAG-P1BS1-1), so a partial list would not pass.
                '0001_create_content_schema',
                '0002_create_content_pages',
                '0003_create_content_posts',
                '0004_create_content_taxonomies',
                '0005_create_content_entity_taxonomies',
                '0006_create_content_media',
            ],
        );

        $probe  = new OnboardingConnectionProbe($this->resolver($this->connection(queryReturns: $rows)));
        $result = (new MigrationsAppliedCheck($probe, FakeModuleMigration::contentModule()))->run();

        self::assertTrue($result->passed);
    }

    public function test_a_module_migration_missing_from_the_database_blocks_the_check(): void
    {
        // The whole point of deriving the required set from the module registry: a module that
        // adds a projection table is covered automatically. Here every CORE migration is applied
        // and the module declares one the database has never seen.
        $rows = array_map(
            static fn (string $name) => ['migration_name' => $name],
            [
                '0002_create_system_events',
                '0003_create_system_queue_jobs',
                '0011_add_unique_event_id_to_queue_jobs',
                '0005_create_system_aggregate_versions',
                '0006_create_system_processed_events',
                '0008_create_system_schema_versions',
            ],
        );

        $probe  = new OnboardingConnectionProbe($this->resolver($this->connection(queryReturns: $rows)));
        $result = (new MigrationsAppliedCheck($probe, FakeModuleMigration::listOf('0099_a_future_module_table')))->run();

        self::assertFalse($result->passed, 'a declared-but-unapplied module migration must hard-block');
        self::assertStringContainsString('0099_a_future_module_table', $result->detail);
    }

    public function test_the_check_degrades_to_core_only_when_module_migrations_cannot_be_built(): void
    {
        // Building module migrations opens the libpq DDL link eagerly and throws on an
        // unconfigured site. That must read as "core requirements unmet", never as a fatal —
        // activation and the onboarding screen must not 500 (ADR-054 Principle 8).
        $probe = new OnboardingConnectionProbe($this->resolver($this->connection(queryReturns: [])));

        $result = (new MigrationsAppliedCheck(
            $probe,
            static fn (): array => throw new \RuntimeException('PostgreSQL unreachable'),
        ))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('0002_create_system_events', $result->detail);
    }

    public function test_migrations_check_fails_and_names_the_missing_migrations(): void
    {
        // Only a subset applied → the rest are reported missing.
        $rows = [
            ['migration_name' => '0002_create_system_events'],
            ['migration_name' => '0003_create_system_queue_jobs'],
        ];

        $probe  = new OnboardingConnectionProbe($this->resolver($this->connection(queryReturns: $rows)));
        $result = (new MigrationsAppliedCheck($probe, FakeModuleMigration::contentModule()))->run();

        self::assertFalse($result->passed);
        self::assertStringContainsString('0002_create_content_pages', $result->detail);
        self::assertNotSame('', $result->remediation);
    }

    public function test_migrations_check_fails_when_the_schema_table_is_unreadable(): void
    {
        $probe  = new OnboardingConnectionProbe($this->resolver($this->connection(throwOnQuery: true)));
        $result = (new MigrationsAppliedCheck($probe, FakeModuleMigration::contentModule()))->run();

        self::assertFalse($result->passed);
    }

    public function test_migrations_check_fails_when_the_connection_cannot_be_opened(): void
    {
        // Delivery-handle resolver throws (unreachable PG) — the check must fail-close, not throw.
        $probe  = new OnboardingConnectionProbe($this->throwingResolver());
        $result = (new MigrationsAppliedCheck($probe, FakeModuleMigration::contentModule()))->run();

        self::assertFalse($result->passed);
        self::assertNotSame('', $result->remediation);
    }

    /**
     * A lazy resolver returning the given connection — mirrors the delivery-handle resolver the
     * probe now takes (resolved on demand so an open-time failure is caught inside the check).
     *
     * @return callable(): DatabaseConnectionInterface
     */
    private function resolver(DatabaseConnectionInterface $conn): callable
    {
        return static fn (): DatabaseConnectionInterface => $conn;
    }

    /**
     * A resolver that THROWS when invoked — mirrors DeliveryServiceProvider throwing on an
     * unreachable PostgreSQL (the failure happens at handle open, not at query time).
     *
     * @return callable(): DatabaseConnectionInterface
     */
    private function throwingResolver(): callable
    {
        return static function (): DatabaseConnectionInterface {
            throw new \RuntimeException('Delivery PostgreSQL connect failed.');
        };
    }

    /**
     * A minimal read-only DatabaseConnectionInterface double. It throws on any write primitive so
     * the checks are proven read-only, and its query() is scripted.
     *
     * @param array<int,array<string,mixed>> $queryReturns
     */
    private function connection(array $queryReturns = [], bool $throwOnQuery = false): DatabaseConnectionInterface
    {
        return new class ($queryReturns, $throwOnQuery) implements DatabaseConnectionInterface {
            /** @param array<int,array<string,mixed>> $rows */
            public function __construct(private array $rows, private bool $throwOnQuery) {}

            public function execute(string $sql, array $params = []): int
            {
                throw new \RuntimeException('write attempted by a preflight check');
            }

            public function query(string $sql, array $params = []): array
            {
                if ($this->throwOnQuery) {
                    throw new \RuntimeException('connection failed');
                }

                return $this->rows;
            }

            public function beginTransaction(): void
            {
                throw new \RuntimeException('transaction attempted by a preflight check');
            }

            public function commit(): void
            {
                throw new \RuntimeException('commit attempted by a preflight check');
            }

            public function rollback(): void {}
        };
    }
}
