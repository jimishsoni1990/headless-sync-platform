<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

use HSP\Core\Contracts\EventProviderInterface;
use HSP\Modules\Content\Events\ContentEventTypes;

/**
 * Wires WordPress hooks to Content domain events via the EventProvider.
 *
 * Seven hooks resolve to nine event types (OPEN-1).
 *
 * Membership-based public-set capture (OPEN-10 — Resolved):
 *   Public set = {publish} only.
 *
 *   transition_post_status:
 *     non-public → publish               → post/page created
 *     publish    → publish               → post/page updated
 *     publish    → non-public (any exit) → post/page deleted
 *     non-public → non-public            → NO event
 *   save_post       → post/page updated (publish-only guard; not reached when
 *                     transition_post_status already handled the post_id)
 *   wp_trash_post   → post/page deleted (suppressed when transition already fired)
 *   after_delete_post → post/page deleted (permanent hard-delete)
 *   created_term    → category created
 *   edited_term     → category updated
 *   delete_term     → category deleted
 *
 * All outbox writes are post-commit (DECISION 1) — hooks fire after WordPress
 * completes its own DB transaction. No cross-DB transaction is attempted.
 *
 * Category hooks are scoped to taxonomy='category' only (MVP scope).
 * Tags and other taxonomies are ignored.
 *
 * Double-write prevention: transition_post_status sets a per-request flag for each
 * post_id it handles. Both save_post and wp_trash_post skip any post_id already
 * handled by transition_post_status in the same request. transition_post_status
 * is the authoritative hook for all status-change emits including trash.
 */
final class HookWiring
{
    private const SUPPORTED_POST_TYPES = ['post', 'page'];

    /** @var array<int,true> post_ids handled by transition_post_status this request */
    private array $handledByTransition = [];

    public function __construct(
        private readonly EventProviderInterface $eventProvider,
    ) {}

    /**
     * Register all seven WordPress action hooks.
     *
     * Called during module register() — before boot(). WordPress's add_action()
     * must be available at call time.
     */
    public function register(): void
    {
        add_action('transition_post_status', [$this, 'onTransitionPostStatus'], 10, 3);
        add_action('save_post',              [$this, 'onSavePost'],              20, 3);
        add_action('wp_trash_post',          [$this, 'onWpTrashPost'],           10, 1);
        add_action('after_delete_post',      [$this, 'onAfterDeletePost'],       10, 2);
        add_action('created_term',           [$this, 'onCreatedTerm'],           10, 3);
        add_action('edited_term',            [$this, 'onEditedTerm'],            10, 3);
        add_action('delete_term',            [$this, 'onDeleteTerm'],            10, 4);
    }

    // -------------------------------------------------------------------------
    // Post / Page hooks
    // -------------------------------------------------------------------------

    /**
     * transition_post_status: fires whenever a post's status changes.
     *
     * Membership-based: emits only when at least one side is in the public set {publish}.
     * non-public→publish = created; publish→publish = updated; publish→non-public = deleted.
     * transition_post_status is the authoritative hook for trash as well (both it and
     * wp_trash_post fire for a trash action; the guard below ensures only one emit).
     *
     * @param string   $newStatus
     * @param string   $oldStatus
     * @param \WP_Post $post
     */
    public function onTransitionPostStatus(string $newStatus, string $oldStatus, object $post): void
    {
        if (! in_array($post->post_type, self::SUPPORTED_POST_TYPES, true)) {
            return;
        }

        $wasPublic = ($oldStatus === 'publish');
        $isPublic  = ($newStatus === 'publish');

        // Skip non-public → non-public transitions; neither side is in the public set.
        if (! $wasPublic && ! $isPublic) {
            return;
        }

        $postId    = (int) $post->ID;
        $eventType = $this->resolvePostEventTypeForTransition($wasPublic, $isPublic, $post->post_type);

        $this->handledByTransition[$postId] = true;

        $this->eventProvider->provide(
            $eventType,
            (string) $postId,
            $this->postContext($post),
        );
    }

    /**
     * save_post: fires on every post save, after the DB write.
     *
     * Only emits an event if:
     *   1. Post type is in scope (post, page).
     *   2. Post is already published ($post->post_status === 'publish').
     *   3. transition_post_status did NOT already handle this post_id (prevents double-emit).
     *
     * @param int      $postId
     * @param \WP_Post $post
     * @param bool     $update
     */
    public function onSavePost(int $postId, object $post, bool $update): void
    {
        if (! in_array($post->post_type, self::SUPPORTED_POST_TYPES, true)) {
            return;
        }

        // Skip revisions and auto-drafts.
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        // Already handled by transition_post_status this request — no double-emit.
        if (isset($this->handledByTransition[$postId])) {
            return;
        }

        $eventType = $update
            ? $this->resolveUpdateEventType($post->post_type)
            : $this->resolveCreateEventType($post->post_type);

        $this->eventProvider->provide(
            $eventType,
            (string) $postId,
            $this->postContext($post),
        );
    }

    /**
     * wp_trash_post: fires when a post is moved to the trash.
     *
     * WordPress fires both transition_post_status(publish→trash) AND wp_trash_post
     * for a trash action. transition_post_status is authoritative; this hook is
     * suppressed when transition already handled the post_id this request (OPEN-10).
     *
     * @param int $postId
     */
    public function onWpTrashPost(int $postId): void
    {
        // transition_post_status already emitted for this post_id — suppress double-emit.
        if (isset($this->handledByTransition[$postId])) {
            return;
        }

        $post = get_post($postId);
        if ($post === null || ! in_array($post->post_type, self::SUPPORTED_POST_TYPES, true)) {
            return;
        }

        $eventType = $this->resolveDeleteEventType($post->post_type);

        $this->eventProvider->provide(
            $eventType,
            (string) $postId,
            $this->postContext($post),
        );
    }

    /**
     * after_delete_post: fires after a post is permanently deleted.
     *
     * Both wp_trash_post and after_delete_post map to *.deleted (OPEN-1).
     * Trashing and permanent deletion are both modelled as deletion events.
     *
     * @param int      $postId
     * @param \WP_Post $post  The deleted post object (still available at this hook).
     */
    public function onAfterDeletePost(int $postId, object $post): void
    {
        if (! in_array($post->post_type, self::SUPPORTED_POST_TYPES, true)) {
            return;
        }

        $eventType = $this->resolveDeleteEventType($post->post_type);

        $this->eventProvider->provide(
            $eventType,
            (string) $postId,
            $this->postContext($post),
        );
    }

    // -------------------------------------------------------------------------
    // Category hooks (taxonomy='category' only — MVP scope)
    // -------------------------------------------------------------------------

    /**
     * created_term: fires after a new term is inserted.
     *
     * @param int    $termId
     * @param int    $ttId     Term taxonomy ID (unused).
     * @param string $taxonomy
     */
    public function onCreatedTerm(int $termId, int $ttId, string $taxonomy): void
    {
        if ($taxonomy !== 'category') {
            return;
        }

        $this->eventProvider->provide(
            ContentEventTypes::CATEGORY_CREATED,
            (string) $termId,
            $this->categoryContext($termId),
        );
    }

    /**
     * edited_term: fires after an existing term is updated.
     *
     * @param int    $termId
     * @param int    $ttId
     * @param string $taxonomy
     */
    public function onEditedTerm(int $termId, int $ttId, string $taxonomy): void
    {
        if ($taxonomy !== 'category') {
            return;
        }

        $this->eventProvider->provide(
            ContentEventTypes::CATEGORY_UPDATED,
            (string) $termId,
            $this->categoryContext($termId),
        );
    }

    /**
     * delete_term: fires before a term is deleted (term data still readable).
     *
     * @param int    $termId
     * @param int    $ttId
     * @param string $taxonomy
     * @param mixed  $deletedTerm  WP_Term object or WP_Error of the deleted term.
     */
    public function onDeleteTerm(int $termId, int $ttId, string $taxonomy, mixed $deletedTerm): void
    {
        if ($taxonomy !== 'category') {
            return;
        }

        $this->eventProvider->provide(
            ContentEventTypes::CATEGORY_DELETED,
            (string) $termId,
            $this->categoryContext($termId),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the action for transition_post_status given membership booleans.
     * Caller guarantees at least one of $wasPublic / $isPublic is true.
     */
    private function resolvePostEventTypeForTransition(
        bool   $wasPublic,
        bool   $isPublic,
        string $postType,
    ): string {
        if (! $wasPublic && $isPublic) {
            $action = 'created';   // entry into public set
        } elseif ($wasPublic && $isPublic) {
            $action = 'updated';   // in-set update
        } else {
            $action = 'deleted';   // exit from public set
        }

        return "content.{$postType}.{$action}";
    }

    private function resolveCreateEventType(string $postType): string
    {
        return "content.{$postType}.created";
    }

    private function resolveUpdateEventType(string $postType): string
    {
        return "content.{$postType}.updated";
    }

    private function resolveDeleteEventType(string $postType): string
    {
        return "content.{$postType}.deleted";
    }

    /** @return array<string, mixed> */
    private function postContext(object $post): array
    {
        $modifiedAt = isset($post->post_modified_gmt) && $post->post_modified_gmt !== '0000-00-00 00:00:00'
            ? new \DateTimeImmutable($post->post_modified_gmt . ' UTC')
            : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return [
            'source_updated_at' => $modifiedAt,
            'payload'           => [
                'post_id'   => (int) $post->ID,
                'post_type' => $post->post_type,
                'status'    => $post->post_status,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function categoryContext(int $termId): array
    {
        return [
            'source_updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'payload'           => [
                'term_id' => $termId,
            ],
        ];
    }
}
