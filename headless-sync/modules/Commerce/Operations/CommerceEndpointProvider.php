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
 *
 * The `woo_*` identifiers are the one deliberate exception to HSP keeping source ids out of the
 * published contract: WooCommerce is the transactional authority a storefront hands off to, and
 * every native cart mechanism it offers is keyed on WordPress post ids. They are interoperability
 * identifiers, not addressing — `/products/{slug}` is unchanged — and not a precedent for
 * exposing source ids elsewhere. Ratified by DECISION AK, which authorises them for Commerce
 * Product + Variation ONLY and is explicitly not a precedent for the rest of the platform.
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
            'id'               => 'string',
            // LEGACY generic identity, retained for compatibility only (DECISION AK-9). These are
            // NOT the Woo handoff contract: `source_id` names a different kind of entity on every
            // other Commerce resource — a term id on /product-categories, an attribute-definition
            // id on /product-attributes — so a consumer cannot read Woo semantics off the name.
            // The explicit `woo_*` fields below are what a handoff uses.
            'source_id'        => [
                'type'        => 'integer',
                'description' => 'Legacy generic source identifier, retained for compatibility. '
                    . 'NOT the WooCommerce interoperability contract: use woo_variation_id for '
                    . 'Woo variation interoperability.',
            ],
            'product_id'       => [
                'type'        => 'integer',
                'description' => "Legacy generic reference to this variation's parent, retained "
                    . 'for compatibility. NOT the WooCommerce interoperability contract: use '
                    . 'woo_product_id for the parent Woo product identity.',
            ],
            'woo_product_id'   => self::wooProductIdSchema(
                'Authoritative WooCommerce product id of this variation\'s PARENT product — the '
                . '`$product_id` argument of a native add-to-cart, never the variation itself.'
            ),
            'woo_variation_id' => [
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'Authoritative WooCommerce variation id for this variation, as '
                    . 'accepted by native WooCommerce cart flows (the `$variation_id` argument, '
                    . 'or the `variation_id` form field). Distinct from woo_product_id: a cart '
                    . 'handoff for a variable product needs BOTH, and they are never '
                    . 'interchangeable. Site-specific — authoritative for the connected source '
                    . 'WooCommerce site only, not a globally unique identifier.',
            ],
            // NULLABLE for the same reason as a product's: the column is `VARCHAR(255) NULL`.
            'sku'              => [
                'type'        => ['string', 'null'],
                'description' => 'Stock keeping unit, or null when the store has not set one.',
            ],
            'name'             => 'string',
            'description'      => 'string',
            'status'           => 'string',
            // Exact decimal strings, never JSON numbers (Requirement C).
            'prices'           => self::pricesSchema(),
            // taxonomy => selected value. An EMPTY value means "any value of this attribute",
            // which is not the same as the attribute being absent.
            'attributes'       => self::variationAttributesSchema(),
            // Attachment id reference; content.media owns the projection (AG-10).
            'media'            => self::variationMediaSchema(),
            'menu_order'       => 'integer',
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
            // Parent SOURCE term id, or NULL at top level — so a consumer can rebuild the tree
            // without a second lookup. The column is `NOT NULL DEFAULT 0`, but the published
            // contract deliberately maps the sentinel 0 to null: "top level" reads better than a
            // magic zero in JSON, and the schema must say so rather than promise an integer.
            'parent'      => [
                'type'        => ['integer', 'null'],
                'description' => 'Parent source term id, or null for a top-level term.',
            ],
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
            // LEGACY generic identity, retained for compatibility only (DECISION AK-9). NOT the
            // Woo handoff contract: `source_id` means a term id on /product-categories and an
            // attribute-definition id on /product-attributes, so the name carries no WooCommerce
            // semantics a consumer could rely on. `woo_product_id` below is what a handoff uses.
            'source_id'          => [
                'type'        => 'integer',
                'description' => 'Legacy generic source identifier, retained for compatibility. '
                    . 'NOT the WooCommerce interoperability contract: use woo_product_id for Woo '
                    . 'product interoperability.',
            ],
            'woo_product_id'     => self::wooProductIdSchema(
                'Authoritative WooCommerce product id for this product, as accepted by native '
                . 'WooCommerce cart flows. For a variable product this is the PARENT id — the '
                . 'selected variation is identified by woo_variation_id on the variation '
                . 'resource, which a simple product does not have.'
            ),
            // NULLABLE: `commerce.products.sku` is `VARCHAR(255) NULL` because a WooCommerce SKU
            // is optional. A product without one publishes null, not an empty string.
            'sku'                => [
                'type'        => ['string', 'null'],
                'description' => 'Stock keeping unit, or null when the store has not set one.',
            ],
            'slug'               => 'string',
            'name'               => 'string',
            'description'        => 'string',
            'short_description'  => 'string',
            'type'               => 'string',
            'catalog_visibility' => 'string',
            'featured'           => 'boolean',
            // Exact decimal strings, never JSON numbers (Requirement C).
            'prices'             => self::pricesSchema(),
            // Attachment id references; content.media owns the projection (AG-10).
            'media'              => self::productMediaSchema(),
            // Joined from commerce.inventory at read time, never stored on the product (AG-8).
            // Every field inside is nullable, and null means UNKNOWN rather than out of stock.
            'stock'              => self::stockSchema(),
            // NULLABLE: `commerce.products.published_at` is `TIMESTAMPTZ NULL` — a product that
            // has never been published carries no date. `updated_at` is NOT NULL DEFAULT NOW(),
            // so it stays a plain string.
            'published_at'       => [
                'type'        => ['string', 'null'],
                'description' => 'Publication timestamp, or null when the product has none.',
            ],
            'updated_at'         => 'string',
            'meta'               => self::openMapSchema(
                'Product meta the projection carries. Deliberately OPEN: the key set belongs to '
                . 'the store, not to the published contract.'
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Nested published shapes (ADR-055 (c))
    //
    // Written out EXPLICITLY, exactly as ProductResource / VariationResource build them.
    // Nothing here reflects over a Resource or infers a shape from a projection row
    // (ADR-055 (a)) — a nested field left as a bare `type: object` gives a generated consumer
    // type no way to read data the contract deliberately publishes.
    // -------------------------------------------------------------------------

    /**
     * The WooCommerce product id, shared by the product and variation contracts.
     *
     * WHY A WORDPRESS ID IS IN A PUBLIC CONTRACT AT ALL. HSP does not publish source ids so that
     * consumers can address HSP resources — addressing stays `/products/{slug}` and nothing here
     * changes it. This one is an INTEROPERABILITY identifier: WooCommerce remains the
     * transactional authority for cart, pricing, stock, tax, coupons, shipping and checkout, and
     * every native handoff it offers — `WC_Cart::add_to_cart()`, the `?add-to-cart=` form
     * handler, the Store API — is keyed on WordPress post ids. Without this value published under
     * a name that means it, a storefront browsing HSP has to GUESS that `source_id` happens to be
     * a Woo id, and a guess is not a contract.
     *
     * `minimum: 1` is a real guarantee, not decoration: `source_product_id` is `BIGINT NOT NULL`
     * with a UNIQUE constraint, and a product with no row has no response at all.
     *
     * Site-specific by nature. It identifies the entity on the CONNECTED WooCommerce store; it is
     * not federated, not globally unique, and carries no meaning against another site.
     *
     * @return array<string,mixed>
     */
    private static function wooProductIdSchema(string $description): array
    {
        return [
            'type'        => 'integer',
            'minimum'     => 1,
            'description' => $description . ' Site-specific: authoritative for the connected '
                . 'source WooCommerce site only, not a globally unique identifier.',
        ];
    }

    /**
     * Money, shared by products and variations.
     *
     * Every member is `string|null`, NEVER a JSON number: a number would hand consumers a binary
     * float for a decimal quantity, which is how 19.99 becomes 19.989999999999998 in a cart total
     * (Requirement C). Null means the store has not set that price — `sale_price` is null on most
     * products. All three keys are always present, so all three are `required`.
     *
     * @return array<string,mixed>
     */
    private static function pricesSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Exact decimal strings, never JSON numbers (Requirement C). A null '
                . 'member means the store has not set that price.',
            'properties'  => [
                'price'         => ['type' => ['string', 'null'], 'description' => 'Effective price.'],
                'regular_price' => ['type' => ['string', 'null']],
                'sale_price'    => ['type' => ['string', 'null']],
            ],
            'required'    => ['price', 'regular_price', 'sale_price'],
        ];
    }

    /**
     * Product media: attachment id REFERENCES only (AG-10). `content.media` remains the single
     * attachment projection, so a consumer resolves these against `/hsp/v1/media` — Commerce
     * does not duplicate attachment state and does not require the Content module to be active.
     *
     * `featured_id` is 0 when the product has no featured image (the Resource casts a missing id
     * to 0 rather than null), and `gallery_ids` is an empty list when there is no gallery.
     *
     * @return array<string,mixed>
     */
    private static function productMediaSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'WordPress attachment id references. Resolve them against the '
                . 'media endpoint, which owns attachment delivery; Commerce publishes the '
                . 'reference only.',
            'properties'  => [
                'featured_id' => [
                    'type'        => 'integer',
                    'description' => 'Featured attachment id; 0 when the product has none.',
                ],
                'gallery_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'Gallery attachment ids in store order; empty when there is no gallery.',
                ],
            ],
            'required'    => ['featured_id', 'gallery_ids'],
        ];
    }

    /**
     * Variation media: a single attachment id reference, with no gallery — WooCommerce gives a
     * variation one image, not a gallery (AG-10 as above).
     *
     * @return array<string,mixed>
     */
    private static function variationMediaSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'WordPress attachment id reference. Resolve it against the media '
                . 'endpoint, which owns attachment delivery.',
            'properties'  => [
                'featured_id' => [
                    'type'        => 'integer',
                    'description' => 'Variation image attachment id; 0 when the variation has none.',
                ],
            ],
            'required'    => ['featured_id'],
        ];
    }

    /**
     * Stock, joined from `commerce.inventory` at read time (AG-8).
     *
     * EVERY member is nullable and null means UNKNOWN — a product whose inventory row has not
     * projected yet — which is deliberately NOT the same as out of stock (AG-8 / AG-14). A
     * consumer that chooses to treat null as false is making its own call; the contract does not
     * make it for them, which is exactly why the nullability is published rather than smoothed
     * over. `quantity` is null for both "not tracked" and "not yet known"; `managed` tells them
     * apart. All four keys are always present.
     *
     * @return array<string,mixed>
     */
    private static function stockSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => 'Inventory joined at read time (AG-8). NULL means UNKNOWN — the '
                . 'inventory row has not projected — which is NOT the same as out of stock.',
            'properties'  => [
                'status'     => [
                    'type'        => ['string', 'null'],
                    'description' => "WooCommerce stock status, e.g. 'instock'. Null = unknown.",
                ],
                'managed'    => [
                    'type'        => ['boolean', 'null'],
                    'description' => 'Whether the store tracks quantity for this item. Null = unknown.',
                ],
                'quantity'   => [
                    'type'        => ['integer', 'null'],
                    'description' => 'Null for BOTH "not tracked" and "not yet known" — `managed` '
                        . 'is what tells them apart.',
                ],
                'backorders' => ['type' => ['string', 'null']],
            ],
            'required'    => ['status', 'managed', 'quantity', 'backorders'],
        ];
    }

    /**
     * A variation's selected attribute values: an OPEN map of attribute taxonomy name
     * (`pa_colour`) to the selected term slug.
     *
     * `additionalProperties` rather than a property list because attribute taxonomies are
     * DYNAMIC — an operator defines `pa_colour` whenever they like — so there is no closed key
     * set to publish. The VALUE type is platform-owned and therefore described.
     *
     * An EMPTY string value means "any value of this attribute", which is not the same as the
     * attribute being absent from the map; a consumer matching a shopper's selection needs both.
     *
     * @return array<string,mixed>
     */
    private static function variationAttributesSchema(): array
    {
        return [
            'type'                 => 'object',
            'description'          => 'Selected attribute values keyed by attribute taxonomy name '
                . '(e.g. pa_colour). The key set is store-defined. An EMPTY value means the '
                . 'variation matches ANY value of that attribute — not the same as the attribute '
                . 'being absent.',
            'additionalProperties' => ['type' => 'string'],
        ];
    }

    /**
     * An INTENTIONALLY OPAQUE map: the key set belongs to the store, not to the published
     * contract, so it is described as an open object rather than frozen into a closed property
     * list that would be wrong on the next store. Opaque by decision, not by omission.
     *
     * @return array<string,mixed>
     */
    private static function openMapSchema(string $description): array
    {
        return [
            'type'                 => 'object',
            'description'          => $description,
            'additionalProperties' => true,
        ];
    }
}
