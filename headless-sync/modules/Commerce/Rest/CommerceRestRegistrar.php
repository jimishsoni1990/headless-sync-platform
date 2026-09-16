<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Rest;

use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Delivery\CursorToken;
use HSP\Core\Rest\DeliveryErrorBoundary;
use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\TermFilterSet;
use HSP\Modules\Commerce\Queries\VariationFilterSet;
use HSP\Modules\Commerce\Support\Money;

/**
 * Registers the Commerce delivery routes on the `hsp/v1` namespace (DECISION N, Doc 9 §7).
 *
 *   GET /hsp/v1/products
 *   GET /hsp/v1/products/{slug}
 *   GET /hsp/v1/product-categories
 *   GET /hsp/v1/product-categories/{slug}
 *   GET /hsp/v1/product-attributes
 *   GET /hsp/v1/product-attributes/{taxonomy}
 *   GET /hsp/v1/product-attributes/{taxonomy}/terms
 *   GET /hsp/v1/products/{slug}/variations
 *
 * The category routes are namespaced `product-categories` rather than `categories`, which the
 * Content module already owns for WordPress post categories. Two different taxonomies in two
 * different domains must not contend for one route.
 *
 * Reads come from commerce.products only — no synchronous WordPress reads (Rule 6, ADR-040).
 *
 * WPCS at the WordPress boundary (DECISION V (b) / W (a)): every request parameter is
 * sanitized here, which is the untrusted edge. The routes are public reads, so
 * `permission_callback` is `__return_true` — matching the shipped content endpoints, whose
 * data is likewise already public.
 *
 * WHY EVERY CONSTRAINED ARG BELOW NAMES `rest_validate_request_arg` EXPLICITLY
 * (FLAG-RESTARGDRIFT-1). WordPress schema-validates a raw route arg through exactly one door:
 * `WP_REST_Request::sanitize_params()` installs the validating default `rest_parse_request_arg`
 * only when the arg has a `type` and NO `sanitize_callback` key. Declaring a sanitizer —
 * `absint`, `sanitize_text_field`, `sanitize_key` — REPLACES that default and silently switches
 * all schema validation off for that parameter. That is why `limit` accepted 0, -5, abc and 500
 * alike, and why `featured`/`in_stock`, the only two args here without a sanitizer, were the only
 * two that ever rejected anything.
 *
 * Naming the callback explicitly also fixes the ORDER: it runs in `has_valid_params()`, which
 * WP_REST_Server calls BEFORE `sanitize_params()`, so the raw value is judged before `absint`
 * can turn `abc` into 0 or `-5` into 5. And it cannot be disabled again by someone adding a
 * sanitizer later.
 *
 * Page-size maxima are per-route and deliberately NOT flattened to one number: products are
 * capped at 100, taxonomy/attribute/variation listings at 200, matching each query provider's
 * own ceiling and each descriptor's published contract.
 */
final class CommerceRestRegistrar
{
    private const NAMESPACE = 'hsp/v1';

    /**
     * Published page-size ceilings, per route family (FLAG-RESTARGDRIFT-1 A-2/A-3).
     *
     * Each matches the MAX_LIMIT its query provider already clamps to and the maximum its
     * EndpointDescriptor already published in prose. They are NOT flattened to one platform-wide
     * number: a product page and a term page have different costs, and the published contracts
     * differ accordingly.
     */
    private const PRODUCT_MAX_LIMIT = 100;

    /** Terms, attribute definitions, attribute terms and variations all publish 200. */
    private const TERM_MAX_LIMIT = 200;

    /**
     * The addressing grammars of the path parameters, published so the contract stops implying
     * that any string is addressable (FLAG-RESTARGDRIFT-1 D-5).
     *
     * Identical to the character classes in the route regexes, which is what ENFORCES them —
     * structurally, at dispatch — so publishing them creates no new 400. The ADR-055 parameter
     * drift guard compares each against the live route's own capture group.
     */
    private const SLUG_PATTERN = '^[a-z0-9_-]+$';

    /** The full taxonomy name (`pa_colour`), so `_` is part of the identifier. */
    private const TAXONOMY_PATTERN = '^[a-z0-9_-]+$';

    /**
     * @param \Closure(string): QueryProviderInterface $attributeTermQueryFactory Builds a term
     *        provider scoped to ONE `pa_*` taxonomy. A factory rather than a container binding
     *        because attribute taxonomies are DYNAMIC — an operator defines `pa_colour`
     *        whenever they like — so there is no fixed set to bind at composition time.
     */
    public function __construct(
        private readonly QueryProviderInterface $productQueryProvider,
        private readonly ResourceInterface $productResource,
        private readonly QueryProviderInterface $categoryQueryProvider,
        private readonly ResourceInterface $termResource,
        private readonly QueryProviderInterface $attributeQueryProvider,
        private readonly ResourceInterface $attributeResource,
        private readonly \Closure $attributeTermQueryFactory,
        // A SEPARATE shape from `$termResource`, not the same one reused: `pa_*` terms are flat
        // and slug-selected, product categories are hierarchical (FLAG-COMMSOURCEID-1).
        private readonly ResourceInterface $attributeTermResource,
        private readonly QueryProviderInterface $variationQueryProvider,
        private readonly ResourceInterface $variationResource,
        // CCF-003: every callback is registered through this boundary, so a Throwable escaping a
        // handler becomes the documented 500 envelope rather than an HTML fatal page. Injected,
        // never reached statically (ADR-012 / Rule 7).
        private readonly DeliveryErrorBoundary $errorBoundary,
    ) {
    }

    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::NAMESPACE, '/products', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleProductListing(...)),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/products/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleProductSingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'pattern'           => self::SLUG_PATTERN,
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/product-categories', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleCategoryListing(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'cursor' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'limit'  => [
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => self::TERM_MAX_LIMIT,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
                // No `parent` arg: the `?parent={source-term-id}` filter was Removed
                // (FLAG-COMMCATPARENT-1). WordPress ignores an undeclared query key, so an old
                // caller still sending it gets the full listing — not a supported filter.
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/product-categories/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleCategorySingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'pattern'           => self::SLUG_PATTERN,
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/product-attributes', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleAttributeListing(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'cursor' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'limit'  => [
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => self::TERM_MAX_LIMIT,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        // The path segment is the FULL taxonomy name (`pa_colour`), so underscores are part of
        // the identifier and `sanitize_key` — not `sanitize_title` — is the matching sanitizer.
        register_rest_route(self::NAMESPACE, '/product-attributes/(?P<taxonomy>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleAttributeSingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'taxonomy' => [
                    'required'          => true,
                    'type'              => 'string',
                    'pattern'           => self::TAXONOMY_PATTERN,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/product-attributes/(?P<taxonomy>[a-z0-9_-]+)/terms', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleAttributeTermListing(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'taxonomy' => [
                    'required'          => true,
                    'type'              => 'string',
                    'pattern'           => self::TAXONOMY_PATTERN,
                    'sanitize_callback' => 'sanitize_key',
                ],
                'cursor'   => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'limit'    => [
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => self::TERM_MAX_LIMIT,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/products/(?P<slug>[a-z0-9_-]+)/variations', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleVariationListing(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug'   => [
                    'required'          => true,
                    'type'              => 'string',
                    'pattern'           => self::SLUG_PATTERN,
                    'sanitize_callback' => 'sanitize_title',
                ],
                'cursor' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'limit'  => [
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => self::TERM_MAX_LIMIT,
                    'validate_callback' => 'rest_validate_request_arg',
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * The variations of one product.
     *
     * Nested under the product deliberately: WooCommerce gives a variation no permalink and no
     * independent catalogue presence, so there is no top-level resource to expose. The parent is
     * resolved through the SINGLE-product path rather than the catalog-scoped listing, matching
     * how the parent itself is addressed — a product hidden from the catalog is still reachable
     * at its own URL, so its variations must be too (Requirement B).
     *
     * @param \WP_REST_Request<array<string,mixed>>|object $request
     */
    public function handleVariationListing(object $request): mixed
    {
        // Variations sort on menu_order, so their cursor carries `o`, not `s`.
        $cursorError = $this->validateCursor($request, CursorToken::SORT_INTEGER, 'o');
        if ($cursorError !== null) {
            return $cursorError;
        }

        $product = $this->productQueryProvider->findBySlug(
            (string) ($this->param($request, 'slug') ?? '')
        );

        if ($product === null) {
            return $this->notFound();
        }

        $page = $this->variationQueryProvider->list(new VariationFilterSet(
            parentSourceId: (int) ($product['source_product_id'] ?? 0),
            cursor:         $this->param($request, 'cursor'),
            limit:          $this->intParam($request, 'limit'),
        ));

        return $this->respond($this->variationResource->toCollection($page->rows, $page->nextCursor));
    }

    /** @param \WP_REST_Request<array<string,mixed>>|object $request */
    public function handleAttributeListing(object $request): mixed
    {
        $cursorError = $this->validateCursor($request, CursorToken::SORT_TEXT);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $page = $this->attributeQueryProvider->list(new TermFilterSet(
            cursor: $this->param($request, 'cursor'),
            limit:  $this->intParam($request, 'limit'),
        ));

        return $this->respond($this->attributeResource->toCollection($page->rows, $page->nextCursor));
    }

    /** @param \WP_REST_Request<array<string,mixed>>|object $request */
    public function handleAttributeSingle(object $request): mixed
    {
        $row = $this->attributeQueryProvider->findBySlug(
            (string) ($this->param($request, 'taxonomy') ?? '')
        );

        if ($row === null) {
            return $this->notFound();
        }

        return $this->respond($this->attributeResource->toArray($row));
    }

    /**
     * The terms of ONE global attribute.
     *
     * The taxonomy is checked against the `pa_` prefix BEFORE any provider is built. Without
     * that guard `/product-attributes/product_cat/terms` would serve product categories through
     * the attribute route — the shared-table leak DECISION AA exists to prevent, arriving
     * through the front door as a path parameter rather than as a forgotten predicate.
     *
     * @param \WP_REST_Request<array<string,mixed>>|object $request
     */
    public function handleAttributeTermListing(object $request): mixed
    {
        $cursorError = $this->validateCursor($request, CursorToken::SORT_TEXT);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $taxonomy = (string) ($this->param($request, 'taxonomy') ?? '');

        if (! CommerceTaxonomies::isAttributeTaxonomy($taxonomy)) {
            return $this->notFound();
        }

        $provider = ($this->attributeTermQueryFactory)($taxonomy);

        $page = $provider->list(new TermFilterSet(
            cursor: $this->param($request, 'cursor'),
            limit:  $this->intParam($request, 'limit'),
        ));

        return $this->respond($this->attributeTermResource->toCollection($page->rows, $page->nextCursor));
    }

    /** @param \WP_REST_Request<array<string,mixed>>|object $request */
    public function handleCategoryListing(object $request): mixed
    {
        $cursorError = $this->validateCursor($request, CursorToken::SORT_TEXT);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $page = $this->categoryQueryProvider->list(new TermFilterSet(
            cursor: $this->param($request, 'cursor'),
            limit:  $this->intParam($request, 'limit'),
        ));

        return $this->respond($this->termResource->toCollection($page->rows, $page->nextCursor));
    }

    /** @param \WP_REST_Request<array<string,mixed>>|object $request */
    public function handleCategorySingle(object $request): mixed
    {
        $row = $this->categoryQueryProvider->findBySlug((string) ($this->param($request, 'slug') ?? ''));

        if ($row === null) {
            return $this->notFound();
        }

        return $this->respond($this->termResource->toArray($row));
    }

    /** @param \WP_REST_Request<array<string,mixed>>|object $request */
    public function handleProductListing(object $request): mixed
    {
        $cursorError = $this->validateCursor($request, CursorToken::SORT_TEMPORAL);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $minPrice = $this->priceParam($request, 'min_price');
        if ($minPrice instanceof \WP_Error) {
            return $minPrice;
        }

        $maxPrice = $this->priceParam($request, 'max_price');
        if ($maxPrice instanceof \WP_Error) {
            return $maxPrice;
        }

        $filters = new ProductFilterSet(
            sku:         $this->param($request, 'sku'),
            productType: $this->param($request, 'type'),
            featured:    $this->boolParam($request, 'featured'),
            minPrice:    $minPrice,
            maxPrice:    $maxPrice,
            // Always catalog-scoped: a public listing must not expose a product WooCommerce
            // excludes from the catalog, and that must not be defeatable by a query parameter
            // (Requirement B).
            catalogOnly: true,
            categorySlug: $this->param($request, 'category'),
            attributeTaxonomy: $this->attributeTaxonomyParam($request),
            attributeTermSlug: $this->param($request, 'attribute_term'),
            inStock:     $this->boolParam($request, 'in_stock'),
            cursor:      $this->param($request, 'cursor'),
            limit:       $this->intParam($request, 'limit'),
        );

        $page = $this->productQueryProvider->list($filters);

        return $this->respond($this->productResource->toCollection($page->rows, $page->nextCursor));
    }

    /** @param \WP_REST_Request<array<string,mixed>>|object $request */
    public function handleProductSingle(object $request): mixed
    {
        $slug = (string) ($this->param($request, 'slug') ?? '');
        $row  = $this->productQueryProvider->findBySlug($slug);

        if ($row === null) {
            return $this->notFound();
        }

        return $this->respond($this->productResource->toArray($row));
    }

    /** @return array<string, array<string,mixed>> */
    private function listingArgs(): array
    {
        return [
            'cursor'    => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'limit'     => [
                'type'              => 'integer',
                'minimum'           => 1,
                'maximum'           => self::PRODUCT_MAX_LIMIT,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
            'sku'       => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            // Phase 2 projects `simple` and `variable` only (AG-13), and the enum comes from the
            // module's own ProductScope rather than a second literal list. `?type=bogus` used to
            // answer 200 with an empty listing — a filter for a type the platform does not
            // project, reported as a successful search.
            'type'      => [
                'type'              => 'string',
                'enum'              => ProductScope::SUPPORTED_TYPES,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'sanitize_key',
            ],
            // The two args that were already validated, by accident: no sanitize_callback meant
            // WordPress installed its validating default. Named explicitly now so adding a
            // sanitizer here can never silently turn that off. Behaviour is unchanged.
            'featured'  => ['type' => 'boolean', 'validate_callback' => 'rest_validate_request_arg'],
            // Prices stay `type: string` and are validated by Money's exact-decimal contract in
            // priceParam() — an exact-decimal grammar is not expressible as a useful public
            // constraint, and generic validation here would replace the CCF-003 stable code
            // `hsp_invalid_filter` (FLAG-RESTARGDRIFT-1 D-2).
            'min_price' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'max_price' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'category'  => ['type' => 'string',  'sanitize_callback' => 'sanitize_title'],
            'attribute'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            'attribute_term' => ['type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            'in_stock'       => ['type' => 'boolean', 'validate_callback' => 'rest_validate_request_arg'],
        ];
    }

    /**
     * The `attribute` query parameter, accepted only when it names a `pa_*` taxonomy.
     *
     * Anything else becomes null, which drops the filter rather than applying it to another
     * taxonomy — `?attribute=product_cat&attribute_term=shoes` must not quietly become a
     * category filter wearing an attribute's name.
     */
    private function attributeTaxonomyParam(object $request): ?string
    {
        $taxonomy = $this->param($request, 'attribute');

        if ($taxonomy === null || ! CommerceTaxonomies::isAttributeTaxonomy($taxonomy)) {
            return null;
        }

        return $taxonomy;
    }

    /**
     * Validate ?cursor= against THIS endpoint's cursor contract (CCF-003).
     *
     * Two defects closed at once. An invalid cursor used to be dropped silently and the listing
     * restarted at page 1 — an opaque token that fails validation must never quietly mean "start
     * from the beginning", because the consumer then sees rows it has already paged past. And a
     * payload that decoded but was semantically wrong (`{"s":"notadate",…}`) reached
     * `$n::timestamptz` in SQL, raising an uncaught DatabaseException that WordPress rendered as an
     * HTML 500 with a stack trace and filesystem paths.
     *
     * Content already returned 400 here; Commerce now matches it, using the same code and the same
     * envelope. The sort kind and key are supplied per endpoint: products sort on published_at,
     * taxonomies and attributes on name, variations on menu_order under the key `o`.
     *
     * @param \WP_REST_Request<array<string,mixed>>|object $request
     * @param string $sortKind one of the CursorToken::SORT_* constants
     * @param string $sortKey  the payload key holding the primary sort value
     */
    private function validateCursor(object $request, string $sortKind, string $sortKey = 's'): mixed
    {
        $raw = $this->param($request, 'cursor');

        if ($raw === null || CursorToken::isValid($raw, $sortKind, $sortKey)) {
            return null;
        }

        return $this->error('hsp_invalid_cursor', 'Invalid cursor token.', 400);
    }

    /**
     * A price-bound query parameter, as an EXACT decimal string (CCF-003).
     *
     * `?min_price=abc` used to be handed straight to PostgreSQL, where the NUMERIC cast threw and
     * the request ended as an HTML 500 disclosing the SQL, the stack and the plugin path. It is
     * now rejected as a 400 before any query runs.
     *
     * Validation and normalisation both go through Money — the SAME exact-decimal contract the
     * projection and checksum use (DECISION AG Requirement C). Nothing here parses the value as a
     * PHP float: `(float) '0.1'` is not 0.1, and a price bound that silently shifts is worse than
     * one that is refused. `NaN`, `INF`, `1e5` and every other non-decimal representation fail the
     * same way, because Money cannot represent them exactly.
     *
     * No new filter semantics are introduced — in particular min ≤ max is NOT asserted, since the
     * approved Commerce filter contract does not contain that rule.
     *
     * @param \WP_REST_Request<array<string,mixed>>|object $request
     * @return string|\WP_Error|null the exact decimal string, a 400, or null when absent
     */
    private function priceParam(object $request, string $key): mixed
    {
        $raw = $this->param($request, $key);

        if ($raw === null) {
            return null;
        }

        $normalized = Money::normalize($raw);

        if ($normalized === null) {
            return $this->error(
                'hsp_invalid_filter',
                sprintf('Invalid %s: expected an exact decimal value.', $key),
                400
            );
        }

        return $normalized;
    }

    private function param(object $request, string $key): ?string
    {
        if (! method_exists($request, 'get_param')) {
            return null;
        }

        $value = $request->get_param($key);

        return $value === null || $value === '' ? null : (string) $value;
    }

    private function intParam(object $request, string $key): ?int
    {
        $value = $this->param($request, $key);

        return $value === null ? null : (int) $value;
    }

    private function boolParam(object $request, string $key): ?bool
    {
        $value = $this->param($request, $key);

        if ($value === null) {
            return null;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes'], true);
    }

    private function respond(mixed $data): mixed
    {
        return function_exists('rest_ensure_response') ? rest_ensure_response($data) : $data;
    }

    private function notFound(): mixed
    {
        return $this->error('hsp_not_found', 'Resource not found.', 404);
    }

    /**
     * One WP_Error factory for every Commerce application error, so the envelope
     * (`{code, message, data.status}`) is produced in exactly one place in this module.
     * The 500 representation is NOT here — that belongs to the Core DeliveryErrorBoundary,
     * so no module invents its own (CCF-003).
     */
    private function error(string $code, string $message, int $status): mixed
    {
        if (class_exists(\WP_Error::class)) {
            return new \WP_Error($code, $message, ['status' => $status]);
        }

        return null;
    }
}
