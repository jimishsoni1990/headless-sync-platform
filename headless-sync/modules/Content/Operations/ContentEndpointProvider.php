<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Operations;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Modules\Content\PublicStatus;
use HSP\Core\Contracts\Operations\SchemaObject;

/**
 * Module-owned endpoint metadata for the Content module's hsp/v1 routes (Doc 12 §15; ADR-050;
 * ADR-055).
 *
 * Implements the core-owned EndpointProviderInterface (Rule 5) and populates the ADR-055 (c)
 * enriched descriptors for the ten published content endpoints (DECISION N: 'hsp/v1'): parameters
 * (DECISION F filters + cursor pagination — Doc 9 §13), published request/response shapes (Rule 6
 * — the fields the Resources expose, NOT internal content.* / canonical columns), auth requirement
 * (all ten are PUBLIC — Doc 9 §22), deprecation (none at MVP — Doc 9 §26), version (v1 — Doc 9 §7),
 * and module owner ('content' — Doc 9 §6). The generator (ADR-055) and the API Playground (OPSC-S3)
 * both read these descriptors — one source of truth (ADR-055 (b)). ADR-038: plain metadata only;
 * no HTTP/framework types cross this contract.
 *
 * NAMESPACE mirrors ContentRestRegistrar::NAMESPACE (DECISION N) — kept in sync by hand since that
 * constant is private; a drift here is caught by the OpenAPI drift guard (ADR-055 (f)).
 */
final class ContentEndpointProvider implements EndpointProviderInterface
{
    public const KEY = 'content.endpoints';

    private const NAMESPACE = 'hsp/v1';

    private const MODULE = 'content';

    /**
     * The addressing grammar of the flat single-resource routes, published so the contract stops
     * implying that any string is addressable (FLAG-RESTARGDRIFT-1 D-5).
     *
     * Identical to the character class in the WordPress route regexes, which is what ENFORCES it —
     * structurally, at dispatch, so publishing it creates no new 400. The ADR-055 parameter drift
     * guard compares this string against the live route's own capture group, so a route widened
     * without the contract following cannot stay green.
     */
    private const SLUG_PATTERN = '^[a-z0-9_-]+$';

    /** As SLUG_PATTERN, plus the `/` hierarchy separator pages are addressed by (DECISION AD). */
    private const PATH_PATTERN = '^[a-z0-9_/-]+$';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return EndpointDescriptor[] */
    public function endpoints(): array
    {
        return [
            $this->pagesList(),
            $this->pageSingle(),
            $this->postsList(),
            $this->postSingle(),
            $this->categoriesList(),
            $this->categorySingle(),
            $this->mediaList(),
            $this->mediaSingle(),
            $this->tagsList(),
            $this->tagSingle(),
        ];
    }

    // -------------------------------------------------------------------------
    // Pages
    // -------------------------------------------------------------------------

    private function pagesList(): EndpointDescriptor
    {
        return $this->listing(
            route: '/pages',
            description: 'List published pages (cursor-paginated).',
            itemSchema: $this->pageSchema(),
            filters: [
                self::statusFilter(),
                self::publishedAfterFilter(),
            ],
        );
    }

    /**
     * Pages are hierarchical, so the published parameter is a PATH, not a slug (DECISION AD).
     * This is a WIDENING of the same endpoint — the route count is unchanged and there is no
     * second page-addressing API.
     */
    private function pageSingle(): EndpointDescriptor
    {
        return $this->single(
            route: '/pages/{path}',
            description: 'Fetch a single page by its full hierarchical path (e.g. about/team).',
            itemSchema: $this->pageSchema(),
            paramName: 'path',
            paramDescription: 'Full ancestor path, `/`-separated (e.g. about/team). '
                . 'A one-segment path addresses a top-level page.',
            paramPattern: self::PATH_PATTERN,
            // The only single-resource route that can 400: a malformed path (an empty internal
            // segment, or a segment that sanitises away) is rejected before the lookup runs.
            errorStatuses: [400, 404, 500],
        );
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

    /**
     * The ordering sentence publishes DECISION F, it does not decide anything: the sort keys were
     * ratified at P1A-S5 and PostQueryProvider has always implemented them. Until Finding 002 the
     * contract said only "cursor-paginated", so a consumer composing "the three most recent posts
     * sharing this tag" had to guess that the listing is newest-first or read the PHP — the same
     * gap Finding 010 closed for variation selection.
     *
     * It says newest-first and stable, and deliberately NOT `published_at DESC, id DESC`: the
     * projection UUID is an internal column (ADR-040) and the cursor that carries it is opaque by
     * contract. Consumers need to know the order is newest-first and that equal timestamps do not
     * shuffle between pages; they do not need the tie-breaker's identity, and publishing it would
     * leak one.
     *
     * The filter applies BEFORE that ordering, which is the whole of what HSP offers related-content
     * composition (Finding 002): matching posts in normal listing order, never a relevance ranking.
     */
    private function postsList(): EndpointDescriptor
    {
        return $this->listing(
            route: '/posts',
            description: 'List published posts (cursor-paginated). Posts are returned newest '
                . 'first by published time, with deterministic ordering for posts sharing the '
                . 'same publication timestamp, so paging never shuffles or repeats a row. '
                . 'Filters narrow which posts are returned; they never re-rank them — a '
                . 'filtered listing is ordinary post-list order, not a relevance ranking.',
            itemSchema: $this->postSchema(),
            filters: [
                self::statusFilter(),
                EndpointParameter::query('category', 'string', 'Filter by category slug.'),
                EndpointParameter::query(
                    'tag',
                    'string',
                    'Filter by tag slug. Matches tags only: a category sharing the slug never '
                    . 'matches, and neither does a deleted tag.'
                ),
                self::publishedAfterFilter(),
            ],
        );
    }

    private function postSingle(): EndpointDescriptor
    {
        return $this->single('/posts/{slug}', 'Fetch a single post by slug.', $this->postSchema());
    }

    // -------------------------------------------------------------------------
    // Categories
    // -------------------------------------------------------------------------

    private function categoriesList(): EndpointDescriptor
    {
        return $this->listing(
            route: '/categories',
            description: 'List categories (cursor-paginated).',
            itemSchema: $this->categorySchema(),
            filters: [],
        );
    }

    private function categorySingle(): EndpointDescriptor
    {
        return $this->single('/categories/{slug}', 'Fetch a single category by slug.', $this->categorySchema());
    }

    // -------------------------------------------------------------------------
    // Media
    // -------------------------------------------------------------------------

    private function mediaList(): EndpointDescriptor
    {
        return $this->listing(
            route: '/media',
            description: 'List media items (cursor-paginated).',
            itemSchema: $this->mediaSchema(),
            // No status filter: attachments carry post_status='inherit', outside the
            // {publish} public set (OPEN-10) — membership is "not soft-deleted".
            filters: [
                self::publishedAfterFilter(),
            ],
        );
    }

    private function mediaSingle(): EndpointDescriptor
    {
        return $this->single('/media/{slug}', 'Fetch a single media item by slug.', $this->mediaSchema());
    }

    // -------------------------------------------------------------------------
    // Tags (P1B-S3 — same projection as categories, taxonomy_type='post_tag')
    // -------------------------------------------------------------------------

    private function tagsList(): EndpointDescriptor
    {
        return $this->listing(
            route: '/tags',
            description: 'List tags (cursor-paginated).',
            itemSchema: $this->categorySchema(),
            filters: [],
        );
    }

    private function tagSingle(): EndpointDescriptor
    {
        return $this->single('/tags/{slug}', 'Fetch a single tag by slug.', $this->categorySchema());
    }

    // -------------------------------------------------------------------------
    // Descriptor builders
    // -------------------------------------------------------------------------

    /**
     * A cursor-paginated listing endpoint: the common cursor/per_page params + any DECISION F
     * filters, a cursor-envelope response (data[] + next_cursor — Doc 9 §13).
     *
     * @param EndpointParameter[] $filters
     */
    private function listing(
        string $route,
        string $description,
        SchemaObject $itemSchema,
        array $filters
    ): EndpointDescriptor {
        $parameters = array_merge($filters, [
            EndpointParameter::query('cursor', 'string', 'Opaque pagination cursor (Doc 9 §13).'),
            // 1..100 as MACHINE-READABLE bounds, not prose. The description said "(1-100)" from
            // the day this descriptor shipped while the schema published a bare `integer`, so no
            // generated client could see the bound and nothing could compare it against the route
            // args that declared it and never enforced it (FLAG-RESTARGDRIFT-1 A-1).
            EndpointParameter::query(
                'per_page',
                'integer',
                'Page size. A value outside 1-100 is rejected with 400.',
                minimum: 1,
                maximum: 100
            ),
        ]);

        return new EndpointDescriptor(
            method: 'GET',
            route: $route,
            namespace: self::NAMESPACE,
            displayGroup: 'Content',
            description: $description,
            parameters: $parameters,
            responseSchema: $itemSchema->asCursorPage(),
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: true,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
            // Every listing validates its request (status where offered, and the cursor) and can
            // therefore answer 400; every listing runs a query and is wrapped by the Core delivery
            // error boundary, so it can answer 500. A listing never 404s — an empty page is a
            // successful result, not a missing resource (CCF-003).
            errorStatuses: [400, 500],
        );
    }

    /** A single-resource-by-slug endpoint: one required path param, the bare resource shape. */
    /**
     * @param string $paramName        Path parameter name — `slug` for the flat resources,
     *                                 `path` for hierarchical pages (DECISION AD).
     * @param string $paramDescription Its published description.
     */
    /**
     * @param list<int> $errorStatuses Defaults to the flat-slug case: the slug is constrained by
     *        the route regex and sanitised, so the only application errors are a miss (404) and a
     *        contained internal failure (500). Pages override it — their path parameter has a
     *        validity rule of its own and can be rejected as 400 (CCF-003).
     */
    private function single(
        string $route,
        string $description,
        SchemaObject $itemSchema,
        string $paramName = 'slug',
        string $paramDescription = 'Resource slug.',
        array $errorStatuses = [404, 500],
        string $paramPattern = self::SLUG_PATTERN
    ): EndpointDescriptor {
        return new EndpointDescriptor(
            method: 'GET',
            route: $route,
            namespace: self::NAMESPACE,
            displayGroup: 'Content',
            description: $description,
            parameters: [EndpointParameter::path($paramName, 'string', $paramDescription, $paramPattern)],
            responseSchema: $itemSchema,
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: false,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
            errorStatuses: $errorStatuses,
        );
    }

    /**
     * The `?status=` filter, with its allowed set published as an `enum`.
     *
     * The values come from PublicStatus — the SAME constant ContentRestRegistrar enforces
     * against — so the published contract and the runtime rejection cannot name different
     * sets (FLAG-RESTARGDRIFT-1 C-4). It used to appear only as prose, so a consumer had to
     * read English to learn that `draft` is refused.
     *
     * Enforcement stays with the module's own validator rather than generic schema
     * validation, because that validator emits the CCF-003 stable code `hsp_invalid_status`
     * and treats an empty value as absent — the verified shipped contract, which generic
     * validation would change on both counts (FLAG-RESTARGDRIFT-1 D-1).
     */
    private static function statusFilter(): EndpointParameter
    {
        return EndpointParameter::query(
            'status',
            'string',
            'Filter by post status. Only the public set is accepted; any other value is '
            . 'rejected with 400. Omitting it returns the default public listing.',
            enum: PublicStatus::SET
        );
    }

    /**
     * The `?published_after=` lower bound: an ABSOLUTE instant.
     *
     * `format: date-time` replaces a prose-only "ISO-8601 UTC" claim that nothing enforced —
     * an unparseable value used to be dropped silently, so `?published_after=garbage`
     * answered 200 with an unfiltered listing that looked like a successful filter
     * (FLAG-RESTARGDRIFT-1 C-5).
     *
     * The bound is compared against a TIMESTAMPTZ column, so a value carrying no timezone is
     * ambiguous by construction and a date-only value is not sufficient. The published
     * contract is therefore RFC3339 WITH an explicit offset — narrower than WordPress's own
     * `date-time` grammar, which leaves the offset optional, so the registrar adds exactly
     * that one missing semantic on top of the native validator.
     */
    private static function publishedAfterFilter(): EndpointParameter
    {
        return EndpointParameter::query(
            'published_after',
            'string',
            'Lower bound on published_at, as an RFC3339/ISO-8601 date-time with an explicit '
            . 'timezone (e.g. 2026-01-01T00:00:00Z or 2026-01-01T05:30:00+05:30). A date-only '
            . 'value is not accepted: the bound is an instant, and a bare date has no '
            . 'timezone.',
            format: 'date-time'
        );
    }

    // -------------------------------------------------------------------------
    // Published resource shapes (Rule 6 — the fields the Resources expose; NOT content.* columns)
    // -------------------------------------------------------------------------

    /** Mirrors PageResource::toArray() published fields. */
    private function pageSchema(): SchemaObject
    {
        return SchemaObject::object([
            'slug'         => 'string',
            // The canonical public page identity (Finding 005) — published on BOTH page surfaces,
            // because an identity is only useful where the resource appears.
            'path'         => self::pagePathSchema(),
            'title'        => 'string',
            'content'      => 'string',
            'status'       => 'string',
            'parent_id'    => 'integer',
            'menu_order'   => 'integer',
            'published_at' => 'string',
            'updated_at'   => 'string',
            'meta'         => self::openMapSchema(
                'WordPress post meta the projection carries. Deliberately OPEN: the key set '
                . 'belongs to the site, not to the published contract.'
            ),
            // Resolved featured image, or null (P1B-S2). Nullable because the reference is soft
            // (ADR-013): no image set, never projected, or soft-deleted all read as null.
            'featured_media' => self::featuredMediaSchema(),
        ]);
    }

    /** Mirrors PostResource::toArray() published fields. */
    private function postSchema(): SchemaObject
    {
        return SchemaObject::object([
            'slug'         => 'string',
            'title'        => 'string',
            'content'      => 'string',
            'excerpt'      => 'string',
            'status'       => 'string',
            'author'       => 'string',
            'published_at' => 'string',
            'updated_at'   => 'string',
            'meta'         => self::openMapSchema(
                'WordPress post meta the projection carries. Deliberately OPEN: the key set '
                . 'belongs to the site, not to the published contract.'
            ),
            // Resolved featured image, or null (P1B-S2). Nullable because the reference is soft
            // (ADR-013): no image set, never projected, or soft-deleted all read as null.
            'featured_media' => self::featuredMediaSchema(),
            // The post's tags (P1B-S3): always an array — empty when untagged, never null.
            'tags'         => self::taxonomyRefListSchema(
                "The post's tags, ordered by slug. Empty when untagged; never null."
            ),
            // The post's categories (Finding 003): the SAME reference shape as tags, and a LIST —
            // a WordPress post may belong to several categories and the platform has no
            // primary-category concept to collapse them with. Choosing one to display is a
            // presentation rule that belongs to the consumer.
            'categories'   => self::taxonomyRefListSchema(
                "Every category the post currently belongs to, ordered by slug. Empty when the "
                . 'post has no categories; never null. All assigned categories are published — '
                . 'there is no primary-category designation; selecting one to display is a '
                . 'consumer-side presentation choice.'
            ),
        ]);
    }

    /** Mirrors CategoryResource::toArray() published fields. */
    private function categorySchema(): SchemaObject
    {
        return SchemaObject::object([
            'slug'        => 'string',
            'name'        => 'string',
            'description' => 'string',
            'parent_id'   => 'integer',
            // Stated explicitly because "number of posts" has three plausible readings and the
            // field was published with none of them written down (FLAG-TAGCOUNT-1). This is the
            // semantic the projection has always carried (DECISION AJ (AJ-4)) — not a new one.
            'post_count'  => [
                'type'        => 'integer',
                'description' => "WordPress's own published-post count for this term "
                    . '(`wp_term_taxonomy.count`), projected verbatim. It counts posts WordPress '
                    . 'considers published, so drafts, pending, private, scheduled and trashed '
                    . 'posts are excluded. It is a source fact, not a count of the rows a '
                    . 'listing returns, and it is not a pagination total.',
            ],
        ]);
    }

    /**
     * Mirrors MediaResource::toArray() published fields.
     *
     * `sizes` is an object keyed by registered size name, each value carrying an already
     * resolved absolute url plus width / height / mime_type — consumers never rebuild a
     * filename from upload paths (Rule 6).
     */
    private function mediaSchema(): SchemaObject
    {
        return SchemaObject::object([
            'slug'           => 'string',
            'title'          => 'string',
            'mime_type'      => 'string',
            'url'            => 'string',
            'alt_text'       => 'string',
            'caption'        => 'string',
            'description'    => 'string',
            'width'          => 'integer',
            'height'         => 'integer',
            'sizes'          => self::mediaSizesSchema(),
            'attached_to_id' => 'integer',
            'published_at'   => 'string',
            'updated_at'     => 'string',
            'meta'           => self::openMapSchema(
                'WordPress attachment meta the projection carries. Deliberately OPEN: the key '
                . 'set belongs to the site, not to the published contract.'
            ),
        ]);
    }

    // -------------------------------------------------------------------------
    // Nested published shapes (ADR-055 (c))
    //
    // These are written out EXPLICITLY, exactly as the Resources build them. Nothing here
    // reflects over a Resource or infers a shape from a projection row (ADR-055 (a)) — a
    // nested field that stayed a bare `type: object` would leave a generated consumer type
    // with no way to read data the contract deliberately publishes.
    // -------------------------------------------------------------------------

    /**
     * The canonical public page path — the value `GET /pages/{path}` takes, verbatim.
     *
     * NULLABLE via `type: ['string','null']` (OpenAPI 3.1), and the null is a real contract state,
     * not an oversight: a page whose ancestor was never published has no reconstructable path
     * (DECISION AE), and neither does one caught in a corrupt parent cycle. Such a page is not
     * addressable through `GET /pages/{path}` either, so the contract says so rather than
     * publishing a leaf slug that would 404.
     *
     * @return array<string,mixed>
     */
    private static function pagePathSchema(): array
    {
        return [
            'type'        => ['string', 'null'],
            'description' => 'Canonical public page identity: the full `/`-separated ancestor '
                . 'path (e.g. about/team), usable verbatim as the {path} parameter of '
                . 'GET /pages/{path}. A top-level page publishes its bare slug (e.g. about) — '
                . 'no leading or trailing slash, no host, no /hsp/v1 prefix. Derived from the '
                . 'projected hierarchy at read time, so a parent rename changes every '
                . "descendant's path immediately. Null when the path cannot be reconstructed "
                . 'because an ancestor was never published (DECISION AE); such a page is not '
                . 'addressable, and no leaf-slug substitute is invented.',
        ];
    }
    /**
     * The resolved featured image PostResource/PageResource::featuredMedia() builds, or null.
     *
     * NULLABLE via `type: ['object','null']` (OpenAPI 3.1 / JSON Schema 2020-12): the reference
     * is soft (ADR-013), so no image set, the attachment never projected, and the attachment
     * soft-deleted all read as null. Every property is present whenever the object itself is —
     * the Resource emits all seven unconditionally — so all seven are `required`.
     *
     * @return array<string,mixed>
     */
    private static function featuredMediaSchema(): array
    {
        return [
            'type'        => ['object', 'null'],
            'description' => 'Resolved featured image, or null when no image is set, the '
                . 'attachment never projected, or it was soft-deleted (ADR-013).',
            'properties'  => [
                'slug'      => ['type' => 'string'],
                'url'       => ['type' => 'string', 'description' => 'Absolute URL of the original.'],
                'alt_text'  => ['type' => 'string'],
                'mime_type' => ['type' => 'string'],
                'width'     => ['type' => 'integer'],
                'height'    => ['type' => 'integer'],
                'sizes'     => self::mediaSizesSchema(),
            ],
            'required'    => ['slug', 'url', 'alt_text', 'mime_type', 'width', 'height', 'sizes'],
        ];
    }

    /**
     * Generated size variants: an OPEN map keyed by registered WordPress size name whose VALUES
     * are a fixed, closed shape.
     *
     * `additionalProperties` rather than a property list because the key set is the site's —
     * a theme registers whatever sizes it likes — while every value is the same four fields.
     * Freezing the keys would be wrong on the next site; leaving the whole thing opaque would
     * lose the value shape, which IS platform-owned.
     *
     * @return array<string,mixed>
     */
    private static function mediaSizesSchema(): array
    {
        return [
            'type'                 => 'object',
            'description'          => 'Generated size variants keyed by registered size name. The '
                . 'key set is site-specific; each value carries an already-resolved absolute URL '
                . '(the transformer resolved it write-side — Rule 2).',
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
     * A taxonomy reference list, as PostQueryProvider aggregates it: `{slug, name}` pairs ordered
     * by slug. Always an array — empty when the post carries no such term, never null.
     *
     * ONE shape for tags (P1B-S3) and categories (Finding 003), because they are the same kind of
     * public reference: the slug addresses the term (it is what `?tag=` / `?category=` accept and
     * what a consumer routes on), the name labels it. No identifier of any kind is published —
     * the projection UUID, `source_term_id` and the WordPress term id all stay internal (ADR-040,
     * DECISION AJ AJ-2), and a consumer needs none of them to render a label and a slug route.
     *
     * @return array<string,mixed>
     */
    private static function taxonomyRefListSchema(string $description): array
    {
        return [
            'type'        => 'array',
            'description' => $description,
            'items'       => [
                'type'       => 'object',
                'properties' => [
                    'slug' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                ],
                'required'   => ['slug', 'name'],
            ],
        ];
    }

    /**
     * An INTENTIONALLY OPAQUE map: the key set belongs to the site, not to the published
     * contract, so it is described as an open object rather than frozen into a closed property
     * list that would be wrong on the next install. Opaque by decision, not by omission.
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
