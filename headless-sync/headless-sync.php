<?php

declare(strict_types=1);

/**
 * Plugin Name: Headless Sync Platform
 * Plugin URI:  https://github.com/hsp/headless-sync
 * Description: Event-driven WordPress → PostgreSQL sync pipeline for headless delivery.
 * Version:     0.1.0
 * Author:      HSP
 * License:     Proprietary
 * Text Domain: headless-sync
 * Requires PHP: 8.1
 */

if (! defined('ABSPATH')) {
    exit;
}

// Require the pgsql extension — the entire sync pipeline depends on it.
if (! extension_loaded('pgsql')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . '<strong>Headless Sync Platform:</strong> '
            . 'The <code>pgsql</code> PHP extension is not loaded. '
            . 'Enable <code>extension=php_pgsql.dll</code> (Windows) or <code>extension=pgsql.so</code> (Linux/macOS) '
            . 'in your <code>php.ini</code> and restart the web server.</p></div>';
    });
    return;
}

// Composer autoload — all HSP\ namespaces resolved via PSR-4.
$autoloader = __DIR__ . '/vendor/autoload.php';
if (! file_exists($autoloader)) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p><strong>Headless Sync Platform:</strong> '
            . 'Run <code>composer dump-autoload</code> in the plugin directory before activating.</p></div>';
    });
    return;
}
require_once $autoloader;

use HSP\Bootstrap\Application;
use HSP\Bootstrap\Constants;

Constants::define(__FILE__);

$application = Application::getInstance();

register_activation_hook(__FILE__, [$application, 'activate']);
register_deactivation_hook(__FILE__, [$application, 'deactivate']);

add_action('plugins_loaded', [$application, 'boot'], 5);

// WP-Cron: register the Processing Engine trigger (ADR-054 — WP-Cron is the ONLY v1.x
// execution mechanism). The recurring `hsp_processing_cycle` event fires ONE bounded
// Processing Engine cycle per tick (relay → dispatch → projection → maintenance → clean
// exit); a backlog larger than one cycle is continued by the next tick. No daemon, no
// supervisor. Runs in normal web requests too so the schedule exists and the callback is
// bound; for reliable cadence point a system cron at `wp cron event run --due-now`.
add_action('plugins_loaded', static function () use ($application): void {
    $container = $application->getContainer();
    if ($container === null) {
        return;
    }

    $container->get(HSP\Core\Workers\ProcessingCronRegistrar::class)->register();
}, 20);

// WP-Cron: register reconciliation triggers (DECISION U point 5). Runs in normal web
// requests too (not WP-CLI-only) so the schedules exist and callbacks are bound. The cron
// callback only TRIGGERS a pass on the worker-bootstrapped process — it never processes PG.
add_action('plugins_loaded', static function () use ($application): void {
    $container = $application->getContainer();
    if ($container === null) {
        return;
    }

    $cron = $container->get(HSP\Core\Reconciliation\ReconciliationCronRegistrar::class);
    $cron->register();
}, 20);

// Operations Console (OPSC-S3): bind the wp-admin menu pages and the nonce-protected
// admin-ajax poll/execute endpoints. Read-only console (DECISION V (a)/(b); ADR-053) — no
// state-changing action is registered. Runs after boot (priority 20) so the container exists.
// ConsoleAdminRegistrar no-ops outside a WordPress runtime.
add_action('plugins_loaded', static function () use ($application): void {
    $container = $application->getContainer();
    if ($container === null) {
        return;
    }

    $container->get(HSP\Core\Operations\Admin\ConsoleAdminRegistrar::class)->register();
}, 20);

// Onboarding / First-Run (ONB-S1a/S1b): bind the wp-admin onboarding page (enqueuing the committed
// React dist/ bundle). Runs after boot (priority 20) so the container exists. The admin registrar
// no-ops outside a WordPress runtime. (The onboarding REST routes attach via the core REST-registrar
// funnel below, alongside the OpenAPI endpoint.)
add_action('plugins_loaded', static function () use ($application): void {
    $container = $application->getContainer();
    if ($container === null) {
        return;
    }

    $container->get(HSP\Core\Onboarding\OnboardingAdminRegistrar::class)->register();
}, 20);

// Core REST registrars — booted through the SINGLE authoritative RestRegistrarRegistry list so the
// OpenAPI drift guard (ADR-055 (f)) enumerates the same set production registers (omission is
// structurally impossible — a new core registrar is added to that ONE list and both production and
// the guard pick it up). Covers GET /hsp/v1/openapi.json (public + stateless — ADR-055 (d)/(e))
// and the onboarding hsp/v1/onboarding/* endpoints (WPCS-guarded — DECISION W (a); exempted from
// the completeness assertion by the frozen onboarding prefix). Each registrar attaches on
// rest_api_init and no-ops outside a WordPress runtime. Module REST routes (Content) attach via the
// module lifecycle (ContentModule::boot()), not here.
add_action('plugins_loaded', static function () use ($application): void {
    $container = $application->getContainer();
    if ($container === null) {
        return;
    }

    add_action('rest_api_init', static function () use ($container): void {
        foreach (HSP\Core\Rest\RestRegistrarRegistry::coreRegistrarKeys() as $registrarKey) {
            $container->get($registrarKey)->register();
        }
    });
}, 20);

// WP-CLI: register `hsp dlq …` and `hsp status` (DECISION S clause (d), DECISION Q).
// Runs after boot (priority 20) so the container is available. No-op outside WP-CLI.
if (defined('WP_CLI') && WP_CLI) {
    add_action('plugins_loaded', static function () use ($application): void {
        $container = $application->getContainer();
        if ($container === null) {
            return;
        }

        $registrar = new HSP\Core\Cli\WpCliDlqRegistrar(
            $container->get(HSP\Core\Cli\DlqCommand::class),
            $container->get(HSP\Core\Observability\OperationalMetricsQuery::class),
        );
        $registrar->register();

        // WP-CLI: register `hsp replay entity|range` (DECISION T). WP-CLI only.
        $replayRegistrar = new HSP\Core\Cli\WpCliReplayRegistrar(
            $container->get(HSP\Core\Cli\ReplayCommand::class),
        );
        $replayRegistrar->register();

        // WP-CLI: register `hsp reconcile drift|incremental|full|status` (DECISION U). WP-CLI only.
        $reconcileRegistrar = new HSP\Core\Cli\WpCliReconcileRegistrar(
            $container->get(HSP\Core\Cli\ReconcileCommand::class),
        );
        $reconcileRegistrar->register();
    }, 20);
}
