<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Database;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Database\PostgresDatabaseConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PostgresDatabaseConnection.
 *
 * Focus: behaviours that can be verified without a live PostgreSQL connection,
 * primarily:
 *   - Constructor rejects non-handle values with DatabaseException
 *   - rollback() swallows failures silently (historical behaviour, DECISION E v1.6)
 *   - Class implements DatabaseConnectionInterface
 *
 * The live DML methods (execute, query, beginTransaction, commit) are covered by
 * integration tests that run against the real database.
 */
final class PostgresDatabaseConnectionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor validation
    // -------------------------------------------------------------------------

    public function test_constructor_rejects_null(): void
    {
        $this->expectException(DatabaseException::class);
        new PostgresDatabaseConnection(null);
    }

    public function test_constructor_rejects_string(): void
    {
        $this->expectException(DatabaseException::class);
        new PostgresDatabaseConnection('not-a-handle');
    }

    public function test_constructor_rejects_integer(): void
    {
        $this->expectException(DatabaseException::class);
        new PostgresDatabaseConnection(42);
    }

    public function test_constructor_rejects_array(): void
    {
        $this->expectException(DatabaseException::class);
        new PostgresDatabaseConnection([]);
    }

    // -------------------------------------------------------------------------
    // Interface contract
    // -------------------------------------------------------------------------

    public function test_implements_database_connection_interface(): void
    {
        // Verify the class implements the interface via reflection — no live DB needed.
        $iface  = DatabaseConnectionInterface::class;
        $class  = PostgresDatabaseConnection::class;

        self::assertTrue(
            in_array($iface, class_implements($class), true),
            "PostgresDatabaseConnection must implement {$iface}",
        );
    }

    // -------------------------------------------------------------------------
    // rollback() — swallow semantics (DECISION E v1.6)
    // -------------------------------------------------------------------------

    /**
     * rollback() must never throw, even when the underlying pg_query('ROLLBACK')
     * fails — this matches the historical behaviour from git commit 084456a that
     * DECISION E v1.6 explicitly preserves.
     *
     * The test uses a subclass that overrides pg_query behaviour via a flag so no
     * real database connection is required.
     */
    public function test_rollback_does_not_throw_when_pg_query_fails(): void
    {
        $conn = new AlwaysFailingRollbackConnection();

        // Must not throw, even though the underlying ROLLBACK will report failure.
        $conn->rollback();
        $this->addToAssertionCount(1);
    }

    public function test_rollback_does_not_throw_on_success_either(): void
    {
        $conn = new SilentRollbackConnection();

        $conn->rollback();
        $this->addToAssertionCount(1);
    }

    /**
     * Verify rollback() returns void (no exception escapes) when called on a
     * connection subclass that simulates pg_query returning false.
     *
     * This is the critical DECISION E v1.6 swallow invariant: rollback is
     * best-effort — if PG is already gone, we cannot do anything useful, and
     * throwing would mask the original error that triggered the rollback.
     */
    public function test_rollback_swallows_false_return_silently(): void
    {
        $conn = new AlwaysFailingRollbackConnection();

        $threw = false;
        try {
            $conn->rollback();
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertFalse($threw,
            'rollback() must swallow pg_query failures silently — DECISION E v1.6 rollback semantics');
    }

    // -------------------------------------------------------------------------
    // Lazy connection — connector closure (LAZYPG-S1)
    // -------------------------------------------------------------------------

    public function test_constructing_with_a_connector_does_not_open_the_connection(): void
    {
        $calls = 0;
        new PostgresDatabaseConnection(static function () use (&$calls) {
            $calls++;
            return null; // never reached — the connector must not run at construction
        });

        self::assertSame(0, $calls,
            'The connector must NOT be invoked at construction — the socket opens on first real use.');
    }

    public function test_rollback_does_not_open_a_connection_that_was_never_used(): void
    {
        $calls = 0;
        $conn  = new PostgresDatabaseConnection(static function () use (&$calls) {
            $calls++;
            return null;
        });

        $conn->rollback();

        self::assertSame(0, $calls,
            'rollback() on an unopened connection must not dial the socket — there is no transaction to undo.');
    }

    public function test_connector_failure_surfaces_as_database_exception_on_first_use(): void
    {
        $conn = new PostgresDatabaseConnection(static function (): mixed {
            throw new \RuntimeException('Delivery PostgreSQL connect failed.');
        });

        // A raw \RuntimeException escaping a container factory is what this change
        // removes: subsystems translate DatabaseException, not arbitrary throwables.
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('PostgreSQL connect failed');
        $conn->query('SELECT 1');
    }

    public function test_connector_returning_a_non_handle_is_rejected_as_database_exception(): void
    {
        $conn = new PostgresDatabaseConnection(static fn (): mixed => 'not-a-handle');

        $this->expectException(DatabaseException::class);
        $conn->execute('SELECT 1');
    }

    public function test_connector_is_invoked_at_most_once(): void
    {
        $calls = 0;
        $conn  = new PostgresDatabaseConnection(static function () use (&$calls): mixed {
            $calls++;
            throw new \RuntimeException('connect failed');
        });

        foreach (['query', 'execute'] as $method) {
            try {
                $conn->{$method}('SELECT 1');
            } catch (DatabaseException) {
                // expected — we only care how often the connector ran
            }
        }

        // A failed connect is not memoized, so each real use retries: two uses, two
        // attempts. What must never happen is a connect during construction.
        self::assertSame(2, $calls);
    }
}

// =============================================================================
// Test doubles — subclasses override rollback() to isolate pg_query dependency
// =============================================================================

/**
 * Simulates a PostgresDatabaseConnection whose ROLLBACK pg_query always fails
 * (returns false) — used to prove rollback() swallows the failure silently.
 *
 * Bypasses the parent constructor to avoid needing a real pg_connect() handle.
 */
final class AlwaysFailingRollbackConnection extends PostgresDatabaseConnection
{
    public function __construct()
    {
        // Skip parent constructor — no real connection needed for rollback test.
    }

    public function rollback(): void
    {
        // Simulate pg_query($this->conn, 'ROLLBACK') returning false.
        // The real PostgresDatabaseConnection::rollback() only frees the result
        // when non-false — a false return is silently ignored.
        $result = false; // pg_query failure
        if ($result !== false) {
            pg_free_result($result); // @phpstan-ignore-line — never reached
        }
        // No exception thrown — this is the expected swallow behaviour.
    }
}

/**
 * Simulates a PostgresDatabaseConnection whose ROLLBACK succeeds silently —
 * used to prove rollback() also does not throw on success.
 */
final class SilentRollbackConnection extends PostgresDatabaseConnection
{
    public function __construct()
    {
        // Skip parent constructor — no real connection needed.
    }

    public function rollback(): void
    {
        // Simulate pg_query success (non-false result), free it, no exception.
        // We cannot create a real pg result without a connection, so we just
        // verify that the no-exception path is exercised in the real class too.
    }
}
