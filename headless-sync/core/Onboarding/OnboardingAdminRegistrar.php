<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;

/**
 * Binds the Onboarding surface to WordPress admin hooks (ONB-S1a wiring boundary).
 *
 * This is the single place onboarding attaches to WordPress: the admin menu (`admin_menu`) and
 * the asset enqueue (`admin_enqueue_scripts`) for the committed dist/ bundle. Kept as a thin
 * registrar (mirrors ConsoleAdminRegistrar) so OnboardingPageController stays free of
 * add_action() calls and remains unit-testable without WordPress.
 *
 * No-op if WordPress's hook functions are unavailable (e.g. unit tests / CLI bootstrap).
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class OnboardingAdminRegistrar
{
    public function __construct(
        private readonly OnboardingPageController $page,
        private readonly OnboardingStateInterface $state,
    ) {}

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        // First-run gate, the mirror image of ConsoleAdminRegistrar's: onboarding is a
        // FIRST-RUN surface, so it stops registering once it has completed. Without this the
        // wp-admin sidebar keeps a permanent "HSP Onboarding" top-level item next to
        // "HSP Operations" forever — a setup wizard advertising itself long after setup.
        // The two gates are exact complements: exactly one HSP top-level menu exists at a time.
        // Re-running onboarding is deleting the `hsp_onboarding_state` option (DECISION W (d) —
        // completion is that single option, so clearing it restores this surface).
        if ($this->state->isComplete()) {
            return;
        }

        add_action('admin_menu', $this->page->registerMenu(...));
        add_action('admin_enqueue_scripts', $this->page->enqueueAssets(...));
    }
}
