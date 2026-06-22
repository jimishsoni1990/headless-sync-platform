<?php

declare(strict_types=1);

/**
 * Database connection configuration skeleton.
 * No credentials here — secrets are env-only (Doc 10 §9 / Architect Ruling 1).
 * All credential values arrive exclusively through environment variables.
 */
return [
    'mysql' => [
        'host'    => '',
        'port'    => 3306,
        'name'    => '',
        'user'    => '',
        'password' => '',
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ],
    'pgsql' => [
        'host'     => '',
        'port'     => 5432,
        'name'     => '',
        'user'     => '',
        'password' => '',
        'schema'   => 'system',
        'sslmode'  => 'prefer',
    ],
];
