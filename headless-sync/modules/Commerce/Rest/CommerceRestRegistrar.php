<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Rest;

use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Delivery\CursorToken;
use HSP\Core\Rest\DeliveryErrorBoundary;
use HSP\Modules\Commerce\CommerceTaxonomies;
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
 */
final class CommerceRestRegistrar
{
    private const NAMESPACE = 'hsp/v1';

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
                'limit'  => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'parent' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
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
                'limit'  => ['type' => 'integer', 'sanitize_callback' => 'absint'],
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
                    'sanitize_callback' => 'sanitize_key',
                ],
                'cursor'   => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'limit'    => ['type' => 'integer', 'sanitize_callback' => 'absint'],
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
                    'sanitize_callback' => 'sanitize_title',
                ],
                'cursor' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'limit'  => ['type' => 'integer', 'sanitize_callback' => 'absint'],
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

        return $this->respond($this->termResource->toCollection($page->rows, $page->nextCursor));
    }

    /** @param \WP_REST_Request<array<string,mixed>>|object $request */
    public function handleCategoryListing(object $request): mixed
    {
        $cursorError = $this->validateCursor($request, CursorToken::SORT_TEXT);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $parent = $this->param($request, 'parent');

        $page = $this->categoryQueryProvider->list(new TermFilterSet(
            parentId: $parent === null ? null : (int) $parent,
            cursor:   $this->param($request, 'cursor'),
            limit:    $this->intParam($request, 'limit'),
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
            'limit'     => ['type' => 'integer', 'sanitize_callback' => 'absint'],
            'sku'       => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'type'      => ['type' => 'string',  'sanitize_callback' => 'sanitize_key'],
            'featured'  => ['type' => 'boolean'],
            'min_price' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'max_price' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
            'category'  => ['type' => 'string',  'sanitize_callback' => 'sanitize_title'],
            'attribute'      => ['type' => 'string', 'sanitize_callback' => 'sanitize_key'],
            'attribute_term' => ['type' => 'string', 'sanitize_callback' => 'sanitize_title'],
            'in_stock'       => ['type' => 'boolean'],
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
