<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Onboarding\OnboardingPageController;
use HSP\Tests\Support\WpJsonHalt;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OnboardingPageController — the wp-admin onboarding boundary (ONB-S1a;
 * DECISION W (a)/(e); DECISION V (b); ADR-012).
 *
 * The controller registers ONE capability-gated page, renders a React mount shell, and enqueues
 * the committed dist/ bundle. add_menu_page / current_user_can / wp_die / wp_enqueue_* /
 * wp_localize_script are stubbed in tests/bootstrap.php so registration, capability enforcement,
 * and asset enqueue are assertable without WordPress. ONB-S1a opens no PG handle — there is no
 * DatabaseConnectionInterface anywhere in this controller's construction.
 */
final class OnboardingPageControllerTest extends TestCase
{
    private OnboardingPageController $controller;

    protected function setUp(): void
    {
        $this->controller = new OnboardingPageController('9.9.9');

        $GLOBALS['_hsp_stub_current_user_can'] = true;
        $GLOBALS['_hsp_stub_menu_pages']       = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_hsp_stub_current_user_can'],
            $GLOBALS['_hsp_stub_menu_pages'],
            $GLOBALS['_hsp_stub_enqueued_styles'],
            $GLOBALS['_hsp_stub_enqueued_scripts'],
            $GLOBALS['_hsp_stub_localized'],
        );
    }

    public function test_registers_a_single_capability_gated_menu_page(): void
    {
        $this->controller->registerMenu();

        self::assertCount(1, $GLOBALS['_hsp_stub_menu_pages']);
        // add_menu_page(title, menu_title, capability, slug, callback, icon)
        $menu = $GLOBALS['_hsp_stub_menu_pages'][0];
        self::assertSame('manage_options', $menu[2]);
        self::assertSame(OnboardingPageController::MENU_SLUG, $menu[3]);
    }

    public function test_registers_with_a_custom_capability_when_configured(): void
    {
        $controller = new OnboardingPageController('9.9.9', 'manage_hsp');
        $controller->registerMenu();

        $menu = $GLOBALS['_hsp_stub_menu_pages'][0];
        self::assertSame('manage_hsp', $menu[2]);
    }

    public function test_renders_the_react_mount_shell_server_side(): void
    {
        ob_start();
        $this->controller->render();
        $html = (string) ob_get_clean();

        // The single mount element the React app attaches to.
        self::assertStringContainsString('id="' . OnboardingPageController::MOUNT_ID . '"', $html);
        self::assertStringContainsString('class="wrap"', $html);
    }

    public function test_denies_rendering_without_the_page_capability(): void
    {
        $GLOBALS['_hsp_stub_current_user_can'] = false;

        $this->expectException(WpJsonHalt::class); // wp_die stub throws WpJsonHalt
        $this->controller->render();
    }

    public function test_enqueues_committed_dist_assets_and_localizes_the_bootstrap(): void
    {
        $GLOBALS['_hsp_stub_enqueued_styles']  = [];
        $GLOBALS['_hsp_stub_enqueued_scripts'] = [];
        $GLOBALS['_hsp_stub_localized']        = [];

        // WP passes the page hook suffix; the onboarding menu slug appears in it.
        $this->controller->enqueueAssets('toplevel_page_' . OnboardingPageController::MENU_SLUG);

        // Committed, non-hashed dist/ filenames (DECISION W (a)).
        self::assertArrayHasKey('hsp-admin-ui', $GLOBALS['_hsp_stub_enqueued_styles']);
        self::assertArrayHasKey('hsp-admin-ui', $GLOBALS['_hsp_stub_enqueued_scripts']);
        self::assertStringContainsString(
            'resources/admin-ui/dist/hsp-onboarding.css',
            $GLOBALS['_hsp_stub_enqueued_styles']['hsp-admin-ui'],
        );
        self::assertStringContainsString(
            'resources/admin-ui/dist/hsp-onboarding.js',
            $GLOBALS['_hsp_stub_enqueued_scripts']['hsp-admin-ui'],
        );

        // The bootstrap payload the React client reads (nonce + REST base + version).
        self::assertArrayHasKey('hsp-admin-ui', $GLOBALS['_hsp_stub_localized']);
        [$objectName, $data] = $GLOBALS['_hsp_stub_localized']['hsp-admin-ui'];
        self::assertSame('HSP_ONBOARDING', $objectName);
        self::assertArrayHasKey('nonce', $data);
        self::assertArrayHasKey('restUrl', $data);
        self::assertSame('9.9.9', $data['version']);
    }

    public function test_does_not_enqueue_assets_on_unrelated_admin_pages(): void
    {
        $GLOBALS['_hsp_stub_enqueued_scripts'] = [];
        $GLOBALS['_hsp_stub_enqueued_styles']  = [];

        $this->controller->enqueueAssets('edit.php');

        self::assertSame([], $GLOBALS['_hsp_stub_enqueued_scripts']);
        self::assertSame([], $GLOBALS['_hsp_stub_enqueued_styles']);
    }
}
