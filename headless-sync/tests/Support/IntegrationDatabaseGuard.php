<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

/**
 * Fail-closed safety boundary for the DESTRUCTIVE PostgreSQL integration suite.
 *
 * WHY THIS EXISTS
 * ---------------
 * The PostgreSQL integration tests drop the REAL production schema names — 82 occurrences of
 * `DROP SCHEMA IF EXISTS system|content|commerce CASCADE` across the suite. Unlike the MySQL
 * tests, which create and drop only their OWN `test_*`-prefixed tables and are therefore
 * structurally harmless whatever database they are pointed at, the PostgreSQL tests have NO
 * name-level isolation: the only thing separating a test run from a production wipe is WHICH
 * DATABASE the connection opened. Nothing verified that.
 *
 * Schema-level isolation is NOT available as a fix: HSP hard-qualifies every schema
 * (132 references such as `FROM content.posts`), never uses `search_path`, and exposes no
 * configurable schema seam. Running tests in a renamed namespace would require rewriting
 * production SQL and would make the tests materially different from production. So the boundary
 * has to be database IDENTITY, made fail-closed — which is what this class does.
 *
 * THE INCIDENT THIS PREVENTS IS NOT ONLY "WRONG CREDENTIALS"
 * ---------------------------------------------------------
 * Two integration tests hardcoded the LIVE development credentials as their fallbacks
 * (`user=hsp, password=hsp_secret, dbname=hsp`) and skipped ONLY when the connection failed.
 * With the development PostgreSQL running, `phpunit --testsuite Integration` with NO environment
 * variables at all connected to the real delivery database and dropped its schemas. No operator
 * error was required; the DEFAULT path was destructive. Hence rule 2 below: an unset
 * `HSP_TEST_PGSQL_DATABASE` is refused rather than allowed to fall back.
 *
 * THE BOUNDARY
 * ------------
 * A durable SENTINEL TABLE inside the target database marks it as an authorized test target.
 * The marker lives IN the database, so it cannot be supplied by mistyped credentials, an
 * environment variable, a database name convention, a note, or developer memory — a production
 * database simply does not have one. `hsp_test` is a signal, never the boundary.
 *
 * Adoption is ALWAYS explicit. There is no implicit first-time adoption, deliberately: a newly
 * provisioned staging or production database legitimately contains none of the HSP schemas YET,
 * so "empty" is not evidence of "disposable". Auto-adopting an empty database would write a
 * sentinel into it and permanently classify it as test-owned — the precise failure this guard
 * exists to prevent, merely deferred to the next provisioning.
 *
 * Adoption therefore requires a one-time `HSP_TEST_PGSQL_ADOPT=1`, and even then it REFUSES a
 * database that already holds HSP delivery DATA: a populated system/content/commerce set is
 * indistinguishable from a real delivery store, and no environment variable should be able to
 * sign that away. The variable is an ACTION that writes the durable sentinel, not a standing
 * permission — every later run is gated on the sentinel alone.
 *
 * Refusal ABORTS the run with a diagnostic. It never silently skips — a skipped safety check is
 * indistinguishable from a passing one.
 */
final class IntegrationDatabaseGuard
{
    public const SENTINEL_TABLE = 'hsp_integration_test_sentinel';

    /** The schemas searched for delivery data when deciding whether adoption is permissible. */
    private const HSP_SCHEMAS = ['system', 'content', 'commerce'];

    // Decision outcomes returned by the pure decision function.
    public const ALLOW  = 'allow';   // already authorized, or nothing destroyable
    public const ADOPT  = 'adopt';   // authorize now by writing the sentinel
    public const ABORT  = 'abort';   // refuse — the run must not start

    /**
     * The whole safety rule, as a PURE function over observed facts.
     *
     * Kept free of IO precisely so the REFUSAL path can be tested without pointing anything
     * destructive at a real database (see IntegrationDatabaseGuardTest).
     *
     * @param array{
     *     serverReachable: bool,
     *     database: string|null,
     *     sentinelPresent: bool,
     *     hspDataPresent: bool,
     *     adoptRequested: bool
     * } $facts
     * @return array{0:self::ALLOW|self::ADOPT|self::ABORT, 1:string} outcome + human-readable reason
     */
    public static function decide(array $facts): array
    {
        // (1) No reachable server means nothing is destroyable; the suite self-skips exactly as it
        //     always has. Preserves the long-standing "integration tests skip without a database"
        //     developer experience instead of turning it into a hard failure.
        if (! $facts['serverReachable']) {
            return [self::ALLOW, 'PostgreSQL is not reachable — the integration suite will self-skip.'];
        }

        // (2) No explicit target. This is the dangerous case, not the safe one: the per-test
        //     fallbacks include the live database and its credentials, so an unset variable used
        //     to mean "destroy the development database".
        if ($facts['database'] === null || $facts['database'] === '') {
            return [
                self::ABORT,
                'HSP_TEST_PGSQL_DATABASE is not set. The integration suite refuses to fall back to '
                . 'a default database, because the per-test fallbacks point at the live delivery '
                . 'database.',
            ];
        }

        // (3) Already authorized — the durable, in-database proof.
        if ($facts['sentinelPresent']) {
            return [self::ALLOW, 'Authorized: the integration-test sentinel is present.'];
        }

        // (4) Unmarked, and no explicit adoption requested. FAIL CLOSED — there is no implicit
        //     first-time adoption. An empty database is NOT evidence of a disposable one: a
        //     freshly provisioned staging or production database is empty too, and adopting it
        //     would stamp a test sentinel into it permanently.
        if (! $facts['adoptRequested']) {
            return [
                self::ABORT,
                'This database carries no integration-test sentinel, so it cannot be distinguished '
                . 'from a real delivery database — an empty database is not evidence of a '
                . 'disposable one. Refusing to run a suite that drops the system, content and '
                . 'commerce schemas.',
            ];
        }

        // (5) Adoption requested — but it is still refused for a database holding HSP delivery
        //     DATA. A populated system/content/commerce set is indistinguishable from a real
        //     delivery store, and an environment variable must not be able to sign that away.
        if ($facts['hspDataPresent']) {
            return [
                self::ABORT,
                'HSP_TEST_PGSQL_ADOPT=1 was given, but this database already holds HSP delivery '
                . 'data. Adoption is refused: a populated delivery store cannot be reclassified as '
                . 'a disposable test target. Drop the data deliberately first, or point at a '
                . 'different database.',
            ];
        }

        // (6) Explicit, one-time adoption of a database with no delivery data to lose.
        return [self::ADOPT, 'HSP_TEST_PGSQL_ADOPT=1 — adopting this empty database as a test target.'];
    }

    /**
     * Enforce the rule for real. Called once from tests/bootstrap.php.
     *
     * @throws \RuntimeException when the target cannot be positively established as a test target
     */
    public static function enforce(): void
    {
        if (! \extension_loaded('pgsql')) {
            return; // No driver: the destructive tests cannot run at all.
        }

        $host     = \getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port     = \getenv('HSP_TEST_PGSQL_PORT') ?: '5432';
        $user     = \getenv('HSP_TEST_PGSQL_USER') ?: '';
        $password = \getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $database = \getenv('HSP_TEST_PGSQL_DATABASE') ?: null;

        // Credentials resolve EXACTLY as a test's own pg_connect() resolves them — see dsn():
        // user/password are OMITTED when unset rather than sent empty, because libpq falls back
        // to the OS user for an absent `user` but treats `user=` as an explicit empty name.
        // Sending the empty form would report "unreachable" for a server a real test could still
        // reach, and destroy.
        $connection      = null;
        $serverReachable = false;
        $sentinelPresent = false;
        $hspDataPresent  = false;

        // (a) The TARGET is the authority on reachability: if the suite can open it, it can
        //     destroy it, whatever any other database says.
        if ($database !== null && $database !== '') {
            $opened = @\pg_connect(
                self::dsn($host, $port, $user, $password, $database),
                \PGSQL_CONNECT_FORCE_NEW,
            );

            if ($opened !== false) {
                $connection      = $opened;
                $serverReachable = true;
                $sentinelPresent = self::sentinelPresent($opened);
                $hspDataPresent  = self::hspDataPresent($opened);
            }
        }

        // (b) Target absent or unopenable. Fall back to probing the SERVER via the maintenance
        //     database, so "that database does not exist" is not mistaken for "server is down" —
        //     a live server still means some other test target is destroyable.
        //
        //     This is deliberately a FALLBACK rather than the primary probe: making the
        //     maintenance database the gatekeeper would silently disable the guard wherever
        //     `postgres` is unreachable but the real target is not.
        if (! $serverReachable) {
            $probe = @\pg_connect(
                self::dsn($host, $port, $user, $password, 'postgres'),
                \PGSQL_CONNECT_FORCE_NEW,
            );

            if ($probe !== false) {
                $serverReachable = true;
                @\pg_close($probe);
            }
        }

        [$outcome, $reason] = self::decide([
            'serverReachable' => $serverReachable,
            'database'        => $database,
            'sentinelPresent' => $sentinelPresent,
            'hspDataPresent'  => $hspDataPresent,
            'adoptRequested'  => \getenv('HSP_TEST_PGSQL_ADOPT') === '1',
        ]);

        if ($outcome === self::ABORT) {
            if ($connection !== false && $connection !== null) {
                @\pg_close($connection);
            }

            throw new \RuntimeException(self::diagnostic($host, $port, $user, $database, $reason));
        }

        if ($outcome === self::ADOPT && $connection !== null && $connection !== false) {
            self::writeSentinel($connection, $reason);
            \fwrite(
                \STDERR,
                "[integration-db-guard] ADOPTED {$database} at {$host}:{$port} as a test target — {$reason}\n",
            );
        }

        if ($connection !== false && $connection !== null) {
            @\pg_close($connection);
        }
    }

    /**
     * Build a libpq DSN, omitting `user`/`password` when unset so libpq applies its own defaults —
     * the same resolution a test's own pg_connect() performs. See the note in enforce().
     */
    private static function dsn(
        string $host,
        string $port,
        string $user,
        string $password,
        string $database
    ): string {
        $dsn = "host={$host} port={$port} dbname={$database} connect_timeout=3";

        if ($user !== '') {
            $dsn .= " user={$user}";
        }
        if ($password !== '') {
            $dsn .= " password={$password}";
        }

        return $dsn;
    }

    /** @param resource|\PgSql\Connection $connection */
    private static function sentinelPresent(mixed $connection): bool
    {
        $result = @\pg_query_params(
            $connection,
            'SELECT to_regclass($1) IS NOT NULL',
            ['public.' . self::SENTINEL_TABLE],
        );

        if ($result === false) {
            return false;
        }

        $row = \pg_fetch_row($result);

        return \is_array($row) && ($row[0] === 't' || $row[0] === true);
    }

    /**
     * Does this database hold HSP delivery DATA — not merely the schemas?
     *
     * Data, rather than schema presence, is the adoption precondition. A retained test database
     * keeps its schemas between runs (teardown drops and recreates them), so schema presence
     * alone would block re-adopting a legitimate, emptied test target. Rows in those schemas are
     * the thing that must never be reclassified as disposable.
     *
     * Fails CLOSED: any error answering the question is reported as "data present", because an
     * unanswerable question must not authorize a destructive run.
     *
     * @param resource|\PgSql\Connection $connection
     */
    private static function hspDataPresent(mixed $connection): bool
    {
        $tables = @\pg_query_params(
            $connection,
            'SELECT table_schema, table_name FROM information_schema.tables '
            . 'WHERE table_schema = ANY($1) AND table_type = \'BASE TABLE\'',
            ['{' . \implode(',', self::HSP_SCHEMAS) . '}'],
        );

        if ($tables === false) {
            return true; // Cannot prove it is empty — fail closed.
        }

        while ($row = \pg_fetch_assoc($tables)) {
            // Identifiers come from information_schema for the three constant schemas above, so
            // they are not attacker-controlled; quote them anyway.
            $qualified = '"' . \str_replace('"', '""', (string) $row['table_schema']) . '"."'
                . \str_replace('"', '""', (string) $row['table_name']) . '"';

            $probe = @\pg_query($connection, "SELECT 1 FROM {$qualified} LIMIT 1");

            if ($probe === false) {
                return true; // Unreadable table — fail closed.
            }

            if (\pg_num_rows($probe) > 0) {
                return true;
            }
        }

        return false;
    }

    /** @param resource|\PgSql\Connection $connection */
    private static function writeSentinel(mixed $connection, string $reason): void
    {
        @\pg_query($connection, 'CREATE TABLE IF NOT EXISTS public.' . self::SENTINEL_TABLE . ' ('
            . 'id INT PRIMARY KEY DEFAULT 1, '
            . 'adopted_at TIMESTAMPTZ NOT NULL DEFAULT now(), '
            . 'reason TEXT NOT NULL, '
            . 'CONSTRAINT single_row CHECK (id = 1))');

        @\pg_query_params(
            $connection,
            'INSERT INTO public.' . self::SENTINEL_TABLE . ' (id, reason) VALUES (1, $1) '
            . 'ON CONFLICT (id) DO NOTHING',
            [$reason],
        );
    }

    /** The refusal message. Names the target (never the password) and how to proceed. */
    private static function diagnostic(
        string $host,
        string $port,
        string $user,
        ?string $database,
        string $reason
    ): string {
        return <<<TEXT

        ===============================================================================
        INTEGRATION TEST DATABASE GUARD — REFUSED TO START
        ===============================================================================
        host     : {$host}:{$port}
        database : {$database}
        user     : {$user}
        purpose  : TEST (required)

        {$reason}

        The PostgreSQL integration suite runs
            DROP SCHEMA IF EXISTS system|content|commerce CASCADE
        against whatever database it is given. It will not do that to a database that has
        not positively identified itself as an integration-test target.

        To run the suite, point it at the dedicated test database:

            HSP_TEST_PGSQL_DATABASE=hsp_test

        A database is NEVER adopted implicitly — being empty is not evidence of being
        disposable, since a freshly provisioned staging or production database is empty
        too. If this database really is a disposable integration-test target, authorize
        it ONCE (this writes a durable sentinel table; later runs need only the sentinel):

            HSP_TEST_PGSQL_ADOPT=1

        Adoption is still refused for a database holding HSP delivery data.

        Never point this suite at a development site, staging or production database.
        ===============================================================================

        TEXT;
    }
}
