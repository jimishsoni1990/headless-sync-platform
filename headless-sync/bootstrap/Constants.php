<?php

declare(strict_types=1);

namespace HSP\Bootstrap;

final class Constants
{
    public static function define(string $pluginFile): void
    {
        if (! defined('HSP_VERSION')) {
            define('HSP_VERSION', Version::CURRENT);
        }

        if (! defined('HSP_PLUGIN_FILE')) {
            define('HSP_PLUGIN_FILE', $pluginFile);
        }

        if (! defined('HSP_PLUGIN_DIR')) {
            define('HSP_PLUGIN_DIR', plugin_dir_path($pluginFile));
        }

        if (! defined('HSP_PLUGIN_URL')) {
            define('HSP_PLUGIN_URL', plugin_dir_url($pluginFile));
        }

        if (! defined('HSP_CONFIG_DIR')) {
            define('HSP_CONFIG_DIR', HSP_PLUGIN_DIR . 'config/');
        }

        if (! defined('HSP_STORAGE_DIR')) {
            define('HSP_STORAGE_DIR', HSP_PLUGIN_DIR . 'storage/');
        }
    }
}
