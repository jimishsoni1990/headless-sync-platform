<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Operations;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;

/**
 * Module-owned endpoint metadata for the Content module's hsp/v1 routes (Doc 12 §15; ADR-050).
 *
 * Implements the core-owned EndpointProviderInterface (Rule 5). Returns READ-ONLY descriptors
 * for the six published content endpoints (DECISION N: 'hsp/v1') so the API Playground (OPSC-S3)
 * can list and describe them WITHOUT hardcoding (ADR-050/ADR-052). ADR-038: no HTTP/framework
 * types cross this contract — plain metadata only.
 *
 * Static metadata (no query). It IS an OperationsProviderInterface, so it may be registered
 * with the RefreshCoordinator like the other providers; its "snapshot" is just its descriptor
 * list. NAMESPACE mirrors ContentRestRegistrar::NAMESPACE (DECISION N) — kept in sync by hand
 * since that constant is private; a drift here is display-only and caught by the endpoint test.
 */
final class ContentEndpointProvider implements EndpointProviderInterface
{
    public const KEY = 'content.endpoints';

    private const NAMESPACE = 'hsp/v1';

    public function key(): string
    {
        return self::KEY;
    }

    /** @return EndpointDescriptor[] */
    public function endpoints(): array
    {
        return [
            $this->get('/pages', 'List published pages (cursor-paginated).'),
            $this->get('/pages/{slug}', 'Fetch a single page by slug.'),
            $this->get('/posts', 'List published posts (cursor-paginated).'),
            $this->get('/posts/{slug}', 'Fetch a single post by slug.'),
            $this->get('/categories', 'List categories.'),
            $this->get('/categories/{slug}', 'Fetch a single category by slug.'),
        ];
    }

    private function get(string $route, string $description): EndpointDescriptor
    {
        return new EndpointDescriptor(
            method: 'GET',
            route: $route,
            namespace: self::NAMESPACE,
            displayGroup: 'Content',
            description: $description,
        );
    }
}
