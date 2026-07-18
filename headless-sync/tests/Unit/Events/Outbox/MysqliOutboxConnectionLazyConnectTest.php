<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events\Outbox;

use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;
use PHPUnit\Framework\TestCase;

/**
 * Lazy-connect + failure-translation contract for MysqliOutboxConnection (HOTFIX).
 *
 * MysqliOutboxConnection now receives a `\Closure(): \mysqli` connector and must:
 *   - NOT invoke it at construction (so container/cron wiring opens no socket);
 *   - invoke it on FIRST real use, then memoize (connector runs at most once);
 *   - translate any connect failure (connector throwing, or a handle carrying
 *     connect_errno) to OutboxWriteException at this boundary — never a raw
 *     \RuntimeException or mysqli exception (DECISION E v1.6 error semantics).
 */
final class MysqliOutboxConnectionLazyConnectTest extends TestCase
{
    public function test_constructing_does_not_invoke_the_connector(): void
    {
        $calls = 0;
        new MysqliOutboxConnection(function () use (&$calls): \mysqli {
            $calls++;
            return mysqli_init();
        });

        self::assertSame(0, $calls, 'no socket may be opened at construction time');
    }

    public function test_connector_is_invoked_once_and_memoized_on_use(): void
    {
        // Memoization only applies to a SUCCESSFUL connect, so this needs a live handle.
        // Uses the integration MySQL env vars; skips cleanly when they are unset.
        $mysqli = self::liveMysqliOrSkip();

        $calls = 0;
        $conn  = new MysqliOutboxConnection(function () use (&$calls, $mysqli): \mysqli {
            $calls++;
            return $mysqli;
        });

        // Two real uses; the connector must run exactly once (handle memoized).
        $conn->beginTransaction();
        $conn->rollback();
        $conn->beginTransaction();
        $conn->rollback();

        self::assertSame(1, $calls, 'connector runs at most once (handle memoized)');
    }

    public function test_connector_throwing_surfaces_as_outbox_write_exception_on_first_use(): void
    {
        $conn = new MysqliOutboxConnection(function (): \mysqli {
            throw new \RuntimeException('boom: connection refused');
        });

        $this->expectException(OutboxWriteException::class);
        $this->expectExceptionMessageMatches('/Outbox MySQL connect failed/');

        // Failure must appear only at first real use, translated at this boundary.
        $conn->beginTransaction();
    }

    public function test_connect_errno_on_returned_handle_surfaces_as_outbox_write_exception(): void
    {
        // Report OFF so a failed connect returns a handle carrying connect_errno instead of
        // throwing — this exercises the connect_errno branch of connection() specifically.
        $default = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;
        mysqli_report(MYSQLI_REPORT_OFF);

        try {
            $conn = new MysqliOutboxConnection(function (): \mysqli {
                return @new \mysqli('127.0.0.1', 'nobody', 'nobody', 'nodb', 1); // port 1: refused
            });

            $this->expectException(OutboxWriteException::class);
            $this->expectExceptionMessageMatches('/Outbox MySQL connect failed/');

            $conn->query('SELECT 1');
        } finally {
            mysqli_report($default);
        }
    }

    /**
     * Return a live \mysqli against the integration MySQL, or skip the test.
     * Mirrors the RelayEndToEndTest env-var convention.
     */
    private static function liveMysqliOrSkip(): \mysqli
    {
        $user = getenv('HSP_TEST_MYSQL_USER');
        $db   = getenv('HSP_TEST_MYSQL_DATABASE');
        if ($user === false || $user === '' || $db === false || $db === '') {
            self::markTestSkipped('MySQL env vars not set (HSP_TEST_MYSQL_USER, HSP_TEST_MYSQL_DATABASE).');
        }

        $host = getenv('HSP_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_MYSQL_PORT') ?: 3306);
        $pass = getenv('HSP_TEST_MYSQL_PASSWORD') ?: '';

        $default = MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT;
        mysqli_report(MYSQLI_REPORT_OFF);
        try {
            $mysqli = @new \mysqli($host, $user, $pass, $db, $port);
            if ($mysqli->connect_errno) {
                self::markTestSkipped('MySQL not reachable: ' . $mysqli->connect_error);
            }
            return $mysqli;
        } finally {
            mysqli_report($default);
        }
    }
}
