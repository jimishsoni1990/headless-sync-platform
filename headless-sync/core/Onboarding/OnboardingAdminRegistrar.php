<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

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
    ) {}

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', $this->page->registerMenu(...));
        add_action('admin_enqueue_scripts', $this->page->enqueueAssets(...));
    }
}
