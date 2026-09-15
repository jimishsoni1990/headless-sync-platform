<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Rest;

use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Rest\DeliveryErrorBoundary;
use PHPUnit\Framework\TestCase;

/**
 * The Core delivery error boundary (CCF-003).
 *
 * What it must do: pass successes through untouched, and turn any Throwable into the ONE public
 * error envelope with a generic message — while the Throwable's own details go to the platform's
 * existing structured-log channel and nowhere near the response.
 *
 * The leakage assertions are the point of the file. Before this class existed, a Throwable escaping
 * a `hsp/v1` callback was rendered by PHP's fatal handler: `Content-Type: text/html`, a stack trace,
 * absolute filesystem paths, the plugin path, the exception class and the SQL text — on an
 * unauthenticated GET.
 */
final class DeliveryErrorBoundaryTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    private function boundary(): DeliveryErrorBoundary
    {
        $this->logLines = [];

        return new DeliveryErrorBoundary(
            new StructuredLogger(function (string $line): void {
                $this->logLines[] = $line;
            }),
        );
    }

    // -------------------------------------------------------------------------
    // Pass-through
    // -------------------------------------------------------------------------

    public function test_a_successful_callback_result_is_returned_unchanged(): void
    {
        $payload = new \WP_REST_Response(['data' => [], 'next_cursor' => null], 200);

        self::assertSame($payload, $this->boundary()->execute(static fn () => $payload));
    }

    /**
     * A handler that returns a WP_Error is expressing an APPLICATION error (a 404, a 400). The
     * boundary must not touch it — it is already the documented envelope, and converting it to a
     * 500 would turn every missing post into a server fault.
     */
    public function test_a_returned_wp_error_is_not_converted(): void
    {
        $notFound = new \WP_Error('hsp_not_found', 'Post not found.', ['status' => 404]);

        $result = $this->boundary()->execute(static fn () => $notFound);

        self::assertSame($notFound, $result);
        self::assertSame([], $this->logLines, 'An application error is not an internal failure.');
    }

    public function test_guard_wraps_a_handler_and_forwards_the_request(): void
    {
        $seen     = null;
        $guarded  = $this->boundary()->guard(static function (mixed $request) use (&$seen): string {
            $seen = $request;

            return 'ok';
        });

        self::assertSame('ok', $guarded('the-request'));
        self::assertSame('the-request', $seen);
    }

    // -------------------------------------------------------------------------
    // Containment
    // -------------------------------------------------------------------------

    public function test_a_thrown_exception_becomes_the_public_500_envelope(): void
    {
        $result = $this->boundary()->execute(static function (): never {
            throw new \RuntimeException('PostgreSQL query failed: relation "commerce.products" does not exist');
        });

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_internal_error', $result->code);
        self::assertSame(500, $result->data['status']);
        self::assertIsString($result->message);
        self::assertNotSame('', $result->message);
    }

    /** An Error (not just an Exception) is a Throwable too — a TypeError must not escape either. */
    public function test_a_php_error_is_contained_as_well(): void
    {
        $result = $this->boundary()->execute(static function (): never {
            throw new \TypeError('Argument #1 must be of type string, null given');
        });

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_internal_error', $result->code);
        self::assertSame(500, $result->data['status']);
    }

    /**
     * The disclosure test. The thrown message is built from every category §19 forbids; none of it
     * may appear in the public response.
     */
    public function test_the_public_message_discloses_nothing_from_the_throwable(): void
    {
        $secret = 'PostgreSQL query failed: SELECT * FROM commerce.products WHERE price > $1 '
            . '— host=127.0.0.1 port=5432 dbname=hsp user=hsp password=hsp_secret '
            . 'in C:\\Users\\jimis\\wp-content\\plugins\\headless-sync\\core\\Database\\'
            . 'PostgresDatabaseConnection.php:121';

        $result = $this->boundary()->execute(static function () use ($secret): never {
            throw new \RuntimeException($secret);
        });

        self::assertInstanceOf(\WP_Error::class, $result);

        $serialized = (string) json_encode([
            'code'    => $result->code,
            'message' => $result->message,
            'data'    => $result->data,
        ]);

        foreach ([
            'Stack trace',
            'RuntimeException',
            'SELECT',
            'pg_query',
            'commerce.products',
            'password',
            'hsp_secret',
            'dbname=',
            'C:\\',
            '/var/',
            'wp-content/plugins',
            'PostgresDatabaseConnection.php',
            '.php',
            '121',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $serialized,
                "The public 500 body must not disclose '{$forbidden}'.",
            );
        }
    }

    /** The internals are not discarded — they go to the EXISTING observability channel. */
    public function test_the_throwable_is_logged_through_the_existing_structured_logger(): void
    {
        $this->boundary()->execute(static function (): never {
            throw new \RuntimeException('the real cause');
        });

        self::assertCount(1, $this->logLines);

        $entry = json_decode($this->logLines[0], associative: true);

        self::assertIsArray($entry);
        self::assertSame('delivery.callback_failed', $entry['event']);
        self::assertSame(\RuntimeException::class, $entry['exception']);
        self::assertSame('the real cause', $entry['message']);
        self::assertArrayHasKey('file', $entry);
        self::assertArrayHasKey('line', $entry);
    }

    /** Two different failures must not produce two different public messages. */
    public function test_the_public_message_is_the_same_for_every_failure(): void
    {
        $first = $this->boundary()->execute(static function (): never {
            throw new \RuntimeException('cause A');
        });
        $second = $this->boundary()->execute(static function (): never {
            throw new \LogicException('cause B');
        });

        self::assertInstanceOf(\WP_Error::class, $first);
        self::assertInstanceOf(\WP_Error::class, $second);
        self::assertSame($first->message, $second->message);
    }
}
