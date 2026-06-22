<?php

declare(strict_types=1);

namespace HSP\Core\Migrations\Connection;

use HSP\Core\Migrations\Exception\MigrationException;

/**
 * MySQL connection adapter for the migration engine, backed by $wpdb.
 *
 * $wpdb is the only safe, available MySQL handle inside WordPress.
 * Direct mysqli usage would bypass WordPress connection management.
 */
final class WpdbMysqlConnection implements ConnectionInterface
{
    public function __construct(private readonly \wpdb $wpdb) {}

    public function execute(string $sql): void
    {
        $sql    = str_replace('{prefix}', $this->wpdb->prefix, $sql);
        $result = $this->wpdb->query($sql);

        if ($result === false) {
            throw new MigrationException(
                "MySQL migration DDL failed: {$this->wpdb->last_error}\nSQL: {$sql}"
            );
        }
    }

    public function query(string $sql, array $params = []): array
    {
        $sql      = str_replace('{prefix}', $this->wpdb->prefix, $sql);
        $prepared = empty($params) ? $sql : $this->prepare($sql, $params);

        $rows = $this->wpdb->get_results($prepared, ARRAY_A);

        if ($rows === null && $this->wpdb->last_error !== '') {
            throw new MigrationException("MySQL query failed: {$this->wpdb->last_error}");
        }

        return $rows ?? [];
    }

    public function insert(string $sql, array $params = []): int
    {
        $sql      = str_replace('{prefix}', $this->wpdb->prefix, $sql);
        $prepared = empty($params) ? $sql : $this->prepare($sql, $params);

        $result = $this->wpdb->query($prepared);

        if ($result === false) {
            throw new MigrationException("MySQL insert failed: {$this->wpdb->last_error}");
        }

        return (int) $result;
    }

    private function prepare(string $sql, array $params): string
    {
        return $this->wpdb->prepare($sql, ...$params);
    }
}
