<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Operations;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Core\Contracts\Operations\SchemaObject;

/**
 * Module-owned endpoint metadata for the Commerce `hsp/v1` routes (ADR-055).
 *
 * The ADR-055 (f) drift guard fails CI for any registered `hsp/v1` route without a complete
 * descriptor, so these are not documentation — they are what keeps `openapi.json` describing
 * the Commerce endpoints with no hand-authoring, and what the API Playground reads.
 *
 * The published shape here is the RESOURCE's shape (Rule 6), never the `commerce.*` columns.
 * Note what that means concretely: prices are `string`, because publishing a decimal as a JSON
 * number would hand consumers a binary float; media are id references, because `content.media`
 * owns attachment state (AG-10); and there is no `permalink` field, because the WooCommerce
 * permalink base is configurable and a stored URL would go stale (FLAG-COMMPERMA-1).
 */
final class CommerceEndpointProvider implements EndpointProviderInterface
{
    public const KEY = 'commerce.endpoints';

    private const NAMESPACE = 'hsp/v1';

    private const MODULE = 'commerce';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return EndpointDescriptor[] */
    public function endpoints(): array
    {
        return [
            $this->productsList(),
            $this->productSingle(),
            $this->categoriesList(),
            $this->categorySingle(),
            $this->attributesList(),
            $this->attributeSingle(),
            $this->attributeTermsList(),
            $this->variationsList(),
        ];
    }

    private function productsList(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/products',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'List catalog products. Excludes products WooCommerce hides from the '
                . 'catalog, which is NOT the same as unpublished.',
            parameters: [
                self::query('cursor', 'string', 'Opaque pagination cursor.'),
                self::query('limit', 'integer', 'Page size (max 100).'),
                self::query('sku', 'string', 'Exact SKU match.'),
                self::query('type', 'string', "Product type: 'simple' or 'variable'."),
                self::query('featured', 'boolean', 'Featured products only.'),
                self::query('min_price', 'string', 'Inclusive lower price bound, exact decimal string.'),
                self::query('max_price', 'string', 'Inclusive upper price bound, exact decimal string.'),
                self::query('category', 'string', 'Product-category slug.'),
                self::query('attribute', 'string', 'Full attribute taxonomy name, e.g. pa_colour. '
                    . 'Applied only together with attribute_term.'),
                self::query('attribute_term', 'string', 'Attribute term slug within that taxonomy.'),
                self::query('in_stock', 'boolean', 'Availability. Products whose inventory has '
                    . 'not projected yet match NEITHER value: unknown is not in stock, and it is '
                    . 'not out of stock either.'),
            ],
            responseSchema: $this->productSchema()->asCursorPage(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: true,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function productSingle(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/products/{slug}',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'Fetch one product by slug. Unlike the listing, a product hidden from '
                . 'the catalog remains reachable at its direct address, matching WooCommerce.',
            parameters: [
                EndpointParameter::path('slug', 'string', 'Product slug.'),
            ],
            responseSchema: $this->productSchema(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: false,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function categoriesList(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/product-categories',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'List WooCommerce product categories. Namespaced separately from the '
                . "Content module's /categories, which serves WordPress post categories.",
            parameters: [
                self::query('cursor', 'string', 'Opaque pagination cursor.'),
                self::query('limit', 'integer', 'Page size (max 200).'),
                self::query('parent', 'integer', 'Only terms directly under this parent term id.'),
            ],
            responseSchema: $this->termSchema()->asCursorPage(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: true,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function categorySingle(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/product-categories/{slug}',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'Fetch one product category by slug.',
            parameters: [EndpointParameter::path('slug', 'string', 'Category slug.')],
            responseSchema: $this->termSchema(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: false,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function attributesList(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/product-attributes',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'List WooCommerce global attribute definitions (Colour, Size, …). These '
                . 'are definitions, not terms: the terms of one attribute are served by '
                . '/product-attributes/{taxonomy}/terms.',
            parameters: [
                self::query('cursor', 'string', 'Opaque pagination cursor.'),
                self::query('limit', 'integer', 'Page size (max 200).'),
            ],
            responseSchema: $this->attributeSchema()->asCursorPage(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: true,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function attributeSingle(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/product-attributes/{taxonomy}',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'Fetch one global attribute definition by its full taxonomy name.',
            parameters: [
                EndpointParameter::path('taxonomy', 'string', 'Full taxonomy name, e.g. pa_colour.'),
            ],
            responseSchema: $this->attributeSchema(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: false,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function attributeTermsList(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/product-attributes/{taxonomy}/terms',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'List the terms of one global attribute. Only pa_-prefixed taxonomies '
                . 'resolve here; anything else returns 404 rather than reaching across into '
                . 'another taxonomy sharing the same projection.',
            parameters: [
                EndpointParameter::path('taxonomy', 'string', 'Full taxonomy name, e.g. pa_colour.'),
                self::query('cursor', 'string', 'Opaque pagination cursor.'),
                self::query('limit', 'integer', 'Page size (max 200).'),
            ],
            responseSchema: $this->termSchema()->asCursorPage(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: true,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function variationsList(): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: '/products/{slug}/variations',
            namespace: self::NAMESPACE,
            displayGroup: 'Commerce',
            description: 'List the variations of one product, in the store\'s own order. Nested '
                . 'under the product because WooCommerce gives a variation no permalink and no '
                . 'independent catalogue presence.',
            parameters: [
                EndpointParameter::path('slug', 'string', 'Parent product slug.'),
                self::query('cursor', 'string', 'Opaque pagination cursor.'),
                self::query('limit', 'integer', 'Page size (max 200).'),
            ],
            responseSchema: $this->variationSchema()->asCursorPage(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: true,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
        );
    }

    private function variationSchema(): SchemaObject
    {
        return SchemaObject::object([
            'id'          => 'string',
            'source_id'   => 'integer',
            // The parent's source id, so a consumer holding a variation can get back to it.
            'product_id'  => 'integer',
            'sku'         => 'string',
            'name'        => 'string',
            'description' => 'string',
            'status'      => 'string',
            // Exact decimal strings, never JSON numbers (Requirement C).
            'prices'      => 'object',
            // taxonomy => selected value. An EMPTY value means "any value of this attribute",
            // which is not the same as the attribute being absent.
            'attributes'  => 'object',
            // Attachment id reference; content.media owns the projection (AG-10).
            'media'       => 'object',
            'menu_order'  => 'integer',
        ]);
    }

    private function attributeSchema(): SchemaObject
    {
        return SchemaObject::object([
            'id'           => 'string',
            'source_id'    => 'integer',
            // The FULL pa_-prefixed name — the key that joins to this attribute's terms.
            'taxonomy'     => 'string',
            'name'         => 'string',
            'type'         => 'string',
            'order_by'     => 'string',
            'has_archives' => 'boolean',
        ]);
    }

    private function termSchema(): SchemaObject
    {
        return SchemaObject::object([
            'id'          => 'string',
            'source_id'   => 'integer',
            'slug'        => 'string',
            'name'        => 'string',
            'description' => 'string',
            // Parent SOURCE term id, or null at top level — so a consumer can rebuild the tree
            // without a second lookup.
            'parent'      => 'integer',
            'count'       => 'integer',
        ]);
    }

    /** Optional query parameter — the shape every listing filter shares. */
    private static function query(string $name, string $type, string $description): EndpointParameter
    {
        return new EndpointParameter($name, EndpointParameter::IN_QUERY, $type, false, $description);
    }

    private function productSchema(): SchemaObject
    {
        return SchemaObject::object([
            'id'                 => 'string',
            'source_id'          => 'integer',
            'sku'                => 'string',
            'slug'               => 'string',
            'name'               => 'string',
            'description'        => 'string',
            'short_description'  => 'string',
            'type'               => 'string',
            'catalog_visibility' => 'string',
            'featured'           => 'boolean',
            // Exact decimal strings, never JSON numbers (Requirement C).
            'prices'             => 'object',
            // Attachment id references; content.media owns the projection (AG-10).
            'media'              => 'object',
            // Joined from commerce.inventory at read time, never stored on the product (AG-8).
            // Every field inside is nullable, and null means UNKNOWN rather than out of stock.
            'stock'              => 'object',
            'published_at'       => 'string',
            'updated_at'         => 'string',
            'meta'               => 'object',
        ]);
    }
}
