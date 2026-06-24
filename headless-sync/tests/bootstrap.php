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

// ---------------------------------------------------------------------------
// WordPress REST API stubs — for ContentRestRegistrar unit tests.
// ---------------------------------------------------------------------------

if (! class_exists(\WP_REST_Server::class)) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
    }
}

if (! class_exists(\WP_REST_Request::class)) {
    class WP_REST_Request
    {
        private array $params = [];

        public function __construct(array $params = [])
        {
            $this->params = $params;
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }
    }
}

if (! class_exists(\WP_REST_Response::class)) {
    class WP_REST_Response
    {
        public mixed $data;
        public int   $status;

        public function __construct(mixed $data = null, int $status = 200)
        {
            $this->data   = $data;
            $this->status = $status;
        }
    }
}

if (! class_exists(\WP_Error::class)) {
    class WP_Error
    {
        public string $code;
        public string $message;
        public array  $data;

        public function __construct(string $code, string $message = '', array $data = [])
        {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
    }
}

if (! function_exists('rest_ensure_response')) {
    function rest_ensure_response(mixed $data): \WP_REST_Response
    {
        return new \WP_REST_Response($data, 200);
    }
}

if (! function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args): void
    {
        // No-op in unit tests.
    }
}

if (! function_exists('sanitize_title')) {
    function sanitize_title(string $title): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_-]/i', '', $title)));
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('absint')) {
    function absint(mixed $v): int
    {
        return abs((int) $v);
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
