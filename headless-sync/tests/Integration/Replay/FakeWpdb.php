<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Replay;

/**
 * mysqli-backed wpdb stub for the replay integration test.
 *
 * Supports exactly the surface OutboxWriter + AggregateVersionCounter use against a live
 * MySQL database: `prefix`, `last_error`, insert(), prepare(), query(), get_var(). This
 * lets the real emit path (fresh counter version → wp_hsp_outbox row) run end-to-end
 * without a WordPress bootstrap.
 */
final class FakeWpdb
{
    public string $prefix;
    public string $last_error = '';

    public function __construct(
        private readonly \mysqli $mysqli,
        string $prefix,
    ) {
        $this->prefix = $prefix;
    }

    /**
     * Minimal wpdb::insert() — builds a parameterised INSERT from column => value pairs.
     *
     * @param array<string,mixed> $data
     * @param array<int,string>   $formats  '%s'/'%d' per column (unused for binding type;
     *                                       we bind everything as string, which MySQL coerces).
     */
    public function insert(string $table, array $data, array $formats = []): int|false
    {
        $columns      = array_keys($data);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $colList      = '`' . implode('`, `', $columns) . '`';

        $sql  = "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})";
        $stmt = $this->mysqli->prepare($sql);

        if ($stmt === false) {
            $this->last_error = $this->mysqli->error;
            return false;
        }

        $values = array_values($data);
        $types  = str_repeat('s', count($values));
        // Bind all as strings; NULLs are passed through as-is.
        $stmt->bind_param($types, ...$values);

        if (! $stmt->execute()) {
            $this->last_error = $stmt->error;
            $stmt->close();
            return false;
        }

        $stmt->close();
        return 1;
    }

    /** wpdb::prepare() — substitute %s/%d placeholders (counter SQL only). */
    public function prepare(string $sql, mixed ...$args): string
    {
        foreach ($args as $arg) {
            $escaped = $this->mysqli->real_escape_string((string) $arg);
            $sql     = preg_replace('/%[sd]/', "'{$escaped}'", $sql, 1);
        }
        return $sql;
    }

    public function query(string $sql): mixed
    {
        $result = $this->mysqli->query($sql);
        if ($result === false) {
            $this->last_error = $this->mysqli->error;
            return false;
        }
        return 1;
    }

    public function get_var(string $sql): mixed
    {
        $result = $this->mysqli->query($sql);
        if ($result === false) {
            return null;
        }
        $row = $result->fetch_row();
        $result->free();
        return $row[0] ?? null;
    }
}
