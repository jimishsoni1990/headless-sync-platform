<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Modules\Content\EventProvider;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\HookWiring;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for HookWiring.
 *
 * Tests call handler methods directly — no WordPress hook system is loaded.
 * WordPress functions (get_post, wp_is_post_revision, wp_is_post_autosave) are
 * stubbed in tests/bootstrap.php via $GLOBALS arrays.
 *
 * Verifies:
 * - Each hook maps to the correct OPEN-1 event type.
 * - transition_post_status resolves created vs updated correctly.
 * - wp_trash_post and after_delete_post both emit *.deleted.
 * - category hooks are scoped to taxonomy='category' only.
 * - save_post does not double-emit when transition_post_status already fired.
 * - Unsupported post types and taxonomies are ignored.
 */
final class HookWiringTest extends TestCase
{
    private FakeOutboxWriter $writer;
    private EventProvider $provider;
    private HookWiring $wiring;

    protected function setUp(): void
    {
        $this->writer   = new FakeOutboxWriter();
        $this->provider = new EventProvider($this->writer);
        $this->wiring   = new HookWiring($this->provider);

        // Reset WP global stubs.
        $GLOBALS['_hsp_stub_get_post']    = [];
        $GLOBALS['_hsp_stub_is_revision'] = [];
        $GLOBALS['_hsp_stub_is_autosave'] = [];
    }

    // =========================================================================
    // transition_post_status
    // =========================================================================

    public function test_transition_post_status_draft_to_publish_emits_post_created(): void
    {
        $post = $this->makePost(1, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'draft', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_CREATED, $this->writer->lastWrite()['eventType']);
        self::assertSame('1', $this->writer->lastWrite()['aggregateId']);
    }

    public function test_transition_post_status_publish_to_publish_emits_post_updated(): void
    {
        $post = $this->makePost(2, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'publish', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_UPDATED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_post_status_pending_to_publish_emits_post_created(): void
    {
        $post = $this->makePost(3, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'pending', $post);

        self::assertSame(ContentEventTypes::POST_CREATED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_post_status_page_draft_to_publish_emits_page_created(): void
    {
        $post = $this->makePost(10, 'page', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'draft', $post);

        self::assertSame(ContentEventTypes::PAGE_CREATED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_post_status_page_publish_to_publish_emits_page_updated(): void
    {
        $post = $this->makePost(11, 'page', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'publish', $post);

        self::assertSame(ContentEventTypes::PAGE_UPDATED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_post_status_publish_to_draft_emits_post_deleted(): void
    {
        // OPEN-10 (Resolved): publish→draft is an exit from the public set → .deleted.
        $post = $this->makePost(4, 'post', 'draft');

        $this->wiring->onTransitionPostStatus('draft', 'publish', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_post_status_ignores_unsupported_post_type(): void
    {
        $post = $this->makePost(5, 'product', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'draft', $post);

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // save_post
    // =========================================================================

    public function test_save_post_published_update_emits_post_updated(): void
    {
        $postId = 20;
        $post   = $this->makePost($postId, 'post', 'publish');

        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_UPDATED, $this->writer->lastWrite()['eventType']);
        self::assertSame((string) $postId, $this->writer->lastWrite()['aggregateId']);
    }

    public function test_save_post_new_published_post_emits_post_created(): void
    {
        $postId = 21;
        $post   = $this->makePost($postId, 'post', 'publish');

        $this->wiring->onSavePost($postId, $post, false);

        self::assertSame(ContentEventTypes::POST_CREATED, $this->writer->lastWrite()['eventType']);
    }

    public function test_save_post_published_page_update_emits_page_updated(): void
    {
        $postId = 30;
        $post   = $this->makePost($postId, 'page', 'publish');

        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(ContentEventTypes::PAGE_UPDATED, $this->writer->lastWrite()['eventType']);
    }

    public function test_save_post_ignores_non_published_status(): void
    {
        $postId = 22;
        $post   = $this->makePost($postId, 'post', 'draft');

        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_save_post_ignores_unsupported_post_type(): void
    {
        $postId = 23;
        $post   = $this->makePost($postId, 'product', 'publish');

        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_save_post_ignores_revisions(): void
    {
        $postId = 24;
        $post   = $this->makePost($postId, 'post', 'publish');
        $GLOBALS['_hsp_stub_is_revision'][$postId] = true;

        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_save_post_ignores_autosaves(): void
    {
        $postId = 25;
        $post   = $this->makePost($postId, 'post', 'publish');
        $GLOBALS['_hsp_stub_is_autosave'][$postId] = true;

        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_save_post_does_not_double_emit_after_transition_post_status(): void
    {
        $postId = 26;
        $post   = $this->makePost($postId, 'post', 'publish');

        // Simulate WP calling transition_post_status before save_post.
        $this->wiring->onTransitionPostStatus('publish', 'draft', $post);
        $this->wiring->onSavePost($postId, $post, false);

        // Only one write: from transition_post_status; save_post must skip.
        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_CREATED, $this->writer->lastWrite()['eventType']);
    }

    // =========================================================================
    // wp_trash_post
    // =========================================================================

    public function test_wp_trash_post_emits_post_deleted(): void
    {
        $postId = 40;
        $post   = $this->makePost($postId, 'post', 'trash');
        $GLOBALS['_hsp_stub_get_post'][$postId] = $post;

        $this->wiring->onWpTrashPost($postId);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
        self::assertSame((string) $postId, $this->writer->lastWrite()['aggregateId']);
    }

    public function test_wp_trash_post_emits_page_deleted(): void
    {
        $postId = 41;
        $post   = $this->makePost($postId, 'page', 'trash');
        $GLOBALS['_hsp_stub_get_post'][$postId] = $post;

        $this->wiring->onWpTrashPost($postId);

        self::assertSame(ContentEventTypes::PAGE_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_wp_trash_post_ignores_unsupported_post_type(): void
    {
        $postId = 42;
        $post   = $this->makePost($postId, 'product', 'trash');
        $GLOBALS['_hsp_stub_get_post'][$postId] = $post;

        $this->wiring->onWpTrashPost($postId);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_wp_trash_post_ignores_unknown_post_id(): void
    {
        // get_post returns null for unknown ID.
        $this->wiring->onWpTrashPost(9999);

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // after_delete_post
    // =========================================================================

    public function test_after_delete_post_emits_post_deleted(): void
    {
        $postId = 50;
        $post   = $this->makePost($postId, 'post', 'publish');

        $this->wiring->onAfterDeletePost($postId, $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_after_delete_post_emits_page_deleted(): void
    {
        $postId = 51;
        $post   = $this->makePost($postId, 'page', 'publish');

        $this->wiring->onAfterDeletePost($postId, $post);

        self::assertSame(ContentEventTypes::PAGE_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_after_delete_post_ignores_unsupported_post_type(): void
    {
        $postId = 52;
        $post   = $this->makePost($postId, 'product', 'publish');

        $this->wiring->onAfterDeletePost($postId, $post);

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // created_term
    // =========================================================================

    public function test_created_term_category_emits_category_created(): void
    {
        $this->wiring->onCreatedTerm(100, 200, 'category');

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::CATEGORY_CREATED, $this->writer->lastWrite()['eventType']);
        self::assertSame('100', $this->writer->lastWrite()['aggregateId']);
    }

    public function test_created_term_ignores_non_category_taxonomy(): void
    {
        $this->wiring->onCreatedTerm(101, 201, 'post_tag');

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_created_term_ignores_custom_taxonomy(): void
    {
        $this->wiring->onCreatedTerm(102, 202, 'genre');

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // edited_term
    // =========================================================================

    public function test_edited_term_category_emits_category_updated(): void
    {
        $this->wiring->onEditedTerm(110, 210, 'category');

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::CATEGORY_UPDATED, $this->writer->lastWrite()['eventType']);
        self::assertSame('110', $this->writer->lastWrite()['aggregateId']);
    }

    public function test_edited_term_ignores_non_category_taxonomy(): void
    {
        $this->wiring->onEditedTerm(111, 211, 'post_tag');

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // delete_term
    // =========================================================================

    public function test_delete_term_category_emits_category_deleted(): void
    {
        $this->wiring->onDeleteTerm(120, 220, 'category', new \stdClass());

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::CATEGORY_DELETED, $this->writer->lastWrite()['eventType']);
        self::assertSame('120', $this->writer->lastWrite()['aggregateId']);
    }

    public function test_delete_term_ignores_non_category_taxonomy(): void
    {
        $this->wiring->onDeleteTerm(121, 221, 'post_tag', new \stdClass());

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // (b) status filtering — auto-draft and inherit must never emit
    // =========================================================================

    public function test_save_post_auto_draft_emits_zero_events(): void
    {
        // auto-draft: WordPress creates an auto-draft before the user saves.
        // post_status = 'auto-draft'; must never emit.
        $postId = 60;
        $post   = $this->makePost($postId, 'post', 'auto-draft');

        $this->wiring->onSavePost($postId, $post, false);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_transition_post_status_auto_draft_target_emits_zero_events(): void
    {
        // If new_status is auto-draft, no event.
        $post = $this->makePost(61, 'post', 'auto-draft');

        $this->wiring->onTransitionPostStatus('auto-draft', 'new', $post);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_save_post_inherit_status_emits_zero_events(): void
    {
        // 'inherit' is used by attachments; must be ignored.
        $postId = 62;
        $post   = $this->makePost($postId, 'post', 'inherit');

        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // (c) created-vs-updated: first publish emits EXACTLY ONE created, no updated
    // =========================================================================

    public function test_first_publish_via_transition_emits_exactly_one_post_created_not_updated(): void
    {
        // Simulate WordPress publishing a brand-new post:
        // transition_post_status fires ('new' → 'publish'), then save_post fires ($update=false).
        $postId = 70;
        $post   = $this->makePost($postId, 'post', 'publish');

        // WP calls transition_post_status first.
        $this->wiring->onTransitionPostStatus('publish', 'new', $post);
        // Then save_post fires — must be suppressed by the double-emit guard.
        $this->wiring->onSavePost($postId, $post, false);

        self::assertSame(1, $this->writer->writeCount(), 'Exactly one event must be emitted.');
        self::assertSame(
            ContentEventTypes::POST_CREATED,
            $this->writer->lastWrite()['eventType'],
            'Event must be post.created, not post.updated.',
        );
    }

    public function test_first_publish_from_pending_emits_created_not_updated(): void
    {
        $postId = 71;
        $post   = $this->makePost($postId, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'pending', $post);
        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_CREATED, $this->writer->lastWrite()['eventType']);
    }

    // =========================================================================
    // (d) double-emit guard: publish→publish edit emits exactly one updated
    // =========================================================================

    public function test_publish_to_publish_edit_emits_exactly_one_post_updated(): void
    {
        // Normal editorial save of an already-published post:
        // transition_post_status fires (publish → publish), then save_post also fires.
        // Only ONE event must be emitted; it must be post.updated.
        $postId = 80;
        $post   = $this->makePost($postId, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'publish', $post);
        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(1, $this->writer->writeCount(), 'Exactly one event despite both hooks firing.');
        self::assertSame(
            ContentEventTypes::POST_UPDATED,
            $this->writer->lastWrite()['eventType'],
        );
    }

    public function test_publish_to_publish_page_edit_emits_exactly_one_page_updated(): void
    {
        $postId = 81;
        $post   = $this->makePost($postId, 'page', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'publish', $post);
        $this->wiring->onSavePost($postId, $post, true);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::PAGE_UPDATED, $this->writer->lastWrite()['eventType']);
    }

    // =========================================================================
    // (e) after_delete_post resolves post type from $post argument, not get_post()
    // =========================================================================

    public function test_after_delete_post_uses_passed_post_not_get_post(): void
    {
        // get_post() returns null for this ID (post has been deleted from DB).
        // after_delete_post receives the pre-deletion WP_Post object as its second arg.
        // The handler must use $post->post_type, not call get_post().
        $postId = 90;
        $post   = $this->makePost($postId, 'post', 'publish');
        // Deliberately do NOT seed $GLOBALS['_hsp_stub_get_post'][$postId].
        // If get_post() were called it would return null; the guard would bail.

        $this->wiring->onAfterDeletePost($postId, $post);

        self::assertSame(1, $this->writer->writeCount(), 'Must emit even when get_post() returns null.');
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_after_delete_post_page_uses_passed_post_not_get_post(): void
    {
        $postId = 91;
        $post   = $this->makePost($postId, 'page', 'publish');
        // No get_post stub seeded — would return null if called.

        $this->wiring->onAfterDeletePost($postId, $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::PAGE_DELETED, $this->writer->lastWrite()['eventType']);
    }

    // =========================================================================
    // OPEN-10 (Resolved) — exit transitions (publish → non-public) emit .deleted
    // =========================================================================

    public function test_transition_publish_to_pending_emits_post_deleted(): void
    {
        $post = $this->makePost(200, 'post', 'pending');

        $this->wiring->onTransitionPostStatus('pending', 'publish', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_publish_to_private_emits_post_deleted(): void
    {
        $post = $this->makePost(201, 'post', 'private');

        $this->wiring->onTransitionPostStatus('private', 'publish', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_publish_to_future_emits_post_deleted(): void
    {
        $post = $this->makePost(202, 'post', 'future');

        $this->wiring->onTransitionPostStatus('future', 'publish', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_publish_to_draft_emits_page_deleted(): void
    {
        // Page variant of the exit transition.
        $post = $this->makePost(203, 'page', 'draft');

        $this->wiring->onTransitionPostStatus('draft', 'publish', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::PAGE_DELETED, $this->writer->lastWrite()['eventType']);
    }

    // =========================================================================
    // OPEN-10 — non-public → non-public emits zero events
    // =========================================================================

    public function test_transition_draft_to_draft_emits_zero_events(): void
    {
        $post = $this->makePost(210, 'post', 'draft');

        $this->wiring->onTransitionPostStatus('draft', 'draft', $post);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_transition_pending_to_draft_emits_zero_events(): void
    {
        $post = $this->makePost(211, 'post', 'draft');

        $this->wiring->onTransitionPostStatus('draft', 'pending', $post);

        self::assertSame(0, $this->writer->writeCount());
    }

    public function test_transition_draft_to_private_emits_zero_events(): void
    {
        $post = $this->makePost(212, 'post', 'private');

        $this->wiring->onTransitionPostStatus('private', 'draft', $post);

        self::assertSame(0, $this->writer->writeCount());
    }

    // =========================================================================
    // OPEN-10 — non-public → publish (entry) emits .created
    // =========================================================================

    public function test_transition_private_to_publish_emits_post_created(): void
    {
        $post = $this->makePost(220, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'private', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_CREATED, $this->writer->lastWrite()['eventType']);
    }

    public function test_transition_future_to_publish_emits_post_created(): void
    {
        $post = $this->makePost(221, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'future', $post);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_CREATED, $this->writer->lastWrite()['eventType']);
    }

    // =========================================================================
    // OPEN-10 — trash emits EXACTLY ONE .deleted despite both hooks firing
    // =========================================================================

    public function test_publish_to_trash_emits_exactly_one_deleted_despite_both_hooks(): void
    {
        // WordPress fires transition_post_status(publish→trash) then wp_trash_post.
        // transition_post_status is authoritative. wp_trash_post must be suppressed.
        $postId = 230;
        $post   = $this->makePost($postId, 'post', 'trash');
        $GLOBALS['_hsp_stub_get_post'][$postId] = $post;

        // Simulate WordPress hook order.
        $this->wiring->onTransitionPostStatus('trash', 'publish', $post);
        $this->wiring->onWpTrashPost($postId);

        self::assertSame(1, $this->writer->writeCount(), 'Exactly one .deleted despite two hooks firing.');
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_publish_to_trash_page_emits_exactly_one_deleted(): void
    {
        $postId = 231;
        $post   = $this->makePost($postId, 'page', 'trash');
        $GLOBALS['_hsp_stub_get_post'][$postId] = $post;

        $this->wiring->onTransitionPostStatus('trash', 'publish', $post);
        $this->wiring->onWpTrashPost($postId);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::PAGE_DELETED, $this->writer->lastWrite()['eventType']);
    }

    public function test_wp_trash_post_standalone_emits_deleted_when_no_transition_fired(): void
    {
        // Edge case: wp_trash_post fires without a preceding transition (non-published post trashed).
        // The post was not in public set, so transition_post_status would have been suppressed
        // (non-public→non-public). wp_trash_post fires independently and MUST emit .deleted
        // to cover the hard-delete path.
        $postId = 232;
        $post   = $this->makePost($postId, 'post', 'trash');
        $GLOBALS['_hsp_stub_get_post'][$postId] = $post;

        // Only wp_trash_post fires (transition was a non-public→non-public and emitted nothing).
        $this->wiring->onWpTrashPost($postId);

        self::assertSame(1, $this->writer->writeCount());
        self::assertSame(ContentEventTypes::POST_DELETED, $this->writer->lastWrite()['eventType']);
    }

    // =========================================================================
    // Aggregate ID is always a string
    // =========================================================================

    public function test_transition_post_status_aggregate_id_is_string(): void
    {
        $post = $this->makePost(77, 'post', 'publish');

        $this->wiring->onTransitionPostStatus('publish', 'draft', $post);

        self::assertIsString($this->writer->lastWrite()['aggregateId']);
        self::assertSame('77', $this->writer->lastWrite()['aggregateId']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makePost(int $id, string $postType, string $status): object
    {
        $post                    = new \stdClass();
        $post->ID                = $id;
        $post->post_type         = $postType;
        $post->post_status       = $status;
        $post->post_modified_gmt = '2026-06-23 10:00:00';

        return $post;
    }
}
