<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Support;

use HSP\Tests\Support\IntegrationDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The integration-database safety boundary.
 *
 * Every case here drives the PURE decision function with STUBBED connection facts — no database
 * is opened and nothing destructive runs. That is deliberate: the refusal path must be provable
 * without pointing a schema-dropping suite at anything real, which is the exact mistake the guard
 * exists to prevent.
 */
final class IntegrationDatabaseGuardTest extends TestCase
{
    /**
     * @param array<string,mixed> $overrides
     * @return array{serverReachable:bool,database:string|null,sentinelPresent:bool,hspSchemasPresent:bool,adoptRequested:bool}
     */
    private static function facts(array $overrides = []): array
    {
        return [
            'serverReachable'   => true,
            'database'          => 'hsp_test',
            'sentinelPresent'   => true,
            'hspSchemasPresent' => true,
            'adoptRequested'    => false,
            ...$overrides,
        ];
    }

    // -------------------------------------------------------------------------
    // The refusal path — the reason this class exists
    // -------------------------------------------------------------------------

    /**
     * THE INCIDENT CASE. Credentials that reach a real delivery database: HSP schemas present,
     * no sentinel. Must ABORT before any DROP SCHEMA runs.
     */
    public function test_refuses_a_database_holding_hsp_schemas_without_a_sentinel(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'database'          => 'hsp',
            'sentinelPresent'   => false,
            'hspSchemasPresent' => true,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ABORT, $outcome);
        self::assertStringContainsString('cannot be distinguished from a real delivery database', $reason);
    }

    /**
     * The no-environment case, which was destructive by DEFAULT: two tests hardcoded the live
     * credentials and only skipped when the connection FAILED, so a running dev PostgreSQL meant
     * a bare `phpunit --testsuite Integration` dropped the real schemas. An unset target must now
     * refuse rather than fall back.
     */
    public function test_refuses_when_no_target_database_is_configured(): void
    {
        foreach ([null, ''] as $unset) {
            [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
                'database' => $unset,
            ]));

            self::assertSame(IntegrationDatabaseGuard::ABORT, $outcome, var_export($unset, true));
            self::assertStringContainsString('refuses to fall back', $reason);
        }
    }

    /** A name is a signal, never the boundary: the right name without a sentinel still refuses. */
    public function test_the_database_name_alone_does_not_authorize(): void
    {
        [$outcome] = IntegrationDatabaseGuard::decide(self::facts([
            'database'          => 'hsp_test',
            'sentinelPresent'   => false,
            'hspSchemasPresent' => true,
        ]));

        self::assertSame(
            IntegrationDatabaseGuard::ABORT,
            $outcome,
            'Being called hsp_test must not be sufficient authorization.',
        );
    }

    /** Refusal is an ABORT, never a skip — a silently skipped check reads like a passing one. */
    public function test_refusal_is_never_expressed_as_allow(): void
    {
        $dangerous = [
            self::facts(['database' => 'hsp', 'sentinelPresent' => false]),
            self::facts(['database' => null]),
            self::facts(['database' => 'production', 'sentinelPresent' => false]),
        ];

        foreach ($dangerous as $i => $facts) {
            [$outcome] = IntegrationDatabaseGuard::decide($facts);
            self::assertNotSame(IntegrationDatabaseGuard::ALLOW, $outcome, "dangerous case {$i}");
            self::assertNotSame(IntegrationDatabaseGuard::ADOPT, $outcome, "dangerous case {$i}");
        }
    }

    // -------------------------------------------------------------------------
    // The authorized path
    // -------------------------------------------------------------------------

    public function test_allows_a_database_carrying_the_sentinel(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => true,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ALLOW, $outcome);
        self::assertStringContainsString('sentinel is present', $reason);
    }

    /**
     * CI path: a freshly provisioned service container has no HSP schemas, so there is no HSP
     * data to lose and it is adopted automatically. This is what keeps CI zero-configuration.
     */
    public function test_adopts_a_virgin_database_automatically(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent'   => false,
            'hspSchemasPresent' => false,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ADOPT, $outcome);
        self::assertStringContainsString('no HSP schemas', $reason);
    }

    /**
     * A real test database that already ran the suite has schemas but no sentinel. It is adopted
     * only on a deliberate one-time request — and adoption writes a DURABLE marker, so the env
     * var is an action rather than a standing permission.
     */
    public function test_adopts_a_populated_database_only_on_explicit_request(): void
    {
        $withoutRequest = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => false,
            'adoptRequested'  => false,
        ]));
        $withRequest = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => false,
            'adoptRequested'  => true,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ABORT, $withoutRequest[0]);
        self::assertSame(IntegrationDatabaseGuard::ADOPT, $withRequest[0]);
    }

    /**
     * No reachable server means nothing is destroyable, so the suite keeps its long-standing
     * self-skip behaviour instead of becoming a hard failure for every developer without a
     * database. Safety is not weakened: destruction requires a reachable server.
     */
    public function test_allows_when_no_server_is_reachable_so_the_suite_self_skips(): void
    {
        [$outcome] = IntegrationDatabaseGuard::decide(self::facts([
            'serverReachable' => false,
            'database'        => null,
            'sentinelPresent' => false,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ALLOW, $outcome);
    }

    /** Unreachable outranks every other fact — there is simply nothing to destroy. */
    #[DataProvider('dangerousFactCombinations')]
    public function test_unreachable_server_is_always_allowed(array $overrides): void
    {
        [$outcome] = IntegrationDatabaseGuard::decide(
            self::facts([...$overrides, 'serverReachable' => false]),
        );

        self::assertSame(IntegrationDatabaseGuard::ALLOW, $outcome);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function dangerousFactCombinations(): array
    {
        return [
            'live database, no sentinel' => [['database' => 'hsp', 'sentinelPresent' => false]],
            'no target configured'       => [['database' => null]],
            'populated, unmarked'        => [['sentinelPresent' => false, 'hspSchemasPresent' => true]],
        ];
    }

    // -------------------------------------------------------------------------
    // Structural facts the guard depends on
    // -------------------------------------------------------------------------

    /**
     * The sentinel must live in the target database, not in configuration: that is what makes it
     * unreachable by a mistyped credential, an env var, a name convention or a README.
     */
    public function test_the_sentinel_is_an_in_database_object(): void
    {
        self::assertSame('hsp_integration_test_sentinel', IntegrationDatabaseGuard::SENTINEL_TABLE);
    }

    /**
     * PostgreSQL integration tests still drop the REAL schema names — that is deliberate, because
     * HSP hard-qualifies every schema and renaming them would make the tests materially different
     * from production. This asserts the destructive surface is what the guard assumes it is; if a
     * new schema is dropped by the suite, the guard's virgin-database check must learn about it.
     */
    public function test_the_destructive_surface_is_confined_to_the_known_hsp_schemas(): void
    {
        $dropped = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/Integration'),
        );

        foreach ($files as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            \preg_match_all(
                '/DROP SCHEMA IF EXISTS ([a-z_]+)/i',
                (string) \file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches[1] as $schema) {
                $dropped[$schema] = true;
            }
        }

        \ksort($dropped);

        self::assertSame(
            ['commerce', 'content', 'system'],
            \array_keys($dropped),
            'The integration suite drops a schema the guard does not treat as HSP data. Add it to '
            . 'IntegrationDatabaseGuard::HSP_SCHEMAS, or the virgin-database check will adopt a '
            . 'database that actually holds data.',
        );
    }
}
