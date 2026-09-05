<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

/**
 * Gateway abstraction for reloading current WordPress entity state (DECISION H — Option B).
 *
 * Handlers receive this through constructor injection (ADR-012). The real implementation
 * calls get_post() / get_post_meta() / get_term() as appropriate. A fake implementation
 * is injected in unit tests — no WordPress bootstrap required.
 *
 * No global WP function calls appear in any handler class. All WP access is routed
 * through this interface.
 *
 * Authority:
 *   DECISION H (v1.10) — Option B: reload current WordPress state at handler time.
 *   ADR-044             — stateless workers; each event reloads current state.
 *   ADR-012             — constructor injection only; no global WP calls in handlers.
 */
interface WpContentLoader
{
    /**
     * Load raw WordPress post data (pages and posts).
     *
     * Returns an array matching the WP_Post cast-to-array shape expected by PageExtractor
     * and PostExtractor, or null if the post does not exist.
     *
     * @param int $postId  The WordPress post ID (source_post_id).
     * @return array<string,mixed>|null
     */
    public function loadPost(int $postId): ?array;

    /**
     * Load raw post meta for the given post.
     *
     * Returns the flat meta_key → scalar map expected by PageExtractor / PostExtractor.
     * Returns [] when the post has no meta or does not exist.
     *
     * @param int $postId
     * @return array<string,string>
     */
    public function loadPostMeta(int $postId): array;

    /**
     * Load raw WordPress term data for a category.
     *
     * Returns an array matching the WP_Term cast-to-array shape expected by CategoryExtractor,
     * or null if the term does not exist.
     *
     * @param int $termId  The WordPress term ID (source_term_id).
     * @return array<string,mixed>|null
     */
    public function loadTerm(int $termId): ?array;

    /**
     * Load raw WordPress attachment data (post_type='attachment').
     *
     * Returns the WP_Post cast-to-array shape PLUS three loader-resolved extras that only
     * WordPress can compute, so no extractor or handler has to call a WP function:
     *   hsp_url      — wp_get_attachment_url()          (string, '' when unresolvable)
     *   hsp_alt      — _wp_attachment_image_alt          (string, '' when unset)
     *   hsp_metadata — wp_get_attachment_metadata()      (array: width / height / sizes)
     * The keys are hsp_-prefixed so they cannot collide with a WP_Post column.
     *
     * Returns null if the attachment does not exist or is not an attachment.
     *
     * @param int $postId The WordPress attachment ID (source_post_id).
     * @return array<string,mixed>|null
     */
    public function loadAttachment(int $postId): ?array;

    /**
     * Load category term IDs assigned to a post.
     *
     * Returns a list of term_id integers for the 'category' taxonomy attached to $postId.
     * Returns [] when none assigned or post does not exist.
     *
     * @param int $postId
     * @return list<int>
     */
    public function loadPostCategoryIds(int $postId): array;
}
