<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\SourceModels;

/**
 * Normalized snapshot of one WooCommerce global attribute DEFINITION.
 *
 * Not a taxonomy term (AG-9): a definition carries a display label, a value type, an ordering
 * rule and archive behaviour, none of which a term has. Verified shape from wc_get_attribute().
 */
final class AttributeSourceModel
{
    /**
     * @param int    $attributeId  woocommerce_attribute_taxonomies.attribute_id
     * @param string $slug         Full taxonomy name including the `pa_` prefix
     * @param string $name         Human label
     * @param string $type         'select', 'text', …
     * @param string $orderBy      'menu_order' | 'name' | 'name_num' | 'id'
     * @param bool   $hasArchives  Whether WooCommerce publishes archives for this taxonomy
     */
    public function __construct(
        public readonly int $attributeId,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $type,
        public readonly string $orderBy,
        public readonly bool $hasArchives,
    ) {
    }
}
