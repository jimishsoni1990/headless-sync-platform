<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Events;

/**
 * Canonical fully-qualified event type names for the Content module.
 *
 * Authority: OPEN-1 — all names follow <domain>.<aggregate>.<action>.
 * Bare names are prohibited. Nine constants are the Blog MVP set; P1B-S1 adds the three
 * content.media.* types (Doc 11 §7 Media Synchronization).
 */
final class ContentEventTypes
{
    // Pages
    public const PAGE_CREATED  = 'content.page.created';
    public const PAGE_UPDATED  = 'content.page.updated';
    public const PAGE_DELETED  = 'content.page.deleted';

    // Posts
    public const POST_CREATED  = 'content.post.created';
    public const POST_UPDATED  = 'content.post.updated';
    public const POST_DELETED  = 'content.post.deleted';

    // Categories
    public const CATEGORY_CREATED = 'content.category.created';
    public const CATEGORY_UPDATED = 'content.category.updated';
    public const CATEGORY_DELETED = 'content.category.deleted';

    // Media (attachments — P1B-S1)
    public const MEDIA_CREATED = 'content.media.created';
    public const MEDIA_UPDATED = 'content.media.updated';
    public const MEDIA_DELETED = 'content.media.deleted';

    /** Every OPEN-1 event type the Content module emits, in a single list. */
    public const ALL = [
        self::PAGE_CREATED,
        self::PAGE_UPDATED,
        self::PAGE_DELETED,
        self::POST_CREATED,
        self::POST_UPDATED,
        self::POST_DELETED,
        self::CATEGORY_CREATED,
        self::CATEGORY_UPDATED,
        self::CATEGORY_DELETED,
        self::MEDIA_CREATED,
        self::MEDIA_UPDATED,
        self::MEDIA_DELETED,
    ];

    private function __construct() {}
}
