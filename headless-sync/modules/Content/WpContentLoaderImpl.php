<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

/**
 * Live WordPress implementation of WpContentLoader.
 *
 * All WordPress function calls in the handler pipeline are confined to this class.
 * Handlers never call get_post(), get_post_meta(), or get_term() directly (ADR-012,
 * DECISION H Option B). This class is injected via constructor; the fake is injected
 * in unit tests.
 *
 * Authority:
 *   DECISION H (v1.10) — reload current WP state at handler time via bootstrap path.
 *   ADR-044             — stateless; each event reloads current state.
 */
final class WpContentLoaderImpl implements WpContentLoader
{
    /**
     * @return array<string,mixed>|null
     */
    public function loadPost(int $postId): ?array
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post) {
            return null;
        }

        return (array) $post;
    }

    /**
     * @return array<string,string>
     */
    public function loadPostMeta(int $postId): array
    {
        $raw = get_post_meta($postId);

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $values) {
            // get_post_meta() returns each key as an array of values; take the first scalar.
            $value = is_array($values) ? ($values[0] ?? '') : $values;
            $out[(string) $key] = (string) $value;
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadTerm(int $termId): ?array
    {
        $term = get_term($termId, 'category');

        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return null;
        }

        return (array) $term;
    }

    /**
     * @return list<int>
     */
    public function loadPostCategoryIds(int $postId): array
    {
        $terms = wp_get_post_terms($postId, 'category', ['fields' => 'ids']);

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        return array_values(array_map('intval', $terms));
    }
}
