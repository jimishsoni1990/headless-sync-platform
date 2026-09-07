<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Rest;

use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;
use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\TermFilterSet;
use HSP\Modules\Commerce\Queries\VariationFilterSet;

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
    ) {
    }

    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::NAMESPACE, '/products', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleProductListing(...),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(),
        ]);

        register_rest_route(self::NAMESPACE, '/products/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleProductSingle(...),
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
            'callback'            => $this->handleCategoryListing(...),
            'permission_callback' => '__return_true',
            'args'                => [
                'cursor' => ['type' => 'string',  'sanitize_callback' => 'sanitize_text_field'],
                'limit'  => ['type' => 'integer', 'sanitize_callback' => 'absint'],
                'parent' => ['type' => 'integer', 'sanitize_callback' => 'absint'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/product-categories/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleCategorySingle(...),
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
            'callback'            => $this->handleAttributeListing(...),
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
            'callback'            => $this->handleAttributeSingle(...),
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
            'callback'            => $this->handleAttributeTermListing(...),
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
            'callback'            => $this->handleVariationListing(...),
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
        $filters = new ProductFilterSet(
            sku:         $this->param($request, 'sku'),
            productType: $this->param($request, 'type'),
            featured:    $this->boolParam($request, 'featured'),
            minPrice:    $this->param($request, 'min_price'),
            maxPrice:    $this->param($request, 'max_price'),
            // Always catalog-scoped: a public listing must not expose a product WooCommerce
            // excludes from the catalog, and that must not be defeatable by a query parameter
            // (Requirement B).
            catalogOnly: true,
            categorySlug: $this->param($request, 'category'),
            attributeTaxonomy: $this->attributeTaxonomyParam($request),
            attributeTermSlug: $this->param($request, 'attribute_term'),
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
        if (class_exists(\WP_Error::class)) {
            return new \WP_Error('hsp_not_found', 'Resource not found.', ['status' => 404]);
        }

        return null;
    }
}
