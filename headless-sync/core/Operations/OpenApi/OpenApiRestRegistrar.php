<?php

declare(strict_types=1);

namespace HSP\Core\Operations\OpenApi;

use HSP\Core\Rest\DeliveryErrorBoundary;

/**
 * Registers `GET /hsp/v1/openapi.json` with WordPress (ADR-055 (d); DECISION N).
 *
 * The single place the openapi.json route attaches to WordPress. Bound to `rest_api_init`. Kept
 * a thin registrar (mirrors ContentRestRegistrar / OnboardingRestRegistrar) so the controller
 * stays free of register_rest_route() calls and remains unit-testable without WordPress.
 *
 * The route is PUBLIC (ADR-055 (d)/(e)): permission_callback is permissive and there is no nonce
 * — the generated document is the public delivery contract, discoverable unauthenticated (Rule 6).
 * WPCS at the REST boundary: the route registration is the WordPress entry point; the response is
 * a pure array passed through rest_ensure_response (WP JSON-encodes it — no manual escaping owed),
 * and the endpoint reads no request input to sanitize.
 *
 * No-op when WordPress's REST functions are unavailable (unit tests / CLI bootstrap).
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class OpenApiRestRegistrar
{
    public const NAMESPACE = 'hsp/v1';

    public function __construct(
        private readonly OpenApiRestController $controller,
        // CCF-003: the contract endpoint is held to the contract it publishes. Generation is a pure
        // array transformation, but it reads the aggregated registry, so a provider that throws
        // would otherwise fatal this route. Guarded like every other delivery callback, and the
        // descriptor declares 500 accordingly.
        private readonly DeliveryErrorBoundary $errorBoundary,
    ) {
    }

    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::NAMESPACE, '/openapi.json', [
            'methods'             => 'GET',
            'callback'            => $this->errorBoundary->guard($this->controller->handle(...)),
            // Public delivery contract (ADR-055 (d)/(e)) — no capability check inside generation.
            'permission_callback' => '__return_true',
        ]);
    }
}
