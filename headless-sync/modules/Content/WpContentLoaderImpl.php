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
     * @return array<string,mixed>
     */
    public function loadPostMeta(int $postId): array
    {
        $raw = get_post_meta($postId);

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $values) {
            // get_post_meta() returns each key as an array of values; take the first.
            $value = is_array($values) ? ($values[0] ?? '') : $values;

            // P1B-S4: the all-meta form of get_post_meta() does NOT unserialize — a documented
            // WordPress quirk (only the single-key form does). Without this, every structured ACF
            // value (repeater, gallery, checkbox, relationship) reached the projection as a raw
            // PHP-serialized string like `a:2:{i:0;s:1:"a";…}` and was published verbatim.
            // maybe_unserialize() is WordPress's own function and this class is the WP boundary,
            // so it belongs here rather than in an extractor.
            $out[(string) $key] = maybe_unserialize($value);
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadTerm(int $termId): ?array
    {
        // P1B-S3: no taxonomy argument — the term is looked up by id across taxonomies so the
        // same loader serves categories and tags. The returned array carries 'taxonomy', which is
        // what the extractor threads through to taxonomy_type.
        $term = get_term($termId);

        if (! $term instanceof \WP_Term || is_wp_error($term)) {
            return null;
        }

        return (array) $term;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function loadAttachment(int $postId): ?array
    {
        $post = get_post($postId);

        if (! $post instanceof \WP_Post || $post->post_type !== 'attachment') {
            return null;
        }

        $url = wp_get_attachment_url($postId);
        $alt = get_post_meta($postId, '_wp_attachment_image_alt', true);

        // wp_get_attachment_metadata() returns false for non-images and for attachments
        // whose metadata was never generated — normalise both to an empty array so the
        // extractor never has to type-check a WordPress return value.
        $metadata = wp_get_attachment_metadata($postId);

        return (array) $post + [
            'hsp_url'      => is_string($url) ? $url : '',
            'hsp_alt'      => is_string($alt) ? $alt : '',
            'hsp_metadata' => is_array($metadata) ? $metadata : [],
        ];
    }

    /**
     * @return list<int>
     */
    public function loadPostCategoryIds(int $postId): array
    {
        return $this->loadPostTermIds($postId, 'category');
    }

    /**
     * @return list<int>
     */
    public function loadPostTermIds(int $postId, string $taxonomy): array
    {
        $terms = wp_get_post_terms($postId, $taxonomy, ['fields' => 'ids']);

        if (is_wp_error($terms) || ! is_array($terms)) {
            return [];
        }

        return array_values(array_map('intval', $terms));
    }
}
