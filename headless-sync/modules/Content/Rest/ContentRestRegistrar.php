<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Rest;

use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;

/**
 * Registers all six Content REST endpoints with WordPress.
 *
 * This class is the ONLY place where WP_REST_Request / WP_REST_Response / WP_Error
 * or any WordPress REST types appear. Query Providers and Resources are kept
 * transport-agnostic (ADR-038).
 *
 * WP security boundary (IMPLEMENTATION_PLAN.md §3 / WPCS):
 *   - All inputs are sanitized before being passed to Query Providers.
 *   - status filter validates against the public set; returns WP_Error 400 on mismatch.
 *   - limit and cursor are sanitized before use.
 *   - Responses are produced by Resources (pure arrays) then passed to
 *     rest_ensure_response(); no manual output escaping needed for JSON REST responses
 *     as WP encodes the JSON payload automatically.
 *
 * No WordPress reads on the consumer path (ADR-040).
 * Namespace: hsp/v1 (vendor-prefixed per DECISION N; Doc 9 §7).
 *
 * ADR-012: constructor injection only.
 */
final class ContentRestRegistrar
{
    private const NAMESPACE = 'hsp/v1';

    /** Values accepted by the ?status= filter (public set — OPEN-10). */
    private const PUBLIC_STATUSES = ['publish'];

    public function __construct(
        private readonly QueryProviderInterface $pageQueryProvider,
        private readonly QueryProviderInterface $postQueryProvider,
        private readonly QueryProviderInterface $categoryQueryProvider,
        private readonly ResourceInterface      $pageResource,
        private readonly ResourceInterface      $postResource,
        private readonly ResourceInterface      $categoryResource,
    ) {}

    /** Called from ContentModule::register() via add_action('rest_api_init'). */
    public function register(): void
    {
        // Pages
        register_rest_route(self::NAMESPACE, '/pages', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handlePageListing(...),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(['slug', 'published_after']),
        ]);

        register_rest_route(self::NAMESPACE, '/pages/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handlePageSingle(...),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // Posts
        register_rest_route(self::NAMESPACE, '/posts', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handlePostListing(...),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(['category', 'published_after']),
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handlePostSingle(...),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // Categories
        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleCategoryListing(...),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs([]),
        ]);

        register_rest_route(self::NAMESPACE, '/categories/(?P<slug>[a-z0-9_-]+)', [
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

    // -------------------------------------------------------------------------
    // Handlers
    // -------------------------------------------------------------------------

    public function handlePageListing(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $statusError = $this->validateStatus($request->get_param('status'));
        if ($statusError !== null) {
            return $statusError;
        }

        $cursorError = $this->validateCursor($request->get_param('cursor'));
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new FilterSet(
            status:         $this->sanitizeStatus($request->get_param('status')),
            publishedAfter: $this->sanitizeDate($request->get_param('published_after')),
            cursor:         $this->sanitizeCursor($request->get_param('cursor')),
            limit:          $this->sanitizeLimit($request->get_param('per_page')),
        );

        $page = $this->pageQueryProvider->list($filters);
        return rest_ensure_response(
            $this->pageResource->toCollection($page->rows, $page->nextCursor)
        );
    }

    public function handlePageSingle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_title((string) ($request->get_param('slug') ?? ''));
        $row  = $this->pageQueryProvider->findBySlug($slug);

        if ($row === null) {
            return new \WP_Error(
                'hsp_not_found',
                __('Page not found.', 'headless-sync'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($this->pageResource->toArray($row));
    }

    public function handlePostListing(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $statusError = $this->validateStatus($request->get_param('status'));
        if ($statusError !== null) {
            return $statusError;
        }

        $cursorError = $this->validateCursor($request->get_param('cursor'));
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new FilterSet(
            status:         $this->sanitizeStatus($request->get_param('status')),
            categorySlug:   $this->sanitizeCategorySlug($request->get_param('category')),
            publishedAfter: $this->sanitizeDate($request->get_param('published_after')),
            cursor:         $this->sanitizeCursor($request->get_param('cursor')),
            limit:          $this->sanitizeLimit($request->get_param('per_page')),
        );

        $page = $this->postQueryProvider->list($filters);
        return rest_ensure_response(
            $this->postResource->toCollection($page->rows, $page->nextCursor)
        );
    }

    public function handlePostSingle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_title((string) ($request->get_param('slug') ?? ''));
        $row  = $this->postQueryProvider->findBySlug($slug);

        if ($row === null) {
            return new \WP_Error(
                'hsp_not_found',
                __('Post not found.', 'headless-sync'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($this->postResource->toArray($row));
    }

    public function handleCategoryListing(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $cursorError = $this->validateCursor($request->get_param('cursor'));
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new FilterSet(
            cursor: $this->sanitizeCursor($request->get_param('cursor')),
            limit:  $this->sanitizeLimit($request->get_param('per_page')),
        );

        $page = $this->categoryQueryProvider->list($filters);
        return rest_ensure_response(
            $this->categoryResource->toCollection($page->rows, $page->nextCursor)
        );
    }

    public function handleCategorySingle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_title((string) ($request->get_param('slug') ?? ''));
        $row  = $this->categoryQueryProvider->findBySlug($slug);

        if ($row === null) {
            return new \WP_Error(
                'hsp_not_found',
                __('Category not found.', 'headless-sync'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($this->categoryResource->toArray($row));
    }

    // -------------------------------------------------------------------------
    // Input sanitization helpers
    // -------------------------------------------------------------------------

    /**
     * Validate that ?status= is within the public set (OPEN-10).
     * Returns WP_Error 400 if invalid; null if valid or absent.
     */
    private function validateStatus(mixed $raw): ?\WP_Error
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $sanitized = sanitize_text_field((string) $raw);
        if (! in_array($sanitized, self::PUBLIC_STATUSES, strict: true)) {
            return new \WP_Error(
                'hsp_invalid_status',
                sprintf(
                    /* translators: %s: comma-separated list of valid status values */
                    __('Invalid status. Accepted values: %s.', 'headless-sync'),
                    implode(', ', self::PUBLIC_STATUSES)
                ),
                ['status' => 400]
            );
        }
        return null;
    }

    /**
     * Validate that ?cursor= is either absent or a structurally valid base64url-encoded
     * JSON object with 's' and 'id' keys. Returns WP_Error 400 if present but invalid.
     *
     * A cursor that passes character-level sanitization but fails structural decode is
     * rejected here rather than silently ignored, so callers get an actionable error
     * instead of unexpectedly receiving page 1.
     */
    private function validateCursor(mixed $raw): ?\WP_Error
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        // Strip non-base64url chars first (same as sanitizeCursor).
        $sanitized = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $raw);
        if ($sanitized === '') {
            return new \WP_Error(
                'hsp_invalid_cursor',
                __('Invalid cursor token.', 'headless-sync'),
                ['status' => 400]
            );
        }
        // Attempt decode: must be valid base64url wrapping a JSON object with 's' and 'id'.
        $padded  = strtr($sanitized, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json    = base64_decode($padded, strict: true);
        if ($json === false) {
            return new \WP_Error(
                'hsp_invalid_cursor',
                __('Invalid cursor token.', 'headless-sync'),
                ['status' => 400]
            );
        }
        $data = json_decode($json, associative: true);
        if (! is_array($data) || ! isset($data['s'], $data['id'])) {
            return new \WP_Error(
                'hsp_invalid_cursor',
                __('Invalid cursor token.', 'headless-sync'),
                ['status' => 400]
            );
        }
        return null;
    }

    private function sanitizeStatus(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return sanitize_text_field((string) $raw) ?: null;
    }

    private function sanitizeCategorySlug(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        return sanitize_title((string) $raw) ?: null;
    }

    private function sanitizeDate(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $sanitized = sanitize_text_field((string) $raw);
        try {
            return new \DateTimeImmutable($sanitized, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function sanitizeCursor(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        // Only allow base64url characters.
        $sanitized = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $raw);
        return $sanitized !== '' ? $sanitized : null;
    }

    private function sanitizeLimit(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $int = (int) $raw;
        return $int > 0 ? $int : null;
    }

    // -------------------------------------------------------------------------
    // Shared arg schema helpers
    // -------------------------------------------------------------------------

    /**
     * Common args present on every listing endpoint, plus optional extras.
     *
     * @param list<string> $extras  Names of optional extra args: 'slug', 'category', 'published_after'
     * @return array<string,array<string,mixed>>
     */
    private function listingArgs(array $extras): array
    {
        $args = [
            'cursor'   => [
                'type'              => 'string',
                'sanitize_callback' => fn($v) => $this->sanitizeCursor($v) ?? '',
            ],
            'per_page' => [
                'type'              => 'integer',
                'minimum'           => 1,
                'maximum'           => 100,
                'sanitize_callback' => 'absint',
            ],
            'status'   => [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ];

        if (in_array('category', $extras, strict: true)) {
            $args['category'] = [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
            ];
        }

        if (in_array('published_after', $extras, strict: true)) {
            $args['published_after'] = [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
            ];
        }

        return $args;
    }
}
