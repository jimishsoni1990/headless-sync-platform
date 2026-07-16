<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

/**
 * The wp-admin boundary for the Onboarding / First-Run surface (ONB-S1a; DECISION W (a)/(e);
 * DECISION V (b); ADR-012).
 *
 * ONB-S1a scope is the React/shadcn frontend toolchain + the mount seam only. This controller:
 *   - registers ONE capability-gated "HSP Onboarding" wp-admin page,
 *   - renders a server-side shell that carries the single React mount element, and
 *   - enqueues the committed dist/ assets (built in dev/CI; no host build step — DECISION W (a))
 *     and localizes a bootstrap payload (nonce + REST base + version) for the client.
 *
 * WordPress entry point, so WPCS security applies (DECISION V (b)): the render enforces the page
 * capability and every dynamic value is escaped at output; the nonce is created here for the
 * REST/ajax endpoints ONB-S1b will add (the untrusted-client JSON boundary — DECISION W (a)).
 *
 * Placement is core/Onboarding/ (NOT core/Operations/) so the observability-only console
 * (DECISION V (j)) stays separate from this lifecycle/setup surface (DECISION W (e)). This
 * controller opens NO PostgreSQL handle and introduces NO pg_* wrapper (DECISION K reuse; L
 * Ruling 0; E) — ONB-S1a performs no PG read at all.
 *
 * Constructor injection only (ADR-012 / Rule 7).
 */
final class OnboardingPageController
{
    /** The onboarding menu/page slug. */
    public const MENU_SLUG = 'hsp-onboarding';

    /** The DOM id the React app mounts on (matches resources/admin-ui/src/main.tsx). */
    public const MOUNT_ID = 'hsp-admin-ui-root';

    /** Committed build artifacts (stable, non-hashed Vite filenames — DECISION W (a)). */
    private const DIST_JS  = 'resources/admin-ui/dist/hsp-onboarding.js';
    private const DIST_CSS = 'resources/admin-ui/dist/hsp-onboarding.css';

    private const SCRIPT_HANDLE = 'hsp-admin-ui';
    private const STYLE_HANDLE  = 'hsp-admin-ui';

    /** JS object name the localized bootstrap is exposed under (matches src/bootstrap.ts). */
    private const BOOTSTRAP_OBJECT = 'HSP_ONBOARDING';

    /** Nonce action for the REST/ajax endpoints ONB-S1b will call. */
    public const NONCE_ACTION = 'wp_rest';

    public function __construct(
        private readonly string $version,
        private readonly string $capability = 'manage_options',
    ) {}

    /**
     * Register the onboarding admin page. Bound to `admin_menu` at the wiring site.
     */
    public function registerMenu(): void
    {
        add_menu_page(
            __('HSP Onboarding', 'headless-sync'),
            __('HSP Onboarding', 'headless-sync'),
            $this->capability,
            self::MENU_SLUG,
            $this->render(...),
            'dashicons-admin-generic',
        );
    }

    /**
     * Render the onboarding page shell (server-side). The React app takes over the mount node.
     */
    public function render(): void
    {
        $this->guard();

        // The React app renders inside this mount element; the shell is intentionally minimal.
        // The mount id is escaped defensively even though it is a constant.
        echo '<div class="wrap"><div id="' . esc_attr(self::MOUNT_ID) . '"></div></div>';
    }

    /**
     * Enqueue the committed dist/ assets for the onboarding page (DECISION W (a)).
     *
     * Bound to `admin_enqueue_scripts`. Loads only on the onboarding page. The script is
     * localized with the bootstrap payload (nonce + REST base + version) so the React client can
     * call the WPCS-guarded endpoints ONB-S1b adds. No build step runs on the host — these are
     * pre-built, committed files.
     *
     * @param string $hookSuffix current admin page hook suffix (WP passes this to the hook).
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if (! str_contains($hookSuffix, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_style(self::STYLE_HANDLE, $this->assetUrl(self::DIST_CSS), [], $this->version);
        wp_enqueue_script(self::SCRIPT_HANDLE, $this->assetUrl(self::DIST_JS), [], $this->version, true);

        wp_localize_script(self::SCRIPT_HANDLE, self::BOOTSTRAP_OBJECT, [
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'restUrl' => $this->restBase(),
            'version' => $this->version,
        ]);
    }

    /**
     * Enforce the page capability at the admin boundary (WPCS capability check — DECISION V (b)).
     */
    private function guard(): void
    {
        if (! current_user_can($this->capability)) {
            wp_die(esc_html__('You do not have permission to view this page.', 'headless-sync'));
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
            return plugins_url($relPath, dirname(__DIR__, 2) . '/headless-sync.php');
        }

        return $relPath;
    }

    /**
     * REST base URL for the HSP onboarding endpoints ONB-S1b will register.
     */
    private function restBase(): string
    {
        if (function_exists('rest_url')) {
            return rest_url('hsp/v1/');
        }

        return '';
    }
}
