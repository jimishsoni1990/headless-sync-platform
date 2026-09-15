<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Core;

use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Rest\DeliveryErrorBoundary;
use PHPUnit\Framework\TestCase;

/**
 * Information-disclosure proof for the contained 500 (CCF-003 §8/§9), against live PostgreSQL.
 *
 * WHY THIS NEEDS A REAL DATABASE AND A REAL FAILURE. A unit test with a hand-thrown exception
 * proves the boundary returns the right envelope; it cannot prove the response contains ONLY that
 * envelope. The measured live defect had two separate emissions:
 *
 *     pg_query_params() fails
 *       → PHP WARNING printed to the output stream   ← bytes already sent
 *       → DatabaseException thrown                    ← catchable
 *       → PHP fatal handler renders an HTML page      ← bytes already sent
 *
 * A try/catch around the callback removes the third. It cannot remove the first: those bytes left
 * before any PHP code of ours ran. So the boundary alone was not enough, and this test is what
 * establishes that the remaining containment (the @-suppression at the PostgresDatabaseConnection
 * boundary) actually holds.
 *
 * THE ENVIRONMENT IS THE POINT. It is deliberately configured the way the disclosure was
 * reproduced — WP_DEBUG off but `display_errors=On`, which is Local's default PHP configuration and
 * a very common production misconfiguration. A test run with display_errors off would pass without
 * proving anything.
 *
 * SCOPE, STATED HONESTLY. This covers a Throwable raised inside a delivery callback. PHP engine
 * fatals (OOM, E_ERROR), failures before WordPress dispatches to the callback, and web-server or
 * proxy errors are outside the HSP application boundary and outside this contract.
 *
 * Environment variables (test self-skips if the DB is absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class DeliveryErrorDisclosureTest extends TestCase
{
    /** Fragments that must never appear in a public error response. */
    private const FORBIDDEN = [
        'Stack trace',
        'DatabaseException',
        'pg_query',
        'pg_query_params',
        'SELECT',
        'invalid input syntax',
        'C:\\',
        '/var/',
        'wp-content/plugins',
        'PostgresDatabaseConnection.php',
        'password',
        'dbname=',
        'Warning',
        'Fatal error',
        '<br',
        '<html',
    ];

    private mixed $conn = null;

    private string|false $priorDisplayErrors = false;

    protected function setUp(): void
    {
        $dsn = $this->buildDsn();

        $conn = @\pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        if ($conn === false) {
            self::markTestSkipped('PostgreSQL not available — skipping delivery disclosure test.');
        }

        $this->conn = $conn;

        // Reproduce the disclosure-prone configuration exactly.
        $this->priorDisplayErrors = ini_get('display_errors');
        ini_set('display_errors', '1');
        error_reporting(E_ALL);
    }

    protected function tearDown(): void
    {
        if ($this->priorDisplayErrors !== false) {
            ini_set('display_errors', $this->priorDisplayErrors);
        }

        if ($this->conn !== null) {
            pg_close($this->conn);
            $this->conn = null;
        }
    }

    // =========================================================================
    // (1) The database layer emits no bytes of its own
    // =========================================================================

    /**
     * A genuinely failing query — the live `?min_price=abc` shape: a non-numeric value bound to a
     * NUMERIC cast. The DatabaseException must still be raised (error semantics unchanged) and
     * NOTHING may be written to the output stream on the way.
     */
    public function test_a_failing_query_writes_nothing_to_the_output_stream(): void
    {
        $db = new PostgresDatabaseConnection($this->conn);

        ob_start();
        $thrown = null;

        try {
            $db->query('SELECT $1::numeric AS price', ['abc']);
        } catch (DatabaseException $e) {
            $thrown = $e;
        }

        $emitted = (string) ob_get_clean();

        self::assertInstanceOf(
            DatabaseException::class,
            $thrown,
            'The failure must still surface as DatabaseException — only the duplicate PHP warning '
            . 'is suppressed, never the error itself.',
        );
        self::assertSame(
            '',
            $emitted,
            'A failing query must emit no bytes. Emitted: ' . var_export($emitted, true),
        );

        // The diagnostic is not lost — it is carried by the exception, for the log.
        self::assertStringContainsString('invalid input syntax', $thrown->getMessage());
    }

    /** The same for execute(), which shares the failure path. */
    public function test_a_failing_execute_writes_nothing_to_the_output_stream(): void
    {
        $db = new PostgresDatabaseConnection($this->conn);

        ob_start();
        $thrown = null;

        try {
            $db->execute('UPDATE hsp_no_such_table_ccf003 SET x = $1', ['1']);
        } catch (DatabaseException $e) {
            $thrown = $e;
        }

        $emitted = (string) ob_get_clean();

        self::assertInstanceOf(DatabaseException::class, $thrown);
        self::assertSame('', $emitted, 'Emitted: ' . var_export($emitted, true));
    }

    // =========================================================================
    // (2) End to end: a failing delivery callback produces ONLY the envelope
    // =========================================================================

    /**
     * The whole chain, in the disclosure-prone configuration: a callback whose query fails against
     * live PostgreSQL produces the documented 500 envelope, and not one byte besides.
     */
    public function test_a_failing_delivery_callback_produces_only_the_public_envelope(): void
    {
        $db       = new PostgresDatabaseConnection($this->conn);
        $logLines = [];

        $boundary = new DeliveryErrorBoundary(
            new StructuredLogger(function (string $line) use (&$logLines): void {
                $logLines[] = $line;
            }),
        );

        ob_start();
        $result  = $boundary->execute(static fn (): array => $db->query('SELECT $1::numeric', ['abc']));
        $emitted = (string) ob_get_clean();

        self::assertSame(
            '',
            $emitted,
            'No warning prefix, no HTML suffix, nothing outside the JSON body. Emitted: '
            . var_export($emitted, true),
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_internal_error', $result->code);
        self::assertSame(500, $result->data['status']);

        // The body a consumer would receive, exactly as WordPress serialises it.
        $body = (string) json_encode([
            'code'    => $result->code,
            'message' => $result->message,
            'data'    => $result->data,
        ]);

        foreach (self::FORBIDDEN as $fragment) {
            self::assertStringNotContainsString(
                $fragment,
                $body,
                "The public 500 body must not disclose '{$fragment}'. Body: {$body}",
            );
        }

        // And the real cause did reach the operator, through the existing channel.
        self::assertCount(1, $logLines);
        self::assertStringContainsString('delivery.callback_failed', $logLines[0]);
        self::assertStringContainsString('invalid input syntax', $logLines[0]);
    }

    /** A connect failure is contained the same way — no DSN, no credentials in the response. */
    public function test_a_connect_failure_discloses_no_credentials(): void
    {
        $boundary = new DeliveryErrorBoundary(
            new StructuredLogger(static function (string $line): void {
            }),
        );

        $unreachable = new PostgresDatabaseConnection(static function (): mixed {
            throw new \RuntimeException(
                'pg_connect(): Unable to connect — host=127.0.0.1 port=1 dbname=hsp '
                . 'user=hsp password=hsp_secret'
            );
        });

        ob_start();
        $result  = $boundary->execute(static fn (): array => $unreachable->query('SELECT 1'));
        $emitted = (string) ob_get_clean();

        self::assertSame('', $emitted, 'Emitted: ' . var_export($emitted, true));
        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_internal_error', $result->code);

        $body = (string) json_encode([
            'code'    => $result->code,
            'message' => $result->message,
            'data'    => $result->data,
        ]);

        foreach (['password', 'hsp_secret', 'dbname=', 'pg_connect', '127.0.0.1'] as $fragment) {
            self::assertStringNotContainsString($fragment, $body);
        }
    }

    // =========================================================================

    private function buildDsn(): string
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: false;
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: false;

        if ($user === false || $db === false) {
            self::markTestSkipped('PostgreSQL env vars not set — skipping delivery disclosure test.');
        }

        return "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
    }
}
