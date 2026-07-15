<?php

declare(strict_types=1);

namespace HSP\Core\Operations\UI;

use HSP\Core\Contracts\Operations\EndpointDescriptor;

/**
 * Pure server-side renderer for the API Playground (Doc 12 §15; ADR-050 — MVP nav item 2).
 *
 * Renders the four Playground subsystems as server-rendered PHP (DECISION V (a)):
 *   - Endpoint Explorer — the list of published hsp/v1 endpoints, from EndpointDescriptor
 *     metadata supplied by EndpointProviderInterface (no hardcoding — ADR-050/ADR-052).
 *   - Request Builder    — a form to pick an endpoint + optional {slug}/query params.
 *   - Request Execution  — a submit button; execution is a nonce-protected AJAX GET handled
 *     server-side by the AdminAjaxController (read-only, GET only — ADR-050 / DECISION N/F).
 *   - Response Viewer    — a target region the minimal vanilla JS fills with the response.
 *
 * This class is PURE: it takes EndpointDescriptor[] and plain wiring values (ajax URL, nonce)
 * and returns escaped HTML. No infrastructure, no DatabaseConnectionInterface, no live GET
 * happens here (ADR-053). Every dynamic value is escaped at output through {@see Html}
 * (DECISION V (b)).
 */
final class PlaygroundView
{
    /**
     * @param EndpointDescriptor[] $endpoints the published endpoints to explore
     * @param string $ajaxUrl  the admin-ajax endpoint the vanilla JS posts to (already a URL)
     * @param string $nonce    the nonce the JS sends with each execute request
     * @param string $action   the wp_ajax action name for execution
     */
    public function render(array $endpoints, string $ajaxUrl, string $nonce, string $action): string
    {
        $html  = '<div class="hsp-ops-playground"';
        $html .= ' data-ajax-url="' . Html::attr($ajaxUrl) . '"';
        $html .= ' data-nonce="' . Html::attr($nonce) . '"';
        $html .= ' data-action="' . Html::attr($action) . '">';

        $html .= $this->explorer($endpoints);
        $html .= $this->builder($endpoints);
        $html .= $this->responseViewer();

        $html .= '</div>';

        return $html;
    }

    /**
     * Endpoint Explorer — the discoverable list, grouped by display group.
     *
     * @param EndpointDescriptor[] $endpoints
     */
    private function explorer(array $endpoints): string
    {
        $html = '<section class="hsp-ops-explorer"><h3>' . Html::text('Endpoint Explorer') . '</h3>';

        if ($endpoints === []) {
            $html .= '<p class="hsp-ops-empty">' . Html::text('No endpoints registered.') . '</p></section>';

            return $html;
        }

        $html .= '<table class="hsp-ops-table"><thead><tr>'
            . '<th>' . Html::text('Group') . '</th>'
            . '<th>' . Html::text('Method') . '</th>'
            . '<th>' . Html::text('Route') . '</th>'
            . '<th>' . Html::text('Description') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($endpoints as $endpoint) {
            $html .= '<tr>'
                . '<td>' . Html::text($endpoint->displayGroup) . '</td>'
                . '<td>' . Html::text($endpoint->method) . '</td>'
                . '<td><code>' . Html::text('/' . trim($endpoint->namespace, '/') . $endpoint->route) . '</code></td>'
                . '<td>' . Html::text($endpoint->description) . '</td>'
                . '</tr>';
        }

        $html .= '</tbody></table></section>';

        return $html;
    }

    /**
     * Request Builder + Execution control.
     *
     * The <select> is keyed by the endpoint index so the server (AdminAjaxController)
     * re-resolves the descriptor from the SAME EndpointProviderInterface metadata — the
     * client never supplies a raw route string that could target an arbitrary path.
     *
     * @param EndpointDescriptor[] $endpoints
     */
    private function builder(array $endpoints): string
    {
        $html  = '<section class="hsp-ops-builder"><h3>' . Html::text('Request Builder') . '</h3>';
        $html .= '<form class="hsp-ops-request-form" onsubmit="return false;">';

        $html .= '<label>' . Html::text('Endpoint') . ' ';
        $html .= '<select class="hsp-ops-endpoint" name="endpoint">';
        foreach ($endpoints as $index => $endpoint) {
            $label = $endpoint->method . ' /' . trim($endpoint->namespace, '/') . $endpoint->route;
            $html .= '<option value="' . Html::attr((string) $index) . '">'
                . Html::text($label) . '</option>';
        }
        $html .= '</select></label>';

        // Optional path parameter (for /{slug} routes) — sanitized server-side.
        $html .= '<label>' . Html::text('Slug (for single-item routes)') . ' ';
        $html .= '<input type="text" class="hsp-ops-slug" name="slug" value="" '
            . 'placeholder="' . Html::attr('e.g. hello-world') . '"></label>';

        // Optional query string — parsed and re-encoded server-side.
        $html .= '<label>' . Html::text('Query (key=value&key2=value2)') . ' ';
        $html .= '<input type="text" class="hsp-ops-query" name="query" value="" '
            . 'placeholder="' . Html::attr('e.g. limit=5') . '"></label>';

        $html .= '<button type="button" class="button button-primary hsp-ops-execute">'
            . Html::text('Execute') . '</button>';

        $html .= '</form></section>';

        return $html;
    }

    private function responseViewer(): string
    {
        return '<section class="hsp-ops-response"><h3>' . Html::text('Response') . '</h3>'
            . '<div class="hsp-ops-response-meta"></div>'
            . '<pre class="hsp-ops-response-body">' . Html::text('Execute a request to see the response.') . '</pre>'
            . '</section>';
    }
}
