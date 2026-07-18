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

        // ONB-S2 self-remediation: apply the outstanding core + content migrations through the
        // EXISTING engine (DECISION W (e)/(f) v1.23; ADR-054 Principle 8). Gated on the four
        // environment preflight checks at the controller; 409 when they fail. Nonce + capability
        // enforced in the controller.
        register_rest_route(self::NAMESPACE, '/onboarding/migrate', [
            'methods'             => 'POST',
            'callback'            => $this->controller->handleMigrate(...),
            'permission_callback' => '__return_true',
        ]);

        // ONB-S2 self-remediation: nudge WP-Cron so a processing cycle runs and a heartbeat appears
        // (DECISION W (c); DECISION X (4)). Non-blocking spawn — no in-request drain.
        register_rest_route(self::NAMESPACE, '/onboarding/spawn-worker', [
            'methods'             => 'POST',
            'callback'            => $this->controller->handleSpawnWorker(...),
            'permission_callback' => '__return_true',
        ]);

        // ONB-S2: trigger the first-run backfill (thin delegation to reconcileFull re-emission —
        // DECISION W (b)/(c)). Gated on a live worker heartbeat + applied migrations at the
        // controller; 409 with per-gate remediation when blocked.
        register_rest_route(self::NAMESPACE, '/onboarding/backfill', [
            'methods'             => 'POST',
            'callback'            => $this->controller->handleBackfill(...),
            'permission_callback' => '__return_true',
        ]);

        // ONB-S2: derived-on-demand progress poll (DECISION W (d)); flips the completion flag on
        // convergence and reports the redirect to Operations.
        register_rest_route(self::NAMESPACE, '/onboarding/backfill/progress', [
            'methods'             => 'GET',
            'callback'            => $this->controller->handleBackfillProgress(...),
            'permission_callback' => '__return_true',
        ]);
    }
}
