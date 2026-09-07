<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Rest;

use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Queries\TermFilterSet;

/**
 * Registers the Commerce delivery routes on the `hsp/v1` namespace (DECISION N, Doc 9 §7).
 *
 *   GET /hsp/v1/products
 *   GET /hsp/v1/products/{slug}
 *   GET /hsp/v1/product-categories
 *   GET /hsp/v1/product-categories/{slug}
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

    public function __construct(
        private readonly QueryProviderInterface $productQueryProvider,
        private readonly ResourceInterface $productResource,
        private readonly QueryProviderInterface $categoryQueryProvider,
        private readonly ResourceInterface $termResource,
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
        ];
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
            return new \WP_Error('hsp_product_not_found', 'Product not found.', ['status' => 404]);
        }

        return null;
    }
}
