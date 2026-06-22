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
