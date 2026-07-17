<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;

/**
 * REST endpoints the React onboarding app calls (ONB-S1b; DECISION W (a)/(d)/(f); DECISION V (b)).
 *
 * The React client is UNTRUSTED, so WPCS security applies at this JSON boundary (DECISION W (a)
 * extends DECISION V (b) to the endpoints the React app calls): every endpoint verifies the wp
 * REST nonce, checks the operator capability, and sanitizes input before use.
 *
 * Two endpoints (registered under hsp/v1 by {@see OnboardingRestRegistrar}):
 *   GET  onboarding/preflight — run the five hard-blocking checks (DECISION W (f)) and return
 *                               their results plus the current onboarding state. Read-only.
 *   POST onboarding/complete  — flip `hsp_onboarding_state` to COMPLETE, the completion-flag
 *                               round-trip (DECISION W (d)). GUARDED: refuses unless preflight
 *                               passes. This performs NO backfill and triggers NO state-changing
 *                               content action — the full-reconciliation backfill is ONB-S2. It
 *                               only writes the WP option (no schema change, no PG write).
 *
 * Delegate-only (DECISION W (e)): the controller holds the PreflightRunner and OnboardingState —
 * it opens no PG handle (the runner's DB checks reuse the delivery handle) and touches no schema.
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class OnboardingRestController
{
    /** Capability required for every onboarding endpoint. */
    private const CAPABILITY = 'manage_options';

    /** Nonce action WordPress uses for REST requests (matches the localized `wp_rest` nonce). */
    private const NONCE_ACTION = 'wp_rest';

    public function __construct(
        private readonly PreflightRunner $preflight,
        private readonly OnboardingStateInterface $state,
    ) {}

    /**
     * GET onboarding/preflight — return the preflight summary + current onboarding state.
     */
    public function handlePreflight(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        $summary = $this->preflight->summary();

        return rest_ensure_response([
            'state'  => $this->state->current(),
            'ok'     => $summary['ok'],
            'checks' => $summary['checks'],
        ]);
    }

    /**
     * POST onboarding/complete — mark onboarding complete (guarded on preflight passing).
     *
     * This is the completion-flag round-trip (DECISION W (d)); it writes only the WP option. It
     * is NOT the backfill trigger (ONB-S2) — no content re-emission, no PG write happens here.
     */
    public function handleComplete(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $denied = $this->authorize($request);
        if ($denied !== null) {
            return $denied;
        }

        if (! $this->preflight->allPassed()) {
            return new \WP_Error(
                'hsp_preflight_failed',
                __('Onboarding prerequisites are not met. Resolve all preflight checks first.', 'headless-sync'),
                ['status' => 409]
            );
        }

        $this->state->markComplete();

        return rest_ensure_response([
            'state' => $this->state->current(),
            'ok'    => true,
        ]);
    }

    /**
     * Verify nonce + capability at the JSON boundary (WPCS — DECISION V (b) / W (a)).
     *
     * Returns a WP_Error (401/403) to short-circuit the handler, or null when authorized. The
     * nonce arrives in the standard `X-WP-Nonce` header (WP maps it to the `_wpnonce` param).
     */
    private function authorize(\WP_REST_Request $request): ?\WP_Error
    {
        $nonce = (string) ($request->get_header('X-WP-Nonce') ?? $request->get_param('_wpnonce') ?? '');
        $nonce = sanitize_text_field($nonce);

        if ($nonce === '' || ! wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return new \WP_Error(
                'hsp_invalid_nonce',
                __('Invalid or missing security token.', 'headless-sync'),
                ['status' => 401]
            );
        }

        if (! current_user_can(self::CAPABILITY)) {
            return new \WP_Error(
                'hsp_forbidden',
                __('You do not have permission to perform this action.', 'headless-sync'),
                ['status' => 403]
            );
        }

        return null;
    }
}
