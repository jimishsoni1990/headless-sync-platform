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

    // Product categories (P2-S3). The OPEN-1 aggregate is `product_category`; the WordPress
    // taxonomy is `product_cat` — the same naming asymmetry Content has between `tag` and
    // `post_tag`. The aggregate is NOT bare `category`: aggregate types are platform-wide keys
    // and Content already owns that one (see CommerceTaxonomies).
    public const CATEGORY_CREATED = 'commerce.product_category.created';
    public const CATEGORY_UPDATED = 'commerce.product_category.updated';
    public const CATEGORY_DELETED = 'commerce.product_category.deleted';

    // Global attribute DEFINITIONS (P2-S4). Not taxonomy terms — they live in WooCommerce's
    // own woocommerce_attribute_taxonomies table and carry label/type/ordering semantics.
    public const ATTRIBUTE_CREATED = 'commerce.attribute.created';
    public const ATTRIBUTE_UPDATED = 'commerce.attribute.updated';
    public const ATTRIBUTE_DELETED = 'commerce.attribute.deleted';

    // Terms of the pa_* attribute taxonomies (P2-S4). ONE aggregate covering every pa_*
    // taxonomy, because those taxonomies are dynamic — they come and go with the attributes an
    // operator defines, so there is no fixed set to enumerate.
    public const ATTRIBUTE_TERM_CREATED = 'commerce.attribute_term.created';
    public const ATTRIBUTE_TERM_UPDATED = 'commerce.attribute_term.updated';
    public const ATTRIBUTE_TERM_DELETED = 'commerce.attribute_term.deleted';

    // Product variations (P2-S5). An independently synchronised aggregate, not a nested part of
    // its product: a variation has its own post id, its own WooCommerce CRUD hooks, its own
    // price and its own lifecycle.
    public const VARIATION_CREATED = 'commerce.product_variation.created';
    public const VARIATION_UPDATED = 'commerce.product_variation.updated';
    public const VARIATION_DELETED = 'commerce.product_variation.deleted';

    /** @var list<string> */
    public const ALL = [
        self::PRODUCT_CREATED,
        self::PRODUCT_UPDATED,
        self::PRODUCT_DELETED,
        self::CATEGORY_CREATED,
        self::CATEGORY_UPDATED,
        self::CATEGORY_DELETED,
        self::ATTRIBUTE_CREATED,
        self::ATTRIBUTE_UPDATED,
        self::ATTRIBUTE_DELETED,
        self::ATTRIBUTE_TERM_CREATED,
        self::ATTRIBUTE_TERM_UPDATED,
        self::ATTRIBUTE_TERM_DELETED,
        self::VARIATION_CREATED,
        self::VARIATION_UPDATED,
        self::VARIATION_DELETED,
    ];
}
