<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OnboardingServiceProvider;
use HSP\Core\Onboarding\OnboardingAdminRegistrar;
use HSP\Core\Onboarding\OnboardingPageController;
use PHPUnit\Framework\TestCase;

/**
 * Wiring smoke for the Onboarding surface (ONB-S1a; ADR-012 / Rule 7).
 *
 * Proves the OnboardingServiceProvider bindings resolve as singletons via constructor injection,
 * with no service-locator call and no PostgreSQL handle in the graph (ONB-S1a is frontend +
 * mount only). The provider is exercised in isolation on a fresh Container — no DB required.
 */
final class OnboardingWiringTest extends TestCase
{
    public function test_bindings_resolve_via_constructor_injection_as_singletons(): void
    {
        $container = new Container();
        (new OnboardingServiceProvider())->register($container);

        $page = $container->get(OnboardingPageController::class);
        self::assertInstanceOf(OnboardingPageController::class, $page);

        $registrar = $container->get(OnboardingAdminRegistrar::class);
        self::assertInstanceOf(OnboardingAdminRegistrar::class, $registrar);

        // Singletons: same instance on re-resolution.
        self::assertSame($page, $container->get(OnboardingPageController::class));
        self::assertSame($registrar, $container->get(OnboardingAdminRegistrar::class));
    }

    public function test_registrar_register_is_a_safe_no_op_without_wordpress_hooks(): void
    {
        $container = new Container();
        (new OnboardingServiceProvider())->register($container);

        $registrar = $container->get(OnboardingAdminRegistrar::class);

        // add_action is stubbed as a no-op in tests/bootstrap.php; register() must not throw.
        $registrar->register();

        $this->addToAssertionCount(1);
    }
}
