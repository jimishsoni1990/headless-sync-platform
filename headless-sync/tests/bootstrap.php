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
