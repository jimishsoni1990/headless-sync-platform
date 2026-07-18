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
        // When a test opts in by initialising $GLOBALS['_hsp_stub_actions'] = [], registered
        // hook names are recorded so registration-gating can be asserted (ONB-S1b nav gate).
        if (isset($GLOBALS['_hsp_stub_actions']) && is_array($GLOBALS['_hsp_stub_actions'])) {
            $GLOBALS['_hsp_stub_actions'][] = $hook;
        }
    }
}

// ---------------------------------------------------------------------------
// WordPress WP-Cron stubs — for ProcessingCronRegistrar / activation-scheduling tests.
// A test opts in to recording by initialising the $GLOBALS keys below.
//   $GLOBALS['_hsp_stub_filters']   = [];  // records add_filter() hook names
//   $GLOBALS['_hsp_stub_scheduled'] = [];  // hook => schedule (wp_schedule_event / clear)
// wp_next_scheduled() returns the scheduled hook's marker if present, else false.
// ---------------------------------------------------------------------------

if (! defined('DAY_IN_SECONDS'))  { define('DAY_IN_SECONDS', 86400); }
if (! defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): void
    {
        if (isset($GLOBALS['_hsp_stub_filters']) && is_array($GLOBALS['_hsp_stub_filters'])) {
            $GLOBALS['_hsp_stub_filters'][] = $hook;
        }
    }
}

if (! function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook)
    {
        if (isset($GLOBALS['_hsp_stub_scheduled']) && is_array($GLOBALS['_hsp_stub_scheduled'])) {
            return array_key_exists($hook, $GLOBALS['_hsp_stub_scheduled'])
                ? (time() + 60)
                : false;
        }
        return false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook)
    {
        if (isset($GLOBALS['_hsp_stub_scheduled']) && is_array($GLOBALS['_hsp_stub_scheduled'])) {
            $GLOBALS['_hsp_stub_scheduled'][$hook] = $recurrence;
        }
        return true;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook)
    {
        if (isset($GLOBALS['_hsp_stub_scheduled']) && is_array($GLOBALS['_hsp_stub_scheduled'])) {
            unset($GLOBALS['_hsp_stub_scheduled'][$hook]);
        }
        return 0;
    }
}

// spawn_cron() stub — records a non-blocking WP-Cron spawn for WorkerCronSpawner tests.
//   $GLOBALS['_hsp_stub_spawn_cron_calls'] = 0;  // opt in to counting
if (! function_exists('spawn_cron')) {
    function spawn_cron(int $gmt_time = 0)
    {
        if (isset($GLOBALS['_hsp_stub_spawn_cron_calls'])) {
            $GLOBALS['_hsp_stub_spawn_cron_calls']++;
        }
        return true;
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
        private string $method = 'GET';
        private string $route  = '';

        /**
         * Flexible stub matching two call shapes used in the suite:
         *   new WP_REST_Request(['key' => 'val'])            — array-first (legacy REST tests)
         *   new WP_REST_Request('GET', '/hsp/v1/posts')      — method+route (real WP signature)
         */
        public function __construct(array|string $first = [], string $route = '')
        {
            if (is_array($first)) {
                $this->params = $first;
            } else {
                $this->method = $first;
                $this->route  = $route;
            }
        }

        /** @var array<string,string> case-insensitive header store (lower-cased keys) */
        private array $headers = [];

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function set_param(string $key, mixed $value): void
        {
            $this->params[$key] = $value;
        }

        public function set_header(string $key, string $value): void
        {
            $this->headers[strtolower($key)] = $value;
        }

        public function get_header(string $key): ?string
        {
            return $this->headers[strtolower($key)] ?? null;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }

        /** @return array<string,mixed> */
        public function get_params(): array
        {
            return $this->params;
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

        public function get_status(): int
        {
            return $this->status;
        }

        public function get_data(): mixed
        {
            return $this->data;
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

// ---------------------------------------------------------------------------
// WordPress escaping + admin + AJAX stubs — for Operations Console (OPSC-S3) tests.
// The Html helper prefers WP's esc_* when present; these stubs let the WP-boundary
// controllers/executor be unit-tested without loading WordPress. sanitize_key /
// wp_unslash mirror WP behaviour closely enough for the boundary assertions.
// ---------------------------------------------------------------------------

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $url) ?? '';

        return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'http://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string
    {
        return 'nonce-' . $action;
    }
}

if (! function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return 'http://example.test/wp-json/' . ltrim($path, '/');
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        // Default allow; tests override via $GLOBALS['_hsp_stub_current_user_can'].
        return $GLOBALS['_hsp_stub_current_user_can'] ?? true;
    }
}

if (! function_exists('check_ajax_referer')) {
    function check_ajax_referer(string|int $action = -1, string|false $query_arg = false, bool $die = true): int|false
    {
        $GLOBALS['_hsp_stub_check_ajax_referer'][] = [$action, $query_arg];

        return 1;
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string|int $action = -1): int|false
    {
        // Tests override validity via $GLOBALS['_hsp_stub_valid_nonce'] (default valid).
        return ($GLOBALS['_hsp_stub_valid_nonce'] ?? true) ? 1 : false;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['_hsp_stub_options'][$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value): bool
    {
        $GLOBALS['_hsp_stub_options'][$option] = $value;

        return true;
    }
}

if (! function_exists('rest_do_request')) {
    function rest_do_request(\WP_REST_Request $request): \WP_REST_Response
    {
        $handler = $GLOBALS['_hsp_stub_rest_do_request'] ?? null;
        if (is_callable($handler)) {
            return $handler($request);
        }

        return new \WP_REST_Response(null, 200);
    }
}

if (! function_exists('add_menu_page')) {
    function add_menu_page(...$args): string
    {
        $GLOBALS['_hsp_stub_menu_pages'][] = $args;

        return (string) ($args[3] ?? '');
    }
}

/**
 * Test-only signal used to unwind wp_send_json_success/error/wp_die without process exit,
 * so console AJAX handlers can be asserted. Carries the JSON-ish payload + status.
 */
if (! class_exists(\HSP\Tests\Support\WpJsonHalt::class)) {
    // Declared under a test namespace so it does not collide with anything shipped.
    eval('namespace HSP\\Tests\\Support; final class WpJsonHalt extends \\RuntimeException {
        public bool $success;
        public mixed $payload;
        public int $statusCode;
        public function __construct(bool $success, mixed $payload, int $statusCode) {
            parent::__construct("wp json halt");
            $this->success = $success; $this->payload = $payload; $this->statusCode = $statusCode;
        }
    }');
}

if (! function_exists('wp_send_json_success')) {
    function wp_send_json_success(mixed $data = null, int $status_code = 200): void
    {
        throw new \HSP\Tests\Support\WpJsonHalt(true, $data, $status_code);
    }
}

if (! function_exists('wp_send_json_error')) {
    function wp_send_json_error(mixed $data = null, int $status_code = 200): void
    {
        throw new \HSP\Tests\Support\WpJsonHalt(false, $data, $status_code);
    }
}

if (! function_exists('wp_die')) {
    function wp_die(string|\WP_Error $message = '', string|int $title = '', array|string|int $args = []): never
    {
        throw new \HSP\Tests\Support\WpJsonHalt(false, ['wp_die' => (string) $message], 403);
    }
}

if (! function_exists('add_submenu_page')) {
    function add_submenu_page(...$args): string
    {
        $GLOBALS['_hsp_stub_submenu_pages'][] = $args;

        return (string) ($args[4] ?? '');
    }
}

if (! function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], mixed $ver = false, string $media = 'all'): void
    {
        $GLOBALS['_hsp_stub_enqueued_styles'][$handle] = $src;
    }
}

if (! function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], mixed $ver = false, bool $in_footer = false): void
    {
        $GLOBALS['_hsp_stub_enqueued_scripts'][$handle] = $src;
    }
}

if (! function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool
    {
        $GLOBALS['_hsp_stub_localized'][$handle] = [$object_name, $l10n];

        return true;
    }
}

if (! function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'http://example.test/wp-content/plugins/headless-sync/' . ltrim($path, '/');
    }
}
