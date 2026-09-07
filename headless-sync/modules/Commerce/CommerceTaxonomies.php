<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

use HSP\Modules\Commerce\Events\CommerceEventTypes;

/**
 * Which WordPress taxonomies the Commerce module owns, and the aggregate each maps to.
 *
 * The WordPress taxonomy is `product_cat`; the OPEN-1 aggregate is **`product_category`**,
 * giving `commerce.product_category.*`.
 *
 * WHY NOT simply `category`: the aggregate type is a PLATFORM-WIDE key, not a per-module one.
 * The core-owned replay, reconciliation and projection registries are all keyed by it, and a
 * projection descriptor maps one aggregate type to exactly one table — so `category` cannot
 * mean `content.taxonomies` for one module and `commerce.taxonomies` for another. The first
 * attempt used the bare name and the AG-2 duplicate-registration guard caught it immediately,
 * which is precisely the failure mode that guard exists for: before P2-S1 the second module to
 * register would simply have taken ownership, silently.
 *
 * A WordPress post category and a WooCommerce product category are genuinely different
 * aggregates, so distinct names are the honest modelling as well as the safe one.
 *
 * Phase 2 scope (Doc 11 §11) is CATEGORIES. `product_tag` is deliberately absent: AG-9 says not
 * to implement it merely because the shared-taxonomy architecture would support it. The `pa_*`
 * attribute taxonomies join in P2-S4, and they are dynamic — one per global attribute — so
 * they are matched by PREFIX rather than listed.
 *
 * An unsupported taxonomy is ignored SILENTLY, not treated as an error: other plugins register
 * taxonomies freely and their terms are normal traffic passing through the same hooks.
 */
final class CommerceTaxonomies
{
    private function __construct()
    {
    }

    /** WordPress taxonomy for product categories. */
    public const PRODUCT_CAT = 'product_cat';

    /** Prefix WooCommerce gives every global product-attribute taxonomy (P2-S4). */
    public const ATTRIBUTE_PREFIX = 'pa_';

    /** WordPress taxonomy → OPEN-1 aggregate type. */
    private const AGGREGATES = [
        self::PRODUCT_CAT => 'product_category',
    ];

    /** Aggregate type → the module's event constants for that aggregate. */
    private const EVENTS = [
        'product_category' => [
            'created' => CommerceEventTypes::CATEGORY_CREATED,
            'updated' => CommerceEventTypes::CATEGORY_UPDATED,
            'deleted' => CommerceEventTypes::CATEGORY_DELETED,
        ],
        'attribute_term' => [
            'created' => CommerceEventTypes::ATTRIBUTE_TERM_CREATED,
            'updated' => CommerceEventTypes::ATTRIBUTE_TERM_UPDATED,
            'deleted' => CommerceEventTypes::ATTRIBUTE_TERM_DELETED,
        ],
    ];

    public static function isSupported(string $taxonomy): bool
    {
        return self::aggregateFor($taxonomy) !== null;
    }

    /**
     * The OPEN-1 aggregate type for a taxonomy, or null when the module does not own it.
     *
     * Two matching modes, because Commerce owns two KINDS of taxonomy. `product_cat` is a
     * fixed name. The attribute taxonomies are DYNAMIC — `pa_colour`, `pa_size`, whatever an
     * operator defines — so they cannot be enumerated and are matched by their `pa_` prefix
     * instead. All of them map to ONE aggregate, `attribute_term`: they share a projection, a
     * spine and a lifecycle, and differ only in the discriminator value stored on each row.
     */
    public static function aggregateFor(string $taxonomy): ?string
    {
        if (isset(self::AGGREGATES[$taxonomy])) {
            return self::AGGREGATES[$taxonomy];
        }

        // Guard against the bare prefix itself: `pa_` alone is not a taxonomy.
        if (
            str_starts_with($taxonomy, self::ATTRIBUTE_PREFIX)
            && strlen($taxonomy) > strlen(self::ATTRIBUTE_PREFIX)
        ) {
            return 'attribute_term';
        }

        return null;
    }

    public static function isAttributeTaxonomy(string $taxonomy): bool
    {
        return self::aggregateFor($taxonomy) === 'attribute_term';
    }

    /**
     * The event type for a taxonomy and action, or null when unsupported.
     *
     * @param string $action 'created' | 'updated' | 'deleted'
     */
    public static function eventFor(string $taxonomy, string $action): ?string
    {
        $aggregate = self::aggregateFor($taxonomy);

        if ($aggregate === null) {
            return null;
        }

        return self::EVENTS[$aggregate][$action] ?? null;
    }

    /**
     * Every taxonomy the module owns — the reconciliation corpus.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return array_keys(self::AGGREGATES);
    }
}
