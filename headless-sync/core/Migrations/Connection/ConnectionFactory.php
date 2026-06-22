<?php

declare(strict_types=1);

namespace HSP\Core\Migrations\Connection;

use HSP\Core\Migrations\Exception\MigrationException;

/**
 * Creates database connection adapters from config arrays.
 *
 * Config shape expected:
 *   'pgsql' => ['host'=>'', 'port'=>5432, 'dbname'=>'', 'user'=>'', 'password'=>'']
 *
 * MySQL is provided via $wpdb (WordPress global); no separate config entry needed.
 */
final class ConnectionFactory
{
    public static function pgsql(array $config): PgsqlConnection
    {
        $dsn = sprintf(
            'host=%s port=%d dbname=%s user=%s password=%s',
            $config['host']     ?? 'localhost',
            (int) ($config['port']     ?? 5432),
            $config['dbname']   ?? '',
            $config['user']     ?? '',
            $config['password'] ?? '',
        );

        $conn = pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            throw new MigrationException("Failed to open PostgreSQL connection with dsn: {$dsn}");
        }

        return new PgsqlConnection($conn);
    }

    public static function wpdbMysql(\wpdb $wpdb): WpdbMysqlConnection
    {
        return new WpdbMysqlConnection($wpdb);
    }
}
