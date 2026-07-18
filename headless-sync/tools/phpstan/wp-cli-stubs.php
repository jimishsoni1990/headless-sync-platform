<?php

/**
 * Minimal WP-CLI stubs for static analysis only.
 *
 * WP-CLI symbols are not shipped by php-stubs/wordpress-stubs (they live in the
 * separate wp-cli runtime, absent on a normal WordPress request). The HSP CLI
 * registrars call them behind a `defined('WP_CLI') && WP_CLI` guard, so they are
 * unresolved at analysis time and produce class.notFound / function.notFound noise
 * that is not a real defect. These declarations let PHPStan resolve the calls.
 *
 * This file is referenced ONLY from phpstan.neon.dist `scanFiles`; it is never
 * autoloaded at runtime (not in composer autoload, not required by any source).
 * Declarations are unconditional because PHPStan ignores symbols declared inside
 * runtime `if (! class_exists(...))` guards when collecting stub symbols.
 */

declare(strict_types=1);

// phpcs:disable

namespace {
    class WP_CLI
    {
        /** @param mixed $callable @param array<mixed> $args */
        public static function add_command(string $name, $callable, array $args = []): void {}
        public static function success(string $message): void {}
        /** @param bool|int $exit */
        public static function error(string $message, $exit = true): void {}
        public static function warning(string $message): void {}
        public static function log(string $message): void {}
        public static function line(string $message = ''): void {}
    }
}

namespace WP_CLI\Utils {
    /**
     * @param string               $format
     * @param array<mixed>         $items
     * @param string|array<string> $fields
     */
    function format_items($format, $items, $fields): void {}
}
