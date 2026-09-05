<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Operations;

use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Core\Contracts\Operations\SchemaObject;

/**
 * Module-owned endpoint metadata for the Content module's hsp/v1 routes (Doc 12 §15; ADR-050;
 * ADR-055).
 *
 * Implements the core-owned EndpointProviderInterface (Rule 5) and populates the ADR-055 (c)
 * enriched descriptors for the eight published content endpoints (DECISION N: 'hsp/v1'): parameters
 * (DECISION F filters + cursor pagination — Doc 9 §13), published request/response shapes (Rule 6
 * — the fields the Resources expose, NOT internal content.* / canonical columns), auth requirement
 * (all eight are PUBLIC — Doc 9 §22), deprecation (none at MVP — Doc 9 §26), version (v1 — Doc 9 §7),
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
                EndpointParameter::query('status', 'string', 'Filter by post status (public set: publish).'),
                EndpointParameter::query('published_after', 'string', 'ISO-8601 UTC lower bound on published_at.'),
            ],
        );
    }

    private function pageSingle(): EndpointDescriptor
    {
        return $this->single('/pages/{slug}', 'Fetch a single page by slug.', $this->pageSchema());
    }

    // -------------------------------------------------------------------------
    // Posts
    // -------------------------------------------------------------------------

    private function postsList(): EndpointDescriptor
    {
        return $this->listing(
            route: '/posts',
            description: 'List published posts (cursor-paginated).',
            itemSchema: $this->postSchema(),
            filters: [
                EndpointParameter::query('status', 'string', 'Filter by post status (public set: publish).'),
                EndpointParameter::query('category', 'string', 'Filter by category slug.'),
                EndpointParameter::query('published_after', 'string', 'ISO-8601 UTC lower bound on published_at.'),
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
                EndpointParameter::query('published_after', 'string', 'ISO-8601 UTC lower bound on published_at.'),
            ],
        );
    }

    private function mediaSingle(): EndpointDescriptor
    {
        return $this->single('/media/{slug}', 'Fetch a single media item by slug.', $this->mediaSchema());
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
            EndpointParameter::query('per_page', 'integer', 'Page size (1–100).'),
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
        );
    }

    /** A single-resource-by-slug endpoint: one required path param, the bare resource shape. */
    private function single(string $route, string $description, SchemaObject $itemSchema): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: $route,
            namespace: self::NAMESPACE,
            displayGroup: 'Content',
            description: $description,
            parameters: [EndpointParameter::path('slug', 'string', 'Resource slug.')],
            responseSchema: $itemSchema,
            requestSchema: null,
            auth: EndpointAuth::Public,
            paginated: false,
            deprecated: false,
            version: 'v1',
            moduleOwner: self::MODULE,
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
            'title'        => 'string',
            'content'      => 'string',
            'status'       => 'string',
            'parent_id'    => 'integer',
            'menu_order'   => 'integer',
            'published_at' => 'string',
            'updated_at'   => 'string',
            'meta'         => 'object',
            // Resolved featured image, or null (P1B-S2). Nullable because the reference is soft
            // (ADR-013): no image set, never projected, or soft-deleted all read as null.
            'featured_media' => 'object',
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
            'meta'         => 'object',
            // Resolved featured image, or null (P1B-S2). Nullable because the reference is soft
            // (ADR-013): no image set, never projected, or soft-deleted all read as null.
            'featured_media' => 'object',
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
            'post_count'  => 'integer',
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
            'sizes'          => 'object',
            'attached_to_id' => 'integer',
            'published_at'   => 'string',
            'updated_at'     => 'string',
            'meta'           => 'object',
        ]);
    }
}
