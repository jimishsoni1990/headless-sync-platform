<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Admin;

use HSP\Core\Operations\Diagnostics\ModuleInspector;
use HSP\Core\Operations\Diagnostics\SystemInformationProvider;
use HSP\Core\Operations\Services\OperationsService;
use HSP\Core\Operations\UI\DashboardView;
use HSP\Core\Operations\UI\Html;
use HSP\Core\Operations\UI\PlaygroundView;

/**
 * The wp-admin boundary for the Operations Console (OPSC-S3; DECISION V (a)/(b); ADR-053).
 *
 * Registers the console's admin menu pages (from the Page/Navigation registries via
 * OperationsService) and renders them server-side. This is a WordPress entry point, so WPCS
 * security applies (DECISION V (b)): every page render enforces the page's required capability
 * and every dynamic value is escaped at output (through the {@see Html} helper used by the
 * views).
 *
 * ADR-053 (the single UI-facing seam): this controller talks ONLY to OperationsService (widgets
 * + provider snapshots) and to the two directly-queried diagnostics services designated for the
 * UI in OPSC-S2 — SystemInformationProvider and ModuleInspector. It is never constructed with,
 * and never reaches, a DatabaseConnectionInterface, the OperationsQueryReader, or any concrete
 * provider. All PG reads live behind OperationsService / those services on the delivery handle.
 *
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class AdminPageController
{
    /** Parent menu slug + the two MVP page slugs (Doc 12 §6). */
    public const MENU_SLUG        = 'hsp-operations';
    public const PAGE_OPERATIONS  = 'operations';
    public const PAGE_PLAYGROUND  = 'api-playground';

    public function __construct(
        private readonly OperationsService $operations,
        private readonly SystemInformationProvider $systemInfo,
        private readonly ModuleInspector $modules,
        private readonly DashboardView $dashboardView,
        private readonly PlaygroundView $playgroundView,
        private readonly ConsoleAjaxController $ajax,
    ) {}

    /**
     * Register the admin menu + submenus. Bound to `admin_menu` at the wiring site.
     *
     * The parent menu and each submenu take the capability recorded on the corresponding
     * ConsolePage descriptor (discovered via OperationsService — never hardcoded here).
     */
    public function registerMenu(): void
    {
        $operationsCap = $this->capabilityFor(self::PAGE_OPERATIONS, 'manage_options');

        add_menu_page(
            __('HSP Operations', 'headless-sync'),
            __('HSP Operations', 'headless-sync'),
            $operationsCap,
            self::MENU_SLUG,
            $this->renderOperations(...),
            'dashicons-controls-repeat',
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Operations', 'headless-sync'),
            __('Operations', 'headless-sync'),
            $operationsCap,
            self::MENU_SLUG,
            $this->renderOperations(...),
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('API Playground', 'headless-sync'),
            __('API Playground', 'headless-sync'),
            $this->capabilityFor(self::PAGE_PLAYGROUND, 'manage_options'),
            self::MENU_SLUG . '-playground',
            $this->renderPlayground(...),
        );
    }

    /**
     * Render the Operations dashboard page (server-side).
     */
    public function renderOperations(): void
    {
        $this->guard(self::PAGE_OPERATIONS);

        $widgets   = $this->operations->widgetsForPage(self::PAGE_OPERATIONS);
        $snapshots = $this->operations->refreshAll();
        $system    = $this->systemInfo->snapshot();
        $modules   = $this->modules->all();

        $body = $this->dashboardView->render($widgets, $snapshots, $system, $modules);

        echo $this->page(__('HSP Operations', 'headless-sync'), $body); // phpcs:ignore WordPress.Security.EscapeOutput -- $body is fully escaped by the view via Html.
    }

    /**
     * Render the API Playground page (server-side).
     */
    public function renderPlayground(): void
    {
        $this->guard(self::PAGE_PLAYGROUND);

        $endpoints = $this->operations->endpointDescriptors();

        $body = $this->playgroundView->render(
            $endpoints,
            $this->ajax->url(),
            $this->ajax->nonce(),
            ConsoleAjaxController::ACTION_EXECUTE,
        );

        echo $this->page(__('API Playground', 'headless-sync'), $body); // phpcs:ignore WordPress.Security.EscapeOutput -- $body is fully escaped by the view via Html.
    }

    /**
     * Enqueue the console's static assets for the current admin page (DECISION V (a)).
     *
     * Bound to `admin_enqueue_scripts`. Assets are hand-authored, self-contained files
     * discovered from the Asset Registry via OperationsService — no bundle, no build step. The
     * script is localized with the nonce + ajax action so the minimal vanilla JS can poll the
     * nonce-protected admin endpoint (the only client behaviour — DECISION V (a)).
     *
     * @param string $hookSuffix the current admin page hook (WP passes this to the hook).
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        // Only load on our console pages. Menu hook suffixes contain the page slugs.
        $onOperations = str_contains($hookSuffix, self::MENU_SLUG);
        $onPlayground = str_contains($hookSuffix, self::MENU_SLUG . '-playground')
            || str_contains($hookSuffix, self::PAGE_PLAYGROUND);

        if (! $onOperations && ! $onPlayground) {
            return;
        }

        $pageSlug = $onPlayground ? self::PAGE_PLAYGROUND : self::PAGE_OPERATIONS;

        foreach ($this->operations->assetsForPage($pageSlug) as $asset) {
            $url = $this->assetUrl($asset->relPath);

            if ($asset->type === \HSP\Core\Contracts\Operations\ConsoleAsset::TYPE_STYLE) {
                wp_enqueue_style($asset->handle, $url, [], null);
            } else {
                wp_enqueue_script($asset->handle, $url, [], null, true);
                wp_localize_script($asset->handle, 'HSP_OPS', [
                    'ajaxUrl'             => $this->ajax->url(),
                    'nonce'               => $this->ajax->nonce(),
                    'pollAction'          => ConsoleAjaxController::ACTION_POLL,
                    'executeAction'       => ConsoleAjaxController::ACTION_EXECUTE,
                    'pollIntervalSeconds' => 15,
                ]);
            }
        }
    }

    /**
     * Resolve a plugin-relative asset path to a URL at the WordPress boundary.
     */
    private function assetUrl(string $relPath): string
    {
        if (defined('HSP_PLUGIN_URL')) {
            return HSP_PLUGIN_URL . ltrim($relPath, '/');
        }

        if (function_exists('plugins_url')) {
            return plugins_url($relPath, dirname(__DIR__, 3) . '/headless-sync.php');
        }

        return $relPath;
    }

    /**
     * Enforce the page capability at the admin boundary (WPCS capability check — DECISION V (b)).
     */
    private function guard(string $pageSlug): void
    {
        $capability = $this->capabilityFor($pageSlug, 'manage_options');

        if (! current_user_can($capability)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'headless-sync'));
        }
    }

    /**
     * The capability recorded on a ConsolePage descriptor, or a safe default if the page is
     * not (yet) registered. Discovered via OperationsService — never hardcoded per page.
     */
    private function capabilityFor(string $pageSlug, string $default): string
    {
        foreach ($this->operations->pages() as $page) {
            if ($page->slug === $pageSlug && $page->capability !== '') {
                return $page->capability;
            }
        }

        return $default;
    }

    /**
     * Wrap page body in the standard wrap markup. Title is escaped; $body is view HTML that is
     * already escaped at output by the views (through {@see Html}).
     */
    private function page(string $title, string $body): string
    {
        return '<div class="wrap hsp-ops"><h1>' . Html::text($title) . '</h1>' . $body . '</div>';
    }
}
