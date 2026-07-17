<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Admin;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OperationsServiceProvider;
use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\Admin\ConsoleActionController;
use HSP\Core\Operations\Admin\ConsoleAdminRegistrar;
use HSP\Core\Operations\Admin\ConsoleAjaxController;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use HSP\Tests\Unit\Operations\Fakes\WiringTestBindings;
use PHPUnit\Framework\TestCase;

/**
 * Nav-gating for the whole Operations Console admin surface (ONB-S1b; DECISION W (f)).
 *
 * Proves the gate is REGISTRATION-LEVEL and covers not just the menu pages but the admin-ajax
 * endpoints that back them — so the API Playground execute path is genuinely inaccessible before
 * onboarding completes, matching Operations. add_action records registered hook names into
 * $GLOBALS['_hsp_stub_actions'] (opt-in) so we can assert exactly which hooks were attached.
 */
final class ConsoleAdminRegistrarGatingTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_actions'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_actions'], $GLOBALS['_hsp_stub_options']);
    }

    public function test_registers_nothing_for_the_console_until_onboarding_completes(): void
    {
        $GLOBALS['_hsp_stub_options'] = [
            OnboardingStateInterface::OPTION_NAME => OnboardingStateInterface::PENDING,
        ];

        $this->registrar()->register();

        // No menu, no enqueue, and crucially NO wp_ajax_* endpoints (poll / Playground execute /
        // action) are registered while onboarding is incomplete.
        self::assertSame([], $GLOBALS['_hsp_stub_actions']);
    }

    public function test_registers_the_full_console_surface_once_onboarding_is_complete(): void
    {
        $GLOBALS['_hsp_stub_options'] = [
            OnboardingStateInterface::OPTION_NAME => OnboardingStateInterface::COMPLETE,
        ];

        $this->registrar()->register();

        $hooks = $GLOBALS['_hsp_stub_actions'];
        self::assertContains('admin_menu', $hooks);
        self::assertContains('admin_enqueue_scripts', $hooks);
        self::assertContains('wp_ajax_' . ConsoleAjaxController::ACTION_POLL, $hooks);
        self::assertContains('wp_ajax_' . ConsoleAjaxController::ACTION_EXECUTE, $hooks);
        self::assertContains('wp_ajax_' . ConsoleActionController::ACTION_INVOKE, $hooks);
    }

    private function registrar(): ConsoleAdminRegistrar
    {
        $container = new Container();
        $container->instance(DatabaseConnectionInterface::class, new ScriptedReaderConnection());
        WiringTestBindings::registerActionDependencies($container);

        // Bind the onboarding state from the (test-controlled) WP option, not the WiringTestBindings
        // default, so this test drives the gate via $GLOBALS['_hsp_stub_options'].
        $container->singleton(
            OnboardingStateInterface::class,
            static fn () => new \HSP\Core\Onboarding\OnboardingState(),
        );

        $provider = new OperationsServiceProvider(['worker' => []]);
        $provider->register($container);
        $provider->boot($container);

        return $container->get(ConsoleAdminRegistrar::class);
    }
}
