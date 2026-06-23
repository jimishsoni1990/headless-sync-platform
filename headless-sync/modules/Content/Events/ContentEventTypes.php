<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Events;

/**
 * Canonical fully-qualified event type names for the Content module.
 *
 * Authority: OPEN-1 — all names follow <domain>.<aggregate>.<action>.
 * Bare names are prohibited; these nine constants are the complete MVP set.
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

    /** All nine OPEN-1 event types in a single list. */
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
    ];

    private function __construct() {}
}
