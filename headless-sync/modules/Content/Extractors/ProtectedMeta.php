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
    /**
     * Keep only public meta, with values normalized to string.
     *
     * @param array<string,mixed> $rawMeta get_post_meta()-style flat map (meta_key → scalar value)
     * @return array<string,string>
     */
    public static function publicOnly(array $rawMeta): array
    {
        $out = [];

        foreach ($rawMeta as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '_')) {
                continue;
            }

            $out[$key] = (string) $value;
        }

        return $out;
    }
}
