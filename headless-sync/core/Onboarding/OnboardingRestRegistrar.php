<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

/**
 * Registers the onboarding REST routes the React app calls (ONB-S1b; DECISION W (a); DECISION V (b)).
 *
 * The single place onboarding attaches REST routes to WordPress. Bound to `rest_api_init`. Kept
 * as a thin registrar (mirrors ContentRestRegistrar / OnboardingAdminRegistrar) so the controller
 * stays free of register_rest_route() calls and remains unit-testable without WordPress.
 *
 * Every route's args declare sanitize callbacks; the controller additionally verifies nonce +
 * capability at the JSON boundary (DECISION W (a) — the React client is untrusted). No-op when
 * WordPress's REST functions are unavailable (unit tests / CLI bootstrap).
 *
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class OnboardingRestRegistrar
{
    public const NAMESPACE = 'hsp/v1';

    public function __construct(
        private readonly OnboardingRestController $controller,
    ) {}

    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::NAMESPACE, '/onboarding/preflight', [
            'methods'             => 'GET',
            'callback'            => $this->controller->handlePreflight(...),
            // The controller enforces nonce + capability itself; permission_callback stays
            // permissive so the controller can return precise 401/403 WP_Error responses.
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NAMESPACE, '/onboarding/complete', [
            'methods'             => 'POST',
            'callback'            => $this->controller->handleComplete(...),
            'permission_callback' => '__return_true',
        ]);
    }
}
