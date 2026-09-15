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
                . 'independent catalogue presence.'
                . "\n\n"
                . 'RESOLVING A SHOPPER\'S SELECTION TO ONE VARIATION — TWO STAGES, AND THE FIRST '
                . 'IS NOT OPTIONAL.'
                . "\n\n"
                . 'STAGE 1 — CHECK THE CAPABILITY. Read `variation_selection_supported` on the '
                . 'PARENT product. If it is FALSE, STOP: do not resolve a variation from HSP data '
                . 'for this product. Its published selections are missing a variation-defining '
                . 'dimension that HSP does not model, so matching would not merely be '
                . 'incomplete — it would return a confident wrong answer, either picking one of '
                . 'several indistinguishable variations or matching a combination the store does '
                . 'not sell. Hand selection to WooCommerce instead. Everything else about the '
                . 'product remains usable. If it is TRUE, continue to stage 2.'
                . "\n\n"
                . 'STAGE 2 — MATCH. A selection is a map of attribute taxonomy name to term slug, '
                . 'e.g. {"pa_colour":"blue","pa_size":"large"}. '
                . 'It is a MAP, not an ordered list: key order carries no meaning. Apply, in order:'
                . "\n"
                . '(1) CANDIDATES — variations in this listing whose `status` is "publish", and no '
                . 'others. (2) ORDER them by `menu_order` ascending, then `woo_variation_id` '
                . 'ascending. (3) MATCH: a candidate matches when EVERY entry of its own '
                . '`attributes` map is satisfied — an empty value is a wildcard and is always '
                . 'satisfied; a non-empty value requires the selection to carry that key with '
                . 'exactly that slug. Keys in the selection that a candidate does not constrain '
                . 'are ignored. (4) RESOLVE to the FIRST matching candidate in the step-2 order, '
                . 'and use its `woo_variation_id` together with `woo_product_id` for the '
                . 'WooCommerce handoff. (5) NO MATCH means the store does not sell that '
                . 'combination — do not fall back to a nearest or first variation.'
                . "\n\n"
                . 'AMBIGUITY IS POSSIBLE AND IS NOT AN ERROR. Overlapping wildcards let a fully '
                . 'specified selection match more than one variation. Step 4 is exactly what '
                . 'WooCommerce\'s cart does, so following it reproduces the store\'s own answer; '
                . 'WooCommerce itself counts the matches where it needs certainty (its structured '
                . 'data treats more than one match as an ambiguous price rather than choosing). A '
                . 'consumer that needs the same certainty should count matches and treat more than '
                . 'one as ambiguous.'
                . "\n\n"
                . 'BUILDING THE SELECTOR. The dimensions are the KEYS of any candidate\'s '
                . '`attributes` map — complete and identical across a product\'s variations. The '
                . 'selectable VALUES for a dimension are the non-empty values across candidates, '
                . 'except where any candidate carries a wildcard for it, in which case the values '
                . 'and their labels come from the parent product\'s `attributes` field — which is '
                . 'why that field exists and why the store-wide term listing is not a substitute.'
                . "\n\n"
                . 'SCOPE LIMIT, AND HOW TO DETECT IT. Only global (pa_*) WooCommerce attributes '
                . 'are projected in this phase. A product that varies by a local/custom attribute '
                . 'publishes a selection missing that dimension — and that is precisely what '
                . '`variation_selection_supported: false` on the parent product tells you, so it '
                . 'never has to be guessed or inferred from the data. The `attributes` maps below '
                . 'stay published for such a product and remain accurate as far as they go; they '
                . 'are simply not complete enough to IDENTIFY a variation.'
                . "\n\n"
                . 'Availability, price and stock are validated by WooCommerce at add-to-cart time; '
                . 'this contract identifies WHICH variation a selection means, not whether it can '
                . 'currently be bought.',
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
            // LOAD-BEARING for variation selection, not decoration. WooCommerce's own resolver
            // queries `post_status = 'publish'` only, so a non-publish variation must not be a
            // selection candidate even though it is published here.
            'status'           => [
                'type'        => 'string',
                'description' => 'WordPress post status of the variation, e.g. "publish" or '
                    . '"private". ONLY variations with status "publish" participate in variation '
                    . 'selection: WooCommerce\'s own resolver considers no other status, so '
                    . 'matching against one would resolve to a variation the store will refuse.',
            ],
            // Exact decimal strings, never JSON numbers (Requirement C).
            'prices'           => self::pricesSchema(),
            // The selection pattern. See variationAttributesSchema() and the endpoint
            // description, which together carry the whole matching rule.
            'attributes'       => self::variationAttributesSchema(),
            // Attachment id reference; content.media owns the projection (AG-10).
            'media'            => self::variationMediaSchema(),
            // Also load-bearing: it is the FIRST key of WooCommerce's resolution order.
            'menu_order'       => [
                'type'        => 'integer',
                'description' => "The store's own ordering of this variation within its parent. "
                    . 'Render a picker in this order, and use it as the primary tiebreak when '
                    . 'more than one variation matches a selection: WooCommerce resolves by '
                    . 'menu_order ascending, then woo_variation_id ascending, first match wins.',
            ],
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
            // The SOURCE count, projected verbatim — not a Delivery API result count and not a
            // pagination total (FLAG-COMMTERMCOUNT-1). It shipped with no description at all,
            // which is the ambiguity the flag named; the semantic itself is unchanged.
            'count'       => [
                'type'        => 'integer',
                'description' => 'Number of published products this term is DIRECTLY assigned '
                    . 'to, as counted by WordPress/WooCommerce itself and projected verbatim. '
                    . 'It follows the store\'s own membership and product status changes. '
                    . 'Products in a child category are NOT rolled up into the parent. It is '
                    . 'NOT the number of results a Delivery API listing returns and NOT a '
                    . 'pagination total: a product hidden from the catalog, or of a product '
                    . 'type outside Phase 2 support, still counts here.',
            ],
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
            // DECISION AL. Present on VARIABLE products only — hence absent from `required`,
            // which this schema does not declare at all, so an optional field is representable
            // without polymorphic machinery.
            'variation_selection_supported' => self::variationSelectionSupportedSchema(),
            // The product half of the variation-selection contract (Finding 010). Read-time
            // composition from the relationships the product already projects: no column, no
            // migration, no duplicated term state.
            'attributes'         => self::productAttributeTermsSchema(),
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
     * Product media: resolved image objects, plus the attachment ids that were already public.
     *
     * `featured` and `gallery` are what a consumer renders from — they carry a URL and its
     * dimensions, so no id lookup, no second request and no knowledge of WordPress attachments
     * is needed to show a product image (Finding 011). `featured_id` / `gallery_ids` remain
     * because they were already published; they are the stored source references, not the
     * rendering contract.
     *
     * `content.media` is still the single attachment projection (AG-10) — the objects here are
     * composed at read time through the Core media capability, never copied into Commerce. That
     * is why an image edited in WordPress appears here without the product being re-saved.
     *
     * @return array<string,mixed>
     */
    private static function productMediaSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => "The product's images. `featured` and `gallery` are resolved and "
                . 'directly renderable. `featured_id` and `gallery_ids` are the underlying '
                . 'WordPress attachment ids, kept for compatibility — a consumer does not need '
                . 'them to display an image.',
            // Order matches ProductResource's published key order — the drift guard compares the
            // two exactly, so the descriptor is the response's shape and not merely its vocabulary.
            'properties'  => [
                'featured_id' => [
                    'type'        => 'integer',
                    'description' => 'WordPress attachment id of the main image; 0 when the '
                        . 'product has none. A source reference, not required for rendering.',
                ],
                'gallery_ids' => [
                    'type'        => 'array',
                    'items'       => ['type' => 'integer'],
                    'description' => 'WordPress attachment ids of the gallery, in store order; '
                        . 'empty when there is no gallery. Source references, not required for '
                        . 'rendering.',
                ],
                'featured'    => self::resolvedMediaSchema(
                    "The product's main image, or null. Null when no image is set, when the "
                    . 'attachment has not been projected yet, when it has been deleted, or when '
                    . 'the media resolution capability is unavailable — a consumer treats all '
                    . 'four the same way, by rendering no image.'
                ),
                'gallery'     => [
                    'type'        => 'array',
                    'items'       => self::resolvedMediaSchema(null),
                    'description' => 'The gallery images, resolved, in the order the store '
                        . 'arranged them. Always an array — empty when the product has no '
                        . 'gallery, never null. A reference that cannot currently be resolved '
                        . '(not yet projected, or deleted) is OMITTED rather than published as '
                        . 'null, and the remaining images keep their relative order, so this '
                        . 'list can be shorter than `gallery_ids`.',
                ],
            ],
            'required'    => ['featured_id', 'gallery_ids', 'featured', 'gallery'],
        ];
    }

    /**
     * Variation media: one resolved image and its reference, with no gallery — WooCommerce gives
     * a variation a single image, not a gallery (AG-10 as above).
     *
     * @return array<string,mixed>
     */
    private static function variationMediaSchema(): array
    {
        return [
            'type'        => 'object',
            'description' => "The image explicitly assigned to this variation. Does NOT include "
                . "the parent product's image: a variation with no image of its own publishes "
                . 'nothing here rather than inheriting.',
            'properties'  => [
                'featured_id' => [
                    'type'        => 'integer',
                    'description' => 'WooCommerce attachment id explicitly assigned to this '
                        . 'variation. Zero when the variation has no variation-specific image. '
                        . 'Parent-product image fallback is not included. A source reference, '
                        . 'not required for rendering.',
                ],
                'featured'    => self::resolvedMediaSchema(
                    'Resolved media for the image explicitly assigned to this variation. Null '
                    . 'when no variation-specific image is assigned, when the attachment has '
                    . 'not been projected or has been deleted, or when the media resolution '
                    . 'capability is unavailable.'
                ),
            ],
            'required'    => ['featured_id', 'featured'],
        ];
    }

    /**
     * ONE resolved-image shape, published wherever Commerce publishes an image.
     *
     * The same seven fields the Content module publishes as a post's `featured_media`, because
     * they are the same attachment resolved through the same capability — one platform
     * representation of an image rather than a Commerce dialect of it. Pinned by a test against
     * the Content descriptor so the two cannot drift apart silently.
     *
     * Inline rather than a `$ref`: ADR-055 builds schemas inline throughout, and introducing a
     * components/$ref framework for one shared fragment is a larger change than the fragment.
     *
     * @return array<string,mixed>
     */
    private static function resolvedMediaSchema(?string $description): array
    {
        $schema = [
            'type'       => $description === null ? 'object' : ['object', 'null'],
            'properties' => [
                'slug'      => ['type' => 'string'],
                'url'       => [
                    'type'        => 'string',
                    'description' => 'Absolute URL of the full-size image.',
                ],
                'alt_text'  => [
                    'type'        => 'string',
                    'description' => 'Alternative text as entered in WordPress. Empty when none '
                        . 'was entered — HSP does not substitute the product name; supplying a '
                        . 'fallback is a presentation decision for the consumer.',
                ],
                'mime_type' => ['type' => 'string'],
                'width'     => ['type' => 'integer', 'description' => 'Pixel width of the full-size image.'],
                'height'    => ['type' => 'integer', 'description' => 'Pixel height of the full-size image.'],
                'sizes'     => self::mediaSizesSchema(),
            ],
            'required'   => ['slug', 'url', 'alt_text', 'mime_type', 'width', 'height', 'sizes'],
        ];

        if ($description !== null) {
            $schema['description'] = $description;
        }

        return $schema;
    }

    /**
     * The generated thumbnail set, keyed by WordPress size name.
     *
     * Deliberately OPEN: the registered sizes belong to the site's theme and plugins — a store
     * with WooCommerce adds `woocommerce_thumbnail` and friends — so the key set is not part of
     * the published contract, while each value's shape is.
     *
     * @return array<string,mixed>
     */
    private static function mediaSizesSchema(): array
    {
        return [
            'type'                 => 'object',
            'description'          => 'Generated image sizes keyed by WordPress size name '
                . '(`thumbnail`, `medium`, `woocommerce_thumbnail`, …). The key set belongs to '
                . 'the site, not to the contract; an image with no generated sizes is an empty '
                . 'object.',
            'additionalProperties' => [
                'type'       => 'object',
                'properties' => [
                    'url'       => ['type' => 'string'],
                    'width'     => ['type' => 'integer'],
                    'height'    => ['type' => 'integer'],
                    'mime_type' => ['type' => 'string'],
                ],
                'required'   => ['url', 'width', 'height', 'mime_type'],
            ],
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
     * A variation's SELECTION PATTERN: an OPEN map of attribute taxonomy name (`pa_colour`) to
     * the selected term slug in that taxonomy.
     *
     * `additionalProperties` rather than a property list because attribute taxonomies are
     * DYNAMIC — an operator defines `pa_colour` whenever they like — so there is no closed key
     * set to publish. The VALUE type is platform-owned and therefore described.
     *
     * THE THREE FACTS A GENERATED CLIENT CANNOT GUESS, so all three are stated in the
     * description rather than left to whoever wrote the PHP:
     *
     *   KEY   — the full attribute taxonomy name, `pa_`-prefixed, the same string
     *           `/product-attributes` publishes as `taxonomy` and `?attribute=` accepts. Not a
     *           label, not `attribute_pa_colour` as WooCommerce stores it in meta, not an id.
     *   VALUE — the TERM SLUG within that taxonomy, the same string `?attribute_term=` accepts.
     *           Not a name, not a term id. Slugs are unique only WITHIN a taxonomy, so
     *           `pa_colour: "large"` and `pa_size: "large"` are different values and the key is
     *           part of the identity.
     *   ""    — WILDCARD. The variation accepts ANY value of that attribute. Verified against
     *           WooCommerce 11.1.0 (`wc_get_product_variation_attributes()`,
     *           wc-product-functions.php:1208: "Add it - 'any' will be assumed"). An empty value
     *           is NOT the attribute being absent and NOT an empty slug.
     *
     * The key set is COMPLETE over the parent's variation-defining global attributes and
     * identical across that product's variations — WooCommerce fills in every parent attribute
     * and strips any that the parent no longer varies by — so the dimensions of a product's
     * selector are the keys of this map. The one stated exception is the scope limit AG-9 sets:
     * Phase 2 projects GLOBAL (`pa_*`) attributes only, so a product that varies by a
     * local/custom attribute publishes a selection that is missing that dimension.
     *
     * @return array<string,mixed>
     */
    private static function variationAttributesSchema(): array
    {
        return [
            'type'                 => 'object',
            'description'          => 'This variation\'s SELECTION PATTERN — what a shopper must '
                . 'choose to land on it. KEY: the full attribute taxonomy name, pa_-prefixed '
                . '(e.g. pa_colour) — the same value /product-attributes publishes as `taxonomy`. '
                . 'VALUE: the TERM SLUG within that taxonomy (e.g. "blue") — never a label and '
                . 'never an id; slugs repeat across taxonomies, so the key is part of the value\'s '
                . 'identity. EMPTY STRING: a WILDCARD — this variation matches ANY value of that '
                . 'attribute, which is NOT the same as the attribute being absent from the map. '
                . 'The key set is store-defined, complete over the parent\'s variation-defining '
                . 'global attributes, and the same for every variation of one product — so these '
                . 'keys are the product\'s selector dimensions, BUT ONLY when the parent product '
                . 'publishes variation_selection_supported: true. When that flag is false the '
                . 'store varies this product by a dimension outside the supported global (pa_*) '
                . 'model, the map below is missing it, and these entries must NOT be used to '
                . 'identify a variation. See the endpoint description for the two-stage rule.',
            'additionalProperties' => ['type' => 'string'],
        ];
    }

    /**
     * DECISION AL — whether this product's variation-defining state is fully representable by
     * the supported global `pa_*` attribute model.
     *
     * The gate on everything else in the selection contract. WooCommerce lets a product vary by
     * a local/custom attribute, which AG-9 keeps out of Phase 2; its variations then publish a
     * selection missing that dimension, and the failure mode is not a gap a consumer notices —
     * two variations look identical and one is silently resolved instead of the other, or a
     * combination the store does not sell resolves to a real variation. A boolean the consumer
     * can branch on is the whole fix.
     *
     * PRESENT ONLY ON A VARIABLE PRODUCT. A simple product has nothing to select, and `true`
     * there would read as "selection works" while `false` would read as a defect; AK-8 answered
     * the same question the same way for `woo_variation_id`.
     *
     * NOT a general "product supported" flag. A `false` product is a perfectly normal catalogue
     * entry — listed, addressable, with media, prices, descriptions and its Woo handoff id.
     * Exactly one capability is withheld.
     *
     * @return array<string,mixed>
     */
    private static function variationSelectionSupportedSchema(): array
    {
        return [
            'type'        => 'boolean',
            'description' => 'Whether this variable product\'s COMPLETE variation-defining state '
                . 'is representable by HSP\'s supported global WooCommerce attribute (pa_*) '
                . 'model. TRUE: the published Product and Variation data is sufficient to resolve '
                . 'a complete selection to exactly one variation — apply the algorithm in the '
                . 'variations endpoint description. FALSE: this product varies by state outside '
                . 'the supported model (a local/custom WooCommerce attribute), or varies by '
                . 'nothing at all, so the published variation selections are incomplete and a '
                . 'consumer MUST NOT resolve a variation from HSP data alone — a selection may '
                . 'match several variations, or confidently match one the store does not sell; '
                . 'fall back to WooCommerce for selection on this product. PRESENT ONLY on '
                . 'products whose `type` is "variable"; absent otherwise, because a product with '
                . 'no variations has no selection semantics to describe. This flag is about '
                . 'variation selection ONLY: a false product remains fully supported for catalog '
                . 'listing, addressing, media, prices, descriptive data and its woo_product_id '
                . 'handoff identity.',
        ];
    }

    /**
     * The product's own `pa_*` terms, keyed by taxonomy — the values its selector may offer.
     *
     * Separate from a variation's selection pattern and answering a different question. A
     * variation says WHICH value it is; this says WHICH VALUES EXIST for this product. The two
     * are only interchangeable when no variation uses a wildcard, and wildcards are ordinary:
     * every variation of the reference store's v-neck tee carries `pa_size: ""`, so the sizes on
     * offer appear nowhere in the variation list.
     *
     * Deliberately NOT a statement of which attributes are selectors — a store may attach an
     * attribute for display only, and this map cannot tell the two apart. The variation key set
     * answers that, and the description says so rather than letting a consumer assume.
     *
     * @return array<string,mixed>
     */
    private static function productAttributeTermsSchema(): array
    {
        return [
            'type'                 => 'object',
            'description'          => 'The global attribute terms THIS product carries, keyed by '
                . 'attribute taxonomy name (e.g. pa_colour), each a list of {slug, name} ordered '
                . 'by slug. `slug` is the machine value — the same string a variation\'s '
                . '`attributes` map holds and `?attribute_term=` accepts; `name` is the display '
                . 'label. Use this for a variable product\'s selectable OPTIONS, especially where '
                . 'a variation publishes an empty (wildcard) value and therefore names none. It '
                . 'is product-specific and narrower than /product-attributes/{taxonomy}/terms, '
                . 'which lists every term in the store. It does NOT say which attributes the '
                . 'product varies BY — a store may attach an attribute for display only; the keys '
                . 'of a variation\'s `attributes` map are the selector dimensions. Empty object '
                . 'when the product carries no global attribute terms.',
            'additionalProperties' => [
                'type'  => 'array',
                'items' => [
                    'type'       => 'object',
                    'properties' => [
                        'slug' => [
                            'type'        => 'string',
                            'description' => 'Machine value; matches a variation attribute value.',
                        ],
                        'name' => ['type' => 'string', 'description' => 'Display label.'],
                    ],
                    'required'   => ['slug', 'name'],
                ],
            ],
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
