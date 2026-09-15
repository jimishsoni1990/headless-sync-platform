<?php

declare(strict_types=1);

namespace HSP\Core\Delivery;

/**
 * Validates an opaque delivery-API cursor token at the REST trust boundary (DECISION F).
 *
 * WHY THIS EXISTS (CCF-003). Every cursor-paginated `hsp/v1` listing mints its cursor the same
 * way: base64url over a small JSON object holding the endpoint's primary sort value plus the
 * deterministic UUID tiebreaker. The DECODERS in the query providers were tolerant — a payload
 * whose keys merely EXISTED was accepted — so a tampered token such as `{"s":"notadate","id":"x"}`
 * survived the registrar, reached `$n::timestamptz` in SQL and raised an uncaught DatabaseException:
 * an unauthenticated HTML 500 carrying a stack trace, filesystem paths and the SQL text. The token
 * is untrusted input and belongs to the WordPress entry point, so it is validated HERE, before any
 * provider is called and before any value can reach a PostgreSQL cast capable of throwing.
 *
 * A token that fails validation is a 400 `hsp_invalid_cursor` — never a silent fall back to page 1.
 * "Start from the beginning" is a different result from the one the consumer asked for, and
 * returning it quietly turns a tampered or stale token into duplicated rows.
 *
 * SHAPE IS PER-ENDPOINT, NOT GLOBAL. `s` means published_at on the temporal listings, `name` on the
 * name-sorted taxonomy listings, and variations sort on `o` (menu_order). The caller names its own
 * contract; nothing is inferred here.
 *
 * PURE PREDICATE, NOT A SERVICE. No state, no I/O, no container, no WordPress — the same kind of
 * thing as `sanitize_title()`. It is deliberately NOT injected: there is nothing to substitute and
 * nothing to configure. Contrast `DeliveryErrorBoundary`, which has a logger collaborator and real
 * behaviour, and IS constructor-injected (ADR-012 / Rule 7).
 *
 * ADR-038: transport-agnostic — plain strings in, bool out; no WP_REST_* types.
 * Rule 6: the cursor stays OPAQUE to consumers. This class describes the token HSP mints so it can
 * refuse everything else; it publishes nothing.
 */
final class CursorToken
{
    /** Primary sort is a TIMESTAMPTZ (posts, pages, media, products). */
    public const SORT_TEMPORAL = 'temporal';

    /** Primary sort is text (categories, tags, product categories, attributes — sorted by name). */
    public const SORT_TEXT = 'text';

    /** Primary sort is an integer (variations — menu_order). */
    public const SORT_INTEGER = 'integer';

    /** base64url alphabet, unpadded — exactly what the encoders emit. */
    private const BASE64URL = '/^[A-Za-z0-9_-]+$/';

    /** The UUID tiebreaker every projection uses (column-type canon: all ids are UUID). */
    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /**
     * PostgreSQL's TIMESTAMPTZ text form, which is what `pg_fetch_all()` hands back and therefore
     * exactly what the encoders base64 — e.g. `2026-06-21 22:53:58+00`. Pinned to that form rather
     * than to `DateTimeImmutable`'s parser, which accepts relative phrases ("next tuesday") that
     * PHP resolves happily and PostgreSQL then rejects — i.e. the 500 this guard exists to stop.
     */
    private const TIMESTAMPTZ = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d+)?[+-]\d{2}(:?\d{2})?$/';

    /**
     * True when $raw is a token this platform could have minted for a listing with the given sort.
     *
     * @param string $sortKind one of the SORT_* constants — the endpoint's primary sort type
     * @param string $sortKey  the payload key carrying that sort value ('s' by default, 'o' for
     *                         the menu_order-sorted variation listing)
     */
    public static function isValid(string $raw, string $sortKind, string $sortKey = 's'): bool
    {
        if ($raw === '' || preg_match(self::BASE64URL, $raw) !== 1) {
            return false;
        }

        $padded  = strtr($raw, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json    = base64_decode($padded, strict: true);

        if ($json === false) {
            return false;
        }

        $data = json_decode($json, associative: true);

        // A JSON scalar/list decodes fine but is not a cursor payload.
        if (! is_array($data) || ! array_key_exists($sortKey, $data) || ! array_key_exists('id', $data)) {
            return false;
        }

        if (! is_string($data['id']) || preg_match(self::UUID, $data['id']) !== 1) {
            return false;
        }

        return self::sortValueIsValid($data[$sortKey], $sortKind);
    }

    private static function sortValueIsValid(mixed $value, string $sortKind): bool
    {
        return match ($sortKind) {
            self::SORT_TEMPORAL => is_string($value) && preg_match(self::TIMESTAMPTZ, $value) === 1,
            // Any string orders against a text column; a non-string (object, array, bool, number)
            // is not something the encoder can have produced.
            self::SORT_TEXT     => is_string($value),
            self::SORT_INTEGER  => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            default             => false,
        };
    }
}
