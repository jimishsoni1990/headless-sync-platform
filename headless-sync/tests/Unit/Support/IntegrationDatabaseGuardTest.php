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
     * @return array{serverReachable:bool,database:string|null,sentinelPresent:bool,hspDataPresent:bool,adoptRequested:bool}
     */
    private static function facts(array $overrides = []): array
    {
        return [
            'serverReachable' => true,
            'database'        => 'hsp_test',
            'sentinelPresent' => true,
            'hspDataPresent'  => false,
            'adoptRequested'  => false,
            ...$overrides,
        ];
    }

    // -------------------------------------------------------------------------
    // The refusal path — the reason this class exists
    // -------------------------------------------------------------------------

    /**
     * THE INCIDENT CASE. Credentials that reach a real delivery database: data present, no
     * sentinel. Must ABORT before any DROP SCHEMA runs.
     */
    public function test_refuses_a_populated_database_without_a_sentinel(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'database'        => 'hsp',
            'sentinelPresent' => false,
            'hspDataPresent'  => true,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ABORT, $outcome);
        self::assertStringContainsString('cannot be distinguished from a real delivery database', $reason);
    }

    /**
     * NO IMPLICIT FIRST-TIME ADOPTION — the hardening this model turns on.
     *
     * An EMPTY, unmarked database must ABORT, not adopt. A freshly provisioned staging or
     * production database is empty too, so emptiness is not evidence of disposability; adopting
     * it would stamp a durable test sentinel into it permanently.
     */
    public function test_refuses_an_empty_unmarked_database_and_does_not_adopt_it(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => false,
            'hspDataPresent'  => false,
            'adoptRequested'  => false,
        ]));

        self::assertSame(
            IntegrationDatabaseGuard::ABORT,
            $outcome,
            'An empty database must NOT be adopted implicitly — it may be a new staging database.',
        );
        self::assertStringContainsString('not evidence of a', $reason);
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
            'database'        => 'hsp_test',
            'sentinelPresent' => false,
        ]));

        self::assertSame(
            IntegrationDatabaseGuard::ABORT,
            $outcome,
            'Being called hsp_test must not be sufficient authorization.',
        );
    }

    /**
     * Even an EXPLICIT adoption request is refused for a database holding delivery data: a
     * populated store is indistinguishable from production, and no environment variable may
     * sign that away.
     */
    public function test_explicit_adoption_is_refused_for_a_database_holding_delivery_data(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => false,
            'hspDataPresent'  => true,
            'adoptRequested'  => true,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ABORT, $outcome);
        self::assertStringContainsString('already holds HSP delivery', $reason);
    }

    /** Refusal is an ABORT, never a skip — a silently skipped check reads like a passing one. */
    public function test_refusal_is_never_expressed_as_allow_or_adopt(): void
    {
        $dangerous = [
            'live, populated, unmarked'  => self::facts([
                'database' => 'hsp', 'sentinelPresent' => false, 'hspDataPresent' => true,
            ]),
            'no target configured'       => self::facts(['database' => null]),
            'empty but unmarked'         => self::facts(['sentinelPresent' => false]),
            'adopt requested, populated' => self::facts([
                'sentinelPresent' => false, 'hspDataPresent' => true, 'adoptRequested' => true,
            ]),
        ];

        foreach ($dangerous as $label => $facts) {
            [$outcome] = IntegrationDatabaseGuard::decide($facts);
            self::assertNotSame(IntegrationDatabaseGuard::ALLOW, $outcome, $label);
            self::assertNotSame(IntegrationDatabaseGuard::ADOPT, $outcome, $label);
        }
    }

    // -------------------------------------------------------------------------
    // The authorized path
    // -------------------------------------------------------------------------

    public function test_allows_a_database_carrying_the_sentinel(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => true,
            'adoptRequested'  => false,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ALLOW, $outcome);
        self::assertStringContainsString('sentinel is present', $reason);
    }

    /**
     * A retained test database keeps its schemas and rows between runs, so an existing sentinel
     * authorizes it whatever its data state — the sentinel IS the proof of ownership, and
     * requiring emptiness here would break every second run.
     */
    public function test_an_existing_sentinel_authorizes_without_an_adopt_request(): void
    {
        [$outcome] = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => true,
            'hspDataPresent'  => true,
            'adoptRequested'  => false,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ALLOW, $outcome);
    }

    /**
     * The ONLY route to a sentinel: an explicit one-time request against a database with no
     * delivery data to lose. This is the CI lifecycle — fresh database, explicit adoption.
     */
    public function test_adopts_only_on_explicit_request_against_an_empty_database(): void
    {
        [$outcome, $reason] = IntegrationDatabaseGuard::decide(self::facts([
            'sentinelPresent' => false,
            'hspDataPresent'  => false,
            'adoptRequested'  => true,
        ]));

        self::assertSame(IntegrationDatabaseGuard::ADOPT, $outcome);
        self::assertStringContainsString('HSP_TEST_PGSQL_ADOPT=1', $reason);
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

    /**
     * Unreachable outranks every other fact — there is simply nothing to destroy.
     *
     * @param array<string,mixed> $overrides
     */
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
            'live database, populated' => [
                ['database' => 'hsp', 'sentinelPresent' => false, 'hspDataPresent' => true],
            ],
            'no target configured'     => [['database' => null]],
            'empty, unmarked'          => [['sentinelPresent' => false]],
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
     * new schema is dropped by the suite, the guard's data check must learn about it, or adoption
     * could clear a database that actually holds delivery state.
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
            'The integration suite drops a schema the guard does not search for data. Add it to '
            . 'IntegrationDatabaseGuard::HSP_SCHEMAS, or adoption could clear a populated database.',
        );
    }
}
