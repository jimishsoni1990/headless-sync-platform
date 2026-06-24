<?php

declare(strict_types=1);

namespace HSP\Bootstrap;

use HSP\Core\Configuration\ConfigLoader;
use HSP\Core\Container\Container;
use HSP\Core\Container\ContainerBuilder;

/**
 * Plugin application singleton.
 *
 * Singleton scope is justified here: WordPress's hook system is a global bus,
 * and we need exactly one application instance per request to own the container.
 * No business logic lives in this class; it is the plugin entry point only.
 *
 * The container may be accessed here (composition root). ADR-012 prohibits
 * Container::get() inside business logic — not at the wiring root.
 */
final class Application
{
    private static ?self $instance = null;
    private ?Container $container = null;
    private bool $booted = false;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $configLoader    = new ConfigLoader(
            defined('HSP_CONFIG_DIR') ? HSP_CONFIG_DIR : __DIR__ . '/../config/',
        );
        $containerBuilder = new ContainerBuilder();
        $bootstrapper     = new Bootstrapper($configLoader, $containerBuilder);

        $modulesBasePath = defined('HSP_PLUGIN_DIR') ? HSP_PLUGIN_DIR . 'modules/' : '';
        $this->container = $bootstrapper->bootstrap($modulesBasePath);

        // Run the module lifecycle: discover → register → boot (FLAG-P1AS6-2 Gap A fix).
        // The composition root may call container->get() — ADR-012 prohibits it in
        // business logic, not here.
        $this->container->get('module.registrar')->registerAll();
    }

    public function activate(): void
    {
        // Activation hook fires before plugins_loaded; boot if not already done.
        if (! $this->booted) {
            $this->boot();
        }

        // Module activation lifecycle is owned by P0-S3 (module registry).
        // Nothing to do here until the registry exists.
    }

    public function deactivate(): void
    {
        // Module deactivation lifecycle is owned by P0-S3.
    }

    public function getContainer(): ?Container
    {
        return $this->container;
    }
}
