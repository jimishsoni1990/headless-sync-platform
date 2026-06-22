<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events\Outbox;

/**
 * Minimal $wpdb stub for OutboxWriter and AggregateVersionCounter unit tests.
 *
 * Does not extend wpdb — WordPress is not loaded in unit tests.
 * Only the methods called by the classes under test are implemented.
 */
final class FakeWpdb extends \wpdb
{
    public string $prefix     = 'wp_';
    public string $last_error = '';

    /** Pre-seeded return value for get_var(). */
    public mixed $nextVarResult = null;

    /** Tracks calls to query(). */
    public array $queryCalls = [];

    /** Tracks calls to insert(). */
    public array $insertCalls = [];

    /** When true, query() returns false to simulate a failure. */
    public bool $failNextQuery = false;

    /** When true, insert() returns false to simulate a failure. */
    public bool $failNextInsert = false;

    public function prepare(string $sql, mixed ...$args): string
    {
        // Simple placeholder substitution sufficient for test assertions.
        foreach ($args as $arg) {
            $quoted = is_string($arg) ? "'{$arg}'" : (string) $arg;
            $sql    = preg_replace('/%[sd]/', $quoted, $sql, 1);
        }
        return $sql;
    }

    public function query(string $sql): mixed
    {
        $this->queryCalls[] = $sql;

        if ($this->failNextQuery) {
            $this->failNextQuery = false;
            $this->last_error    = 'Simulated query failure';
            return false;
        }

        return 1;
    }

    public function get_var(string $sql): mixed
    {
        return $this->nextVarResult;
    }

    public function insert(string $table, array $data, array $format = []): mixed
    {
        $this->insertCalls[] = ['table' => $table, 'data' => $data];

        if ($this->failNextInsert) {
            $this->failNextInsert = false;
            $this->last_error     = 'Simulated insert failure';
            return false;
        }

        return 1;
    }
}
