<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Extractors;

/**
 * Drops WordPress-internal ("protected") meta before it enters the pipeline.
 *
 * The delivery API is public and unauthenticated (Rule 6 — consumers depend on the API contract
 * only), so whatever reaches a canonical model is world-readable. Passing `get_post_meta()` through
 * verbatim published WordPress's own bookkeeping alongside the content: `_edit_lock`
 * ("<timestamp>:<user-id>") and `_edit_last` disclose which account edited a post and when,
 * `_thumbnail_id` can carry serialized PHP, and `_`-prefixed keys generally belong to plugins, not
 * to consumers. That is both an information leak and a Rule 2 violation — a projection is an
 * optimised delivery store, not a replica of `wp_postmeta`.
 *
 * Filtering here, at extraction, is deliberate: the values never reach the outbox, never relay into
 * `system.events`, and never land in a projection — as opposed to hiding them at the API edge,
 * which would still persist and relay them. Existing projections carrying protected meta clear
 * themselves on the next re-projection, since dropping keys changes the projection checksum and
 * reconciliation therefore sees drift.
 *
 * The rule is WordPress's own convention, matching `is_protected_meta()`: a leading underscore
 * marks meta as internal. Public custom fields (`sub_title`, ACF-exposed values, …) are untouched.
 * WordPress is not loaded in unit tests, so the underscore test is applied directly rather than
 * through `is_protected_meta()` — the convention is the contract, and this keeps the extractors
 * free of global WordPress calls (see the PostExtractor class docblock).
 */
final class ProtectedMeta
{
    /** WordPress's postmeta key for the featured image (attachment ID). */
    public const THUMBNAIL_ID = '_thumbnail_id';

    /**
     * Read the featured-image attachment ID out of raw post meta.
     *
     * `_thumbnail_id` is `_`-prefixed, so {@see publicOnly()} strips it — correctly, because the
     * raw value is WordPress bookkeeping and must not be published as a meta field. The featured
     * image still has to reach the projection, so it is extracted DELIBERATELY here and carried
     * as a typed field on the source model rather than smuggled through `meta` (P1B-S2).
     *
     * Returns 0 when absent, empty, or not a positive integer — WordPress post IDs start at 1,
     * so 0 unambiguously means "no featured image".
     *
     * @param array<string,mixed> $rawMeta get_post_meta()-style flat map
     */
    public static function featuredMediaId(array $rawMeta): int
    {
        $value = $rawMeta[self::THUMBNAIL_ID] ?? null;

        if (is_array($value)) {
            // Some callers hand through the raw get_post_meta() shape (key → list of values).
            $value = $value[0] ?? null;
        }

        if ($value === null || ! is_numeric($value)) {
            return 0;
        }

        $id = (int) $value;

        return $id > 0 ? $id : 0;
    }

    /**
     * Keep only public meta, with values normalised to JSON-safe scalars and arrays.
     *
     * Values are NO LONGER force-cast to string (P1B-S4). WordPress stores postmeta as strings,
     * so a scalar still arrives as a string and nothing changes for existing consumers — but a
     * structured value (an ACF repeater, gallery, checkbox or relationship field) is an ARRAY by
     * the time the loader has run `maybe_unserialize()` on it, and `(string) $array` published the
     * literal text "Array" (with a PHP notice), while an un-unserialized value published raw PHP
     * serialization like `a:2:{i:0;s:1:"a";…}`. Both are Rule 2 violations — a projection is a
     * delivery store, not a `wp_postmeta` replica — and Rule 6 violations, since a consumer would
     * have to understand PHP's serialization format to read the API.
     *
     * Objects and resources are DROPPED rather than published: `maybe_unserialize()` can return an
     * arbitrary object graph, which does not belong in a public JSON contract and would make the
     * adapter's json_encode fail (silently emptying the whole meta object).
     *
     * @param array<string,mixed> $rawMeta get_post_meta()-style flat map (meta_key → value)
     * @return array<string,mixed>
     */
    public static function publicOnly(array $rawMeta): array
    {
        $out = [];

        foreach ($rawMeta as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '_')) {
                continue;
            }

            $sanitised = self::sanitiseValue($value);

            if ($sanitised === null && $value !== null) {
                continue; // unpublishable (object / resource) — dropped, not stringified
            }

            $out[$key] = $sanitised;
        }

        return $out;
    }

    /**
     * Reduce a meta value to something safely JSON-encodable, recursively.
     *
     * Returns null for values that cannot be published (objects, resources); a genuine null meta
     * value also returns null, which the caller distinguishes by checking the input.
     */
    private static function sanitiseValue(mixed $value): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null; // object, resource, closure — not publishable
        }

        $out = [];
        foreach ($value as $k => $v) {
            $clean = self::sanitiseValue($v);

            if ($clean === null && $v !== null) {
                continue;
            }

            $out[$k] = $clean;
        }

        return $out;
    }
}
