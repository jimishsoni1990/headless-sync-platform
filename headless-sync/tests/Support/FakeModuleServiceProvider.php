<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

use HSP\Core\Contracts\ServiceProviderInterface;

/**
 * Minimal module service provider for manifest fixtures.
 *
 * Manifests require a `service_provider` class (DECISION AG AG-1) and
 * ModuleProviderComposer instantiates it, so fixtures need a real, zero-argument,
 * ServiceProviderInterface-implementing class to name. This registers nothing —
 * tests that care about bindings use SecondDomain* doubles instead.
 */
final class FakeModuleServiceProvider implements ServiceProviderInterface
{
    public function register(object $container): void {}

    public function boot(object $container): void {}
}
