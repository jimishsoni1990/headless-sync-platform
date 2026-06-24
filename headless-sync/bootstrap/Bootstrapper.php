<?php

declare(strict_types=1);

namespace HSP\Bootstrap;

use HSP\Core\Configuration\ConfigLoader;
use HSP\Core\Container\ContainerBuilder;

/**
 * Wires together the startup sequence: load environment, build config, build container.
 *
 * This is the composition root. Container::get() MAY be called here to pull
 * top-level entry points out of the container; ADR-012 prohibits it inside
 * business logic, not here (CLAUDE.md note on bootstrap composition root).
 */
final class Bootstrapper
{
    public function __construct(
        private readonly ConfigLoader $configLoader,
        private readonly ContainerBuilder $containerBuilder,
    ) {}

    public function bootstrap(string $modulesBasePath = ''): \HSP\Core\Container\Container
    {
        Environment::load();

        $config = $this->configLoader->load();

        return $this->containerBuilder->build($config, $modulesBasePath);
    }
}
