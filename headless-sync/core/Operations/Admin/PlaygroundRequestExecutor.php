<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Admin;

use HSP\Core\Contracts\Operations\EndpointDescriptor;

/**
 * Executes a live delivery-API GET for the API Playground (Doc 12 §15 Request Execution;
 * ADR-050).
 *
 * ADR-050: the Playground validates the delivery API by exercising the PUBLISHED hsp/v1
 * endpoints from the admin UI, hitting the live API CONTRACT (Rule 6 — consumers depend on
 * the contract, not internal schemas). This executor performs an in-process REST dispatch via
 * `rest_do_request()` — no HTTP round-trip, no internal schema access.
 *
 * Safety invariants (read-only console — ADR-053 / DECISION V (a)):
 *   - The client selects an endpoint by its INDEX into the EndpointDescriptor list, never by a
 *     raw route string; the descriptor is re-resolved here from the SAME metadata the UI
 *     rendered, so a request can only target a registered, published endpoint.
 *   - GET only. A descriptor whose method is not GET is rejected. No mutation verb can be
 *     dispatched from the console (DECISION V — read-only; mutation is out of scope entirely).
 *   - The {slug} path param and query params are sanitized before being placed on the request.
 *
 * The endpoint list is supplied by the caller (AdminAjaxController resolves it from
 * EndpointProviderInterface metadata via OperationsService); this class does not hold a
 * provider or a DatabaseConnectionInterface (ADR-053).
 */
final class PlaygroundRequestExecutor
{
    /**
     * Dispatch the selected endpoint and return a plain result array for the Response Viewer.
     *
     * @param EndpointDescriptor[]  $endpoints registered endpoints (from EndpointProviderInterface)
     * @param int                   $index     index into $endpoints (client selection)
     * @param string                $slug      optional sanitized path param for /{slug} routes
     * @param array<string,string>  $query     optional sanitized query parameters
     *
     * @return array{status:int, path:string, body:mixed}
     *
     * @throws \InvalidArgumentException if the index is out of range or the method is not GET.
     */
    public function execute(array $endpoints, int $index, string $slug = '', array $query = []): array
    {
        $endpoints = array_values($endpoints);

        if (! isset($endpoints[$index])) {
            throw new \InvalidArgumentException('Unknown endpoint selection.');
        }

        $endpoint = $endpoints[$index];

        if (strtoupper($endpoint->method) !== 'GET') {
            // Defence in depth: the console is read-only; only GET is dispatchable.
            throw new \InvalidArgumentException('Only GET endpoints may be executed from the console.');
        }

        $path = $this->buildPath($endpoint, $slug);

        $request = new \WP_REST_Request('GET', $path);
        foreach ($query as $key => $value) {
            $request->set_param($key, $value);
        }

        $response = rest_do_request($request);

        return [
            'status' => $response->get_status(),
            'path'   => $path,
            'body'   => $response->get_data(),
        ];
    }

    /**
     * Build the REST route path from the descriptor, substituting the {slug} placeholder.
     *
     * The result is always `/<namespace>/<route>` with any single `{...}` placeholder replaced
     * by the sanitized slug. Routes with no placeholder ignore the slug.
     */
    private function buildPath(EndpointDescriptor $endpoint, string $slug): string
    {
        $route = $endpoint->route;

        if (str_contains($route, '{')) {
            // Replace the first {placeholder} with the sanitized slug (may be empty → 404 upstream).
            $route = preg_replace('/\{[^}]+\}/', rawurlencode($slug), $route, 1) ?? $route;
        }

        return '/' . trim($endpoint->namespace, '/') . $route;
    }
}
