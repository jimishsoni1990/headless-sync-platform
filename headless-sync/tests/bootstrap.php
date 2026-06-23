<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap — loads Composer autoloader and defines WordPress stubs
 * needed by unit tests that reference $wpdb without loading WordPress itself.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ---------------------------------------------------------------------------
// WordPress stubs — only what HSP core classes reference directly.
// ---------------------------------------------------------------------------

if (! class_exists(\wpdb::class)) {
    /**
     * Minimal wpdb stub for unit tests.
     * Only the methods called by AggregateVersionCounter and OutboxWriter are present.
     */
    class wpdb
    {
        public string $prefix     = 'wp_';
        public string $last_error = '';

        public function prepare(string $sql, mixed ...$args): string { return $sql; }
        public function query(string $sql): mixed                    { return false; }
        public function get_var(string $sql): mixed                  { return null; }
        public function insert(string $table, array $data, array $format = []): mixed { return false; }
    }
}

// ---------------------------------------------------------------------------
// WordPress global function stubs — for HookWiring unit tests.
// Only the functions called by HookWiring are stubbed; tests override as needed
// by redefining the global $hspTestStubs array.
// ---------------------------------------------------------------------------

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): void
    {
        // No-op in unit tests — hook registration is verified via direct method calls.
    }
}

if (! function_exists('get_post')) {
    function get_post(int $postId): ?object
    {
        return $GLOBALS['_hsp_stub_get_post'][$postId] ?? null;
    }
}

if (! function_exists('wp_is_post_revision')) {
    function wp_is_post_revision(int $postId): bool
    {
        return $GLOBALS['_hsp_stub_is_revision'][$postId] ?? false;
    }
}

if (! function_exists('wp_is_post_autosave')) {
    function wp_is_post_autosave(int $postId): bool
    {
        return $GLOBALS['_hsp_stub_is_autosave'][$postId] ?? false;
    }
}
