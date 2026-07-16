<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Admin;

/**
 * Binds the Operations Console to WordPress admin hooks (OPSC-S3 wiring boundary).
 *
 * This is the single place the console attaches to WordPress: the admin menu (`admin_menu`),
 * the two nonce-protected admin-ajax read endpoints the minimal polling JS calls (poll +
 * Playground execute), and the ONE nonce+capability+confirmation-protected action endpoint
 * (OPSC-S4 — Replay/Reconcile). Kept as a thin registrar (mirrors ContentSubscriberRegistrar /
 * ReconciliationCronRegistrar) so the controllers stay free of add_action() calls and remain
 * unit-testable without WordPress.
 *
 * No-op if WordPress's hook functions are unavailable (e.g. unit tests / CLI bootstrap).
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class ConsoleAdminRegistrar
{
    public function __construct(
        private readonly AdminPageController $pages,
        private readonly ConsoleAjaxController $ajax,
        private readonly ConsoleActionController $actions,
    ) {}

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        add_action('admin_menu', $this->pages->registerMenu(...));
        add_action('admin_enqueue_scripts', $this->pages->enqueueAssets(...));

        add_action('wp_ajax_' . ConsoleAjaxController::ACTION_POLL, $this->ajax->handlePoll(...));
        add_action('wp_ajax_' . ConsoleAjaxController::ACTION_EXECUTE, $this->ajax->handleExecute(...));

        // OPSC-S4: the single Replay/Reconcile action endpoint (nonce + capability + confirmation).
        add_action('wp_ajax_' . ConsoleActionController::ACTION_INVOKE, $this->actions->handleInvoke(...));
    }
}
