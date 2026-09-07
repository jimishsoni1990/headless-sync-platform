<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Events;

/**
 * Fully-qualified Commerce event types — `<domain>.<aggregate>.<action>` (OPEN-1).
 *
 * The `commerce` first segment is also the queue routing key (DECISION AG AG-4): the
 * PartitionRouter resolves it to the `commerce` partition, which has existed in
 * config/queue.php and DatabaseQueueProvider::VALID_PARTITIONS since P0-S5 with nothing able
 * to put a job in it until now.
 *
 * Phase 2 aggregates arrive across sessions: `product` here (P2-S2), then taxonomy terms
 * (P2-S3), attributes (P2-S4), variations (P2-S5) and inventory (P2-S6).
 */
final class CommerceEventTypes
{
    private function __construct()
    {
    }

    public const PRODUCT_CREATED = 'commerce.product.created';
    public const PRODUCT_UPDATED = 'commerce.product.updated';
    public const PRODUCT_DELETED = 'commerce.product.deleted';

    // Product categories (P2-S3). The OPEN-1 aggregate is `category`; the WordPress taxonomy
    // is `product_cat` — the same naming asymmetry Content has between `tag` and `post_tag`.
    public const CATEGORY_CREATED = 'commerce.product_category.created';
    public const CATEGORY_UPDATED = 'commerce.product_category.updated';
    public const CATEGORY_DELETED = 'commerce.product_category.deleted';

    /** @var list<string> */
    public const ALL = [
        self::PRODUCT_CREATED,
        self::PRODUCT_UPDATED,
        self::PRODUCT_DELETED,
        self::CATEGORY_CREATED,
        self::CATEGORY_UPDATED,
        self::CATEGORY_DELETED,
    ];
}
