<?php

declare(strict_types=1);

namespace HSP\Core\Container\Definitions;

use HSP\Bootstrap\Version;
use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Onboarding\OnboardingAdminRegistrar;
use HSP\Core\Onboarding\OnboardingPageController;

/**
 * Registers the Onboarding / First-Run surface (ONB-S1a; DECISION W (a)/(e); ADR-012).
 *
 * ONB-S1a is the React/shadcn toolchain bootstrap + onboarding page skeleton. Bindings (all
 * singletons, constructor-injected — ADR-012 / Rule 7):
 *   OnboardingPageController  — the wp-admin boundary: registers ONE capability-gated page,
 *                               renders the React mount shell, enqueues the committed dist/
 *                               bundle (no host build step — DECISION W (a)).
 *   OnboardingAdminRegistrar  — thin hook registrar (admin_menu + admin_enqueue_scripts).
 *
 * Placement is core/Onboarding/ (NOT core/Operations/) so the observability-only console
 * (DECISION V (j)) is untouched (DECISION W (e)). This provider opens NO PG handle, adds NO
 * pg_* wrapper, and touches NO schema — ONB-S1a is frontend + mount only.
 */
final class OnboardingServiceProvider extends ServiceProvider
{
    public function register(object $container): void
    {
        assert($container instanceof Container);

        $container->singleton(
            OnboardingPageController::class,
            fn () => new OnboardingPageController(Version::CURRENT),
        );

        $container->singleton(
            OnboardingAdminRegistrar::class,
            fn (Container $c) => new OnboardingAdminRegistrar(
                $c->get(OnboardingPageController::class),
            ),
        );
    }
}
