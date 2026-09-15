<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Rest;

use HSP\Core\Contracts\HierarchicalQueryProviderInterface;
use HSP\Core\Delivery\CursorToken;
use HSP\Modules\Content\PublicStatus;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Contracts\ResourceInterface;
use HSP\Core\Rest\DeliveryErrorBoundary;

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

    /**
     * Values accepted by the ?status= filter (public set — OPEN-10).
     *
     * Read from the module-owned holder, so the set this class ENFORCES and the enum
     * ContentEndpointProvider PUBLISHES cannot diverge (FLAG-RESTARGDRIFT-1).
     *
     * @var list<string>
     */
    private const PUBLIC_STATUSES = PublicStatus::SET;

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
        // CCF-003: every callback below is registered through this boundary, so a Throwable that
        // escapes a handler becomes the documented 500 envelope instead of an HTML fatal page.
        // Injected, not reached statically (ADR-012 / Rule 7).
        private readonly DeliveryErrorBoundary  $errorBoundary,
    ) {}

    /** Called from ContentModule::register() via add_action('rest_api_init'). */
    public function register(): void
    {
        // Pages
        register_rest_route(self::NAMESPACE, '/pages', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handlePageListing(...)),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(['status', 'published_after']),
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
            'callback'            => $this->errorBoundary->guard($this->handlePageSingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'path' => [
                    'required' => true,
                    'type'     => 'string',
                    // The HIERARCHICAL class — the single-slug one plus the `/` separator — so the
                    // published pattern keeps admitting the multi-segment form `about/team` that is
                    // this endpoint's whole point (DECISION AD). Publishing a single-slug pattern
                    // here would describe an API unable to address a nested page. Structural like
                    // the slug routes: no new 400 (FLAG-RESTARGDRIFT-1 D-5).
                    'pattern'  => '^[a-z0-9_/-]+$',
                ],
            ],
        ]);

        // Posts
        register_rest_route(self::NAMESPACE, '/posts', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handlePostListing(...)),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(['status', 'category', 'tag', 'published_after']),
        ]);

        register_rest_route(self::NAMESPACE, '/posts/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handlePostSingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    // The route's own character class, published so the addressing contract stops
                    // reading as "any string" (FLAG-RESTARGDRIFT-1 D-5). Enforced STRUCTURALLY by
                    // route matching, so no new 400 becomes reachable: a URL outside the class
                    // never reaches this operation, it fails WordPress dispatch.
                    'pattern'           => '^[a-z0-9_-]+$',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // Categories
        register_rest_route(self::NAMESPACE, '/categories', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleCategoryListing(...)),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs([]),
        ]);

        register_rest_route(self::NAMESPACE, '/categories/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleCategorySingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    // The route's own character class, published so the addressing contract stops
                    // reading as "any string" (FLAG-RESTARGDRIFT-1 D-5). Enforced STRUCTURALLY by
                    // route matching, so no new 400 becomes reachable: a URL outside the class
                    // never reaches this operation, it fails WordPress dispatch.
                    'pattern'           => '^[a-z0-9_-]+$',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // Media
        register_rest_route(self::NAMESPACE, '/media', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleMediaListing(...)),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs(['published_after']),
        ]);

        register_rest_route(self::NAMESPACE, '/media/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleMediaSingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    // The route's own character class, published so the addressing contract stops
                    // reading as "any string" (FLAG-RESTARGDRIFT-1 D-5). Enforced STRUCTURALLY by
                    // route matching, so no new 400 becomes reachable: a URL outside the class
                    // never reaches this operation, it fails WordPress dispatch.
                    'pattern'           => '^[a-z0-9_-]+$',
                    'sanitize_callback' => 'sanitize_title',
                ],
            ],
        ]);

        // Tags
        register_rest_route(self::NAMESPACE, '/tags', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleTagListing(...)),
            'permission_callback' => '__return_true',
            'args'                => $this->listingArgs([]),
        ]);

        register_rest_route(self::NAMESPACE, '/tags/(?P<slug>[a-z0-9_-]+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => $this->errorBoundary->guard($this->handleTagSingle(...)),
            'permission_callback' => '__return_true',
            'args'                => [
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    // The route's own character class, published so the addressing contract stops
                    // reading as "any string" (FLAG-RESTARGDRIFT-1 D-5). Enforced STRUCTURALLY by
                    // route matching, so no new 400 becomes reachable: a URL outside the class
                    // never reaches this operation, it fails WordPress dispatch.
                    'pattern'           => '^[a-z0-9_-]+$',
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

        $cursorError = $this->validateCursor($request->get_param('cursor'), CursorToken::SORT_TEMPORAL);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new ContentFilterSet(
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

        $cursorError = $this->validateCursor($request->get_param('cursor'), CursorToken::SORT_TEMPORAL);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new ContentFilterSet(
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
        $cursorError = $this->validateCursor($request->get_param('cursor'), CursorToken::SORT_TEXT);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new ContentFilterSet(
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
        $cursorError = $this->validateCursor($request->get_param('cursor'), CursorToken::SORT_TEMPORAL);
        if ($cursorError !== null) {
            return $cursorError;
        }

        // No status filter: attachments carry post_status='inherit', outside the {publish}
        // public set (OPEN-10), so membership is "not soft-deleted" — as for categories.
        $filters = new ContentFilterSet(
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
        $cursorError = $this->validateCursor($request->get_param('cursor'), CursorToken::SORT_TEXT);
        if ($cursorError !== null) {
            return $cursorError;
        }

        $filters = new ContentFilterSet(
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
     * Validate ?cursor= against THIS endpoint's cursor contract. Returns WP_Error 400 if present
     * but invalid; null if valid or absent.
     *
     * This used to check only that the decoded payload HAD keys `s` and `id`, which let
     * `{"s":"notadate","id":"x"}` through to `$n::timestamptz` in SQL — an uncaught
     * DatabaseException and an unauthenticated HTML 500 carrying a stack trace and filesystem
     * paths. The payload is now checked against the shape this endpoint actually mints: the
     * UUID tiebreaker, and a sort value of the right TYPE for the endpoint's primary sort, so
     * nothing malformed can reach a PostgreSQL cast capable of throwing (CCF-003).
     *
     * The sort kind is passed by the caller rather than assumed: posts, pages and media sort on
     * published_at, while categories and tags sort on name. Demanding a timestamp everywhere
     * would reject perfectly valid taxonomy cursors.
     *
     * @param string $sortKind one of the CursorToken::SORT_* constants
     */
    private function validateCursor(mixed $raw, string $sortKind): ?\WP_Error
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        // Strip non-base64url chars first (same as sanitizeCursor), so a token that only differs
        // by stray characters is judged on the same string the query layer would have used.
        $sanitized = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $raw) ?? '';

        if (CursorToken::isValid($sanitized, $sortKind)) {
            return null;
        }

        return new \WP_Error(
            'hsp_invalid_cursor',
            __('Invalid cursor token.', 'headless-sync'),
            ['status' => 400]
        );
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
     * Every extra a caller names must have a branch below. An unbranched name is not a no-op:
     * the filter keeps working (WordPress hands unregistered query parameters to get_param()
     * and the handler sanitizes them itself), but WordPress's own published route index omits
     * it — so `/wp-json/hsp/v1` and the generated OpenAPI end up describing the same endpoint
     * differently. That is how `tag` went undeclared from P1B-S3 until Finding 002 (B1); the
     * ADR-055 drift guard compared routes to descriptors, not route args to descriptor
     * parameters, so nothing caught it. It now compares parameters too (FLAG-RESTARGDRIFT-1).
     *
     * `status` is an EXTRA rather than a common arg (FLAG-RESTARGDRIFT-1 finding B-1). It was
     * registered on all five listings but only `/posts` and `/pages` ever read it: the category,
     * tag and media handlers never call validateStatus() and never pass a status to the filter
     * set, because `publish` is not a taxonomy state and attachments carry `inherit` (OPEN-10).
     * So three routes advertised a parameter in `/wp-json/hsp/v1` that did nothing. Registration
     * cleanup only — the parameter had no effect before and is still ignored if sent.
     *
     * @param list<string> $extras  Names of optional extra args: 'status', 'category', 'tag',
     *                              'published_after'
     * @return array<string,array<string,mixed>>
     */
    private function listingArgs(array $extras): array
    {
        $args = [
            'cursor'   => [
                'type'              => 'string',
                'sanitize_callback' => fn($v) => $this->sanitizeCursor($v) ?? '',
            ],
            // 1..100 is the published contract, in the descriptor and here. It used to be
            // declared here and enforced NOWHERE: WordPress only schema-validates an arg that
            // has a `type` and NO `sanitize_callback`, because the default validating sanitizer
            // `rest_parse_request_arg` is installed in WP_REST_Request::sanitize_params() only
            // when the key is absent. `absint` displaced it, so `minimum`/`maximum` were inert
            // metadata and ?per_page=500 / =0 / =abc all returned 200 (FLAG-RESTARGDRIFT-1).
            //
            // The callback is named EXPLICITLY rather than left to that implicit default: it
            // runs in has_valid_params(), which WP_REST_Server calls BEFORE sanitize_params(),
            // so the raw value is judged before absint('abc') can turn it into 0 — and a future
            // sanitizer added here cannot silently switch validation off again.
            'per_page' => [
                'type'              => 'integer',
                'minimum'           => 1,
                'maximum'           => 100,
                'validate_callback' => 'rest_validate_request_arg',
                'sanitize_callback' => 'absint',
            ],
        ];

        // The allowed set is declared, so WordPress's route index and the generated OpenAPI both
        // publish it. Enforcement deliberately stays with validateStatus() below and NOT with
        // rest_validate_request_arg: the module validator emits the CCF-003 stable code
        // `hsp_invalid_status`, and it treats an empty value as absent — both of which are the
        // verified shipped contract, and generic validation would change both.
        if (in_array('status', $extras, strict: true)) {
            $args['status'] = [
                'type'              => 'string',
                'enum'              => PublicStatus::SET,
                'sanitize_callback' => 'sanitize_text_field',
            ];
        }

        if (in_array('category', $extras, strict: true)) {
            $args['category'] = [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
            ];
        }

        // Same sanitizer as the handler applies (sanitizeCategorySlug → sanitize_title): the
        // declaration has to describe the filter that actually runs, not a second policy.
        if (in_array('tag', $extras, strict: true)) {
            $args['tag'] = [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_title',
            ];
        }

        if (in_array('published_after', $extras, strict: true)) {
            $args['published_after'] = [
                'type'              => 'string',
                'format'            => 'date-time',
                'validate_callback' => $this->validatePublishedAfter(...),
                'sanitize_callback' => 'sanitize_text_field',
            ];
        }

        return $args;
    }

    /**
     * Validate `?published_after=` as an absolute instant.
     *
     * A lower bound on `published_at` is an INSTANT, and the column is `TIMESTAMPTZ`, so a
     * value without a timezone is ambiguous by construction. The published contract is therefore
     * an RFC3339 date-time WITH an explicit offset: `2026-01-01T00:00:00Z` or
     * `2026-01-01T05:30:00+05:30`.
     *
     * Two layers, because neither alone is the contract:
     *
     *   1. WordPress's own `format: date-time` validator (rest_validate_value_from_schema →
     *      rest_parse_date) supplies the grammar. Verified against WP 7.1: its regex is
     *      `^\d{4}-\d{2}-\d{2}[Tt ]\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}(:\d{2})?)?$`, so it
     *      rejects `garbage`, `next tuesday`, an empty value and date-only `2026-01-01` — and
     *      WP core's own `/wp/v2/posts?after=2026-01-01` returns 400 for exactly this reason.
     *      No hand-written ISO-8601 regex is invented when the platform already has the grammar.
     *   2. That regex makes the offset OPTIONAL, so `2026-01-01T00:00:00` passes it while being
     *      precisely the timezone-ambiguous form the contract excludes. This narrow module check
     *      adds the one missing semantic and nothing else.
     *
     * Previously this parameter had no validation at all: `sanitizeDate()` returned null for
     * anything unparseable and the filter was silently DROPPED, so `?published_after=garbage`
     * answered 200 with an unfiltered listing that looked like a successful filter.
     *
     * DateTimeImmutable is not used as the validator — it accepts `next tuesday` — it only
     * parses the already-validated value in sanitizeDate().
     *
     * @param mixed            $value   the raw request value
     * @param \WP_REST_Request $request the current request
     * @param string           $param   the parameter name
     */
    private function validatePublishedAfter(
        mixed $value,
        \WP_REST_Request $request,
        string $param
    ): bool|\WP_Error
    {
        $native = rest_validate_request_arg($value, $request, $param);
        if ($native instanceof \WP_Error) {
            return $native;
        }

        // Offset or `Z` required — the half WordPress's grammar leaves optional.
        if (preg_match('/([Zz]|[+-]\d{2}:?\d{2})$/', (string) $value) !== 1) {
            return new \WP_Error(
                'hsp_invalid_filter',
                __(
                    'published_after must carry an explicit UTC offset or Z (e.g. 2026-01-01T00:00:00Z).',
                    'headless-sync'
                ),
                ['status' => 400]
            );
        }

        return true;
    }
}
