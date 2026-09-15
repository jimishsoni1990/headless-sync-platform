<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Delivery;

use HSP\Core\Delivery\CursorToken;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The cursor token validator that stands between an untrusted `?cursor=` and a PostgreSQL cast
 * (CCF-003).
 *
 * The defect this pins: the old check asked only whether the decoded payload HAD the keys `s` and
 * `id`. `{"s":"notadate","id":"x"}` satisfied that, reached `$n::timestamptz`, and produced an
 * unauthenticated HTML 500 carrying a stack trace, the SQL and absolute filesystem paths. Every
 * "wrong shape" case below is therefore a security case, not a tidiness case.
 *
 * Manufacturing invalid tokens here does not make the cursor format public: the consumer contract
 * is still an opaque string, and these tests exist to prove the platform refuses everything it did
 * not mint.
 */
final class CursorTokenTest extends TestCase
{
    /** A real token, exactly as the encoders emit it. */
    private static function encode(array $payload): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($payload)), '+/', '-_'), '=');
    }

    private const UUID = '01a09ec2-5241-7f5f-9c54-bf145f4e8bc7';

    // -------------------------------------------------------------------------
    // Accepted — the tokens this platform actually mints
    // -------------------------------------------------------------------------

    public function test_a_temporal_cursor_of_the_minted_shape_is_valid(): void
    {
        $token = self::encode(['s' => '2026-06-21 22:53:58+00', 'id' => self::UUID]);

        self::assertTrue(CursorToken::isValid($token, CursorToken::SORT_TEMPORAL));
    }

    /** PostgreSQL emits fractional seconds only when non-zero; both forms are real. */
    public function test_a_temporal_cursor_with_fractional_seconds_is_valid(): void
    {
        $token = self::encode(['s' => '2026-06-21 22:53:58.123456+00', 'id' => self::UUID]);

        self::assertTrue(CursorToken::isValid($token, CursorToken::SORT_TEMPORAL));
    }

    public function test_a_text_cursor_accepts_any_string_sort_value(): void
    {
        // Taxonomy listings sort by name. Names are arbitrary text — including text that looks
        // nothing like a date, which is why the temporal rule must not be applied here.
        $token = self::encode(['s' => 'Crypto', 'id' => self::UUID]);

        self::assertTrue(CursorToken::isValid($token, CursorToken::SORT_TEXT));
    }

    public function test_an_integer_cursor_accepts_the_menu_order_key(): void
    {
        $token = self::encode(['o' => 3, 'id' => self::UUID]);

        self::assertTrue(CursorToken::isValid($token, CursorToken::SORT_INTEGER, 'o'));
    }

    public function test_an_integer_cursor_accepts_a_numeric_string(): void
    {
        $token = self::encode(['o' => '3', 'id' => self::UUID]);

        self::assertTrue(CursorToken::isValid($token, CursorToken::SORT_INTEGER, 'o'));
    }

    // -------------------------------------------------------------------------
    // Rejected — every distinct way a token can be wrong
    // -------------------------------------------------------------------------

    /**
     * @return array<string,array{0:string,1:string,2:string}>
     */
    public static function invalidTokens(): array
    {
        $uuid = self::UUID;

        return [
            'empty'                     => ['', CursorToken::SORT_TEMPORAL, 's'],
            'not base64url at all'      => ['zzz@@!!', CursorToken::SORT_TEMPORAL, 's'],
            'base64url but not base64'  => ['A', CursorToken::SORT_TEMPORAL, 's'],
            'valid base64, not JSON'    => [
                rtrim(strtr(base64_encode('not json at all'), '+/', '-_'), '='),
                CursorToken::SORT_TEMPORAL,
                's',
            ],
            'JSON scalar, not an object' => [
                rtrim(strtr(base64_encode('"a string"'), '+/', '-_'), '='),
                CursorToken::SORT_TEMPORAL,
                's',
            ],
            'missing s'                 => [self::encode(['id' => $uuid]), CursorToken::SORT_TEMPORAL, 's'],
            'missing id'                => [
                self::encode(['s' => '2026-06-21 22:53:58+00']),
                CursorToken::SORT_TEMPORAL,
                's',
            ],
            // The exact payload that reached ::timestamptz and produced the HTML 500.
            'wrong s semantic'          => [self::encode(['s' => 'notadate', 'id' => $uuid]), CursorToken::SORT_TEMPORAL, 's'],
            // PHP's date parser accepts this; PostgreSQL does not — so the parser must not be
            // the rule.
            'relative date phrase'      => [self::encode(['s' => 'next tuesday', 'id' => $uuid]), CursorToken::SORT_TEMPORAL, 's'],
            'ISO T separator'           => [self::encode(['s' => '2026-06-21T22:53:58+00', 'id' => $uuid]), CursorToken::SORT_TEMPORAL, 's'],
            'wrong s type (int)'        => [self::encode(['s' => 17, 'id' => $uuid]), CursorToken::SORT_TEMPORAL, 's'],
            'wrong s type on text sort' => [self::encode(['s' => ['a'], 'id' => $uuid]), CursorToken::SORT_TEXT, 's'],
            'wrong id type'             => [self::encode(['s' => 'Crypto', 'id' => 42]), CursorToken::SORT_TEXT, 's'],
            'id is not a UUID'          => [self::encode(['s' => 'Crypto', 'id' => 'uuid-xyz']), CursorToken::SORT_TEXT, 's'],
            'SQL payload in s'          => [
                self::encode(['s' => "2024-01-01'; DROP TABLE content.pages; --", 'id' => $uuid]),
                CursorToken::SORT_TEMPORAL,
                's',
            ],
            // A products cursor replayed against the variations listing: the key it needs is absent.
            'cursor for another resource' => [
                self::encode(['s' => '2026-06-21 22:53:58+00', 'id' => $uuid]),
                CursorToken::SORT_INTEGER,
                'o',
            ],
            'non-numeric o'             => [self::encode(['o' => 'abc', 'id' => $uuid]), CursorToken::SORT_INTEGER, 'o'],
        ];
    }

    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_are_refused(string $token, string $sortKind, string $sortKey): void
    {
        self::assertFalse(CursorToken::isValid($token, $sortKind, $sortKey));
    }

    /** An unknown sort kind fails closed rather than waving the token through. */
    public function test_an_unknown_sort_kind_is_refused(): void
    {
        $token = self::encode(['s' => 'Crypto', 'id' => self::UUID]);

        self::assertFalse(CursorToken::isValid($token, 'something-else'));
    }
}
