<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Rest;

use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\HierarchicalQueryProviderInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;

/**
 * Registers all ten Content REST endpoints with WordPress.
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
        // Pages are hierarchical: findByPath() serves the single-page route. The intersection
        // stays after the DECISION AF fallback removal — not for findBySlug(), which this class no
        // longer calls for pages, but because the /pages LISTING still needs list(). (DECISION AD
        // ruling 7 anticipated the type narrowing to the hierarchical contract alone; that was
        // imprecise, and DECISION AF records the correction.)
        private readonly QueryProviderInterface&HierarchicalQueryProviderInterface $pageQueryProvider,
        private readonly QueryProviderInterface $postQueryProvider,
        private readonly QueryProviderInterface $categoryQueryProvider,
        private readonly QueryProviderInterface $mediaQueryProvider,
        private readonly QueryProviderInterface $tagQueryProvider,
        private readonly ResourceInterface      $pageResource,
        private readonly ResourceInterface      $postResource,
        private readonly ResourceInterface      $categoryResource,
        private readonly ResourceInterface      $mediaResource,
        private readonly ResourceInterface      $tagResource,
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

        // Pages are addressed by their FULL ancestor path (DECISION AD): `/pages/about/team`.
        // The character class is the single-slug one plus `/` — the hierarchy separator is the
        // ONLY thing widened here; the per-segment character policy is unchanged.
        //
        // NOTE the deliberate absence of a sanitize_callback. Every other single-resource route
        // uses `sanitize_title`, which STRIPS `/` and would silently turn `about/team` into
        // `aboutteam` — the exact trap this endpoint has to avoid. The path is instead sanitized
        // segment-by-segment in the handler via sanitizePath(), which applies `sanitize_title` to
        // each segment and rejects anything malformed. Sanitization still happens at the WordPress
        // entry point, just one layer in, where it can reject rather than silently mangle.
        register_rest_route(self::NAMESPACE, '/pages/(?P<path>[a-z0-9_/-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handlePageSingle(...),
            'permission_callback' => '__return_true',
            'args'                => [
                'path' => [
                    'required' => true,
                    'type'     => 'string',
                ],
            ],
        ]);

        // Posts
        register_rest_route(self::NAMESPACE, '/posts', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handlePostListing(...),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(['category', 'tag', 'published_after']),
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

        // Media
        register_rest_route(self::NAMESPACE, '/media', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleMediaListing(...),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(['published_after']),
        ]);

        register_rest_route(self::NAMESPACE, '/media/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleMediaSingle(...),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // Tags
        register_rest_route(self::NAMESPACE, '/tags', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleTagListing(...),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs([]),
        ]);

        register_rest_route(self::NAMESPACE, '/tags/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->handleTagSingle(...),
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

    /**
     * Fetch one page by its full ancestor path (DECISION AD).
     *
     * Exact path lookup, and nothing else (DECISION AF completed the DECISION AD lifecycle).
     * `/pages/about/team` resolves the page whose hierarchy IS about → team; every miss is a 404,
     * at any depth. There is no leaf fallback at either arm any more — `/pages/wrong-parent/team`
     * never returns `/about/team`, and `/pages/team` never returns a nested namesake.
     *
     * The one-segment leaf fallback that DECISION AD ruling 2 kept as the `hsp/v1` compatibility
     * arm reached Removed on 2026-09-07 (Doc 9 §26: Supported → Deprecated → Removed). Its
     * absence is also what brings the FLAG-PAGEPATH-ANCESTOR-1 case to WordPress parity: the
     * fallback used to serve a child whose ancestor is unprojected, which WordPress itself 404s.
     */
    public function handlePageSingle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $path = $this->sanitizePath($request->get_param('path'));

        if ($path === null) {
            return new \WP_Error(
                'hsp_invalid_path',
                __('Invalid page path.', 'headless-sync'),
                ['status' => 400]
            );
        }

        $row = $this->pageQueryProvider->findByPath($path);

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
            tagSlug:        $this->sanitizeCategorySlug($request->get_param('tag')),
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

    public function handleMediaListing(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $cursorError = $this->validateCursor($request->get_param('cursor'));
        if ($cursorError !== null) {
            return $cursorError;
        }

        // No status filter: attachments carry post_status='inherit', outside the {publish}
        // public set (OPEN-10), so membership is "not soft-deleted" — as for categories.
        $filters = new FilterSet(
            publishedAfter: $this->sanitizeDate($request->get_param('published_after')),
            cursor:         $this->sanitizeCursor($request->get_param('cursor')),
            limit:          $this->sanitizeLimit($request->get_param('per_page')),
        );

        $page = $this->mediaQueryProvider->list($filters);
        return rest_ensure_response(
            $this->mediaResource->toCollection($page->rows, $page->nextCursor)
        );
    }

    public function handleMediaSingle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_title((string) ($request->get_param('slug') ?? ''));
        $row  = $this->mediaQueryProvider->findBySlug($slug);

        if ($row === null) {
            return new \WP_Error(
                'hsp_not_found',
                __('Media item not found.', 'headless-sync'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($this->mediaResource->toArray($row));
    }

    public function handleTagListing(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $cursorError = $this->validateCursor($request->get_param('cursor'));
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new FilterSet(
            cursor: $this->sanitizeCursor($request->get_param('cursor')),
            limit:  $this->sanitizeLimit($request->get_param('per_page')),
        );

        $page = $this->tagQueryProvider->list($filters);
        return rest_ensure_response(
            $this->tagResource->toCollection($page->rows, $page->nextCursor)
        );
    }

    public function handleTagSingle(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $slug = sanitize_title((string) ($request->get_param('slug') ?? ''));
        $row  = $this->tagQueryProvider->findBySlug($slug);

        if ($row === null) {
            return new \WP_Error(
                'hsp_not_found',
                __('Tag not found.', 'headless-sync'),
                ['status' => 404]
            );
        }

        return rest_ensure_response($this->tagResource->toArray($row));
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

    /**
     * Sanitize a hierarchical page path segment by segment (DECISION AD ruling 6).
     *
     * `sanitize_title()` is the project's slug sanitizer and it strips `/`, so running a whole
     * path through it would collapse `about/team` into `aboutteam` and quietly resolve the wrong
     * page. The separator is therefore handled here and only the SEGMENTS are sanitized, leaving
     * the per-segment character policy identical to every other slug on the API.
     *
     * Leading and trailing separators are trimmed, so `/about/team/` — the shape a WordPress
     * permalink actually has — is accepted and means the same thing as `about/team`.
     *
     * Returns null for a malformed path, which the caller turns into a 400: an empty internal
     * segment (`about//team`) or a segment that sanitizes away to nothing. Traversal is covered
     * twice over — the route's character class admits no `.` at all, and `sanitize_title('..')`
     * is the empty string, which is rejected here.
     */
    private function sanitizePath(mixed $raw): ?string
    {
        $trimmed = trim((string) ($raw ?? ''), '/');
        if ($trimmed === '') {
            return null;
        }

        $segments = explode('/', $trimmed);
        $clean    = [];

        foreach ($segments as $segment) {
            $sanitized = sanitize_title($segment);
            if ($sanitized === '') {
                return null;
            }
            $clean[] = $sanitized;
        }

        return implode('/', $clean);
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
