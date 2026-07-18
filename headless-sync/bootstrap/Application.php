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

        // ADR-054 / Doc 8 v2.0 §2b/§24 (Zero-Configuration Operation, Principle 8): scheduling
        // the processing-cycle WP-Cron event on activation is what makes "activate and
        // synchronization begins" true — nothing outside WordPress is provisioned. Reconciliation
        // events are scheduled here too for consistency. Both registrars are wp_next_scheduled-
        // guarded, so scheduling is idempotent.
        $container = $this->container;
        if ($container !== null) {
            $container->get(\HSP\Core\Workers\ProcessingCronRegistrar::class)->ensureScheduled();
            $container->get(\HSP\Core\Reconciliation\ReconciliationCronRegistrar::class)->register();
        }

        // Module activation lifecycle is owned by P0-S3 (module registry).
    }

    public function deactivate(): void
    {
        // ADR-054: clear the scheduled processing-cycle event on deactivation
        // (wp_clear_scheduled_hook). Reconciliation events are cleared here too for symmetry.
        if (function_exists('wp_clear_scheduled_hook')) {
            \wp_clear_scheduled_hook(\HSP\Core\Workers\ProcessingCronRegistrar::HOOK);
            \wp_clear_scheduled_hook(\HSP\Core\Reconciliation\ReconciliationCronRegistrar::HOOK_DRIFT);
            \wp_clear_scheduled_hook(\HSP\Core\Reconciliation\ReconciliationCronRegistrar::HOOK_INCREMENTAL);
            \wp_clear_scheduled_hook(\HSP\Core\Reconciliation\ReconciliationCronRegistrar::HOOK_FULL);
        }

        // Module deactivation lifecycle is owned by P0-S3.
    }

    public function getContainer(): ?Container
    {
        return $this->container;
    }
}
