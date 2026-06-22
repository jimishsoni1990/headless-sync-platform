<?php

declare(strict_types=1);

namespace HSP\Bootstrap;

/**
 * Reads environment variables and applies them as the highest-precedence
 * config layer (Architect Ruling 1: Environment Override > Module Config > Global Config).
 *
 * Secrets must never be stored in wp_options or source code (Doc 10 §9).
 * All secret values must arrive exclusively through environment variables.
 */
final class Environment
{
    private static string $env = 'production';

    public static function load(): void
    {
        self::$env = self::get('HSP_ENV', 'production');
    }

    public static function current(): string
    {
        return self::$env;
    }

    public static function isProduction(): bool
    {
        return self::$env === 'production';
    }

    public static function isDevelopment(): bool
    {
        return self::$env === 'development' || self::$env === 'local';
    }

    /**
     * Read an environment variable with an optional default.
     * All secret retrieval must go through this method — never through wp_options.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return ($value !== null && $value !== false) ? $value : $default;
    }

    /**
     * Return environment overrides to be merged as the top config layer.
     * Keys are dot-notation config paths; values are env-var-sourced scalars.
     *
     * Extend this map as new env-backed config keys are introduced.
     */
    public static function overrides(): array
    {
        $overrides = [];

        $map = [
            'database.mysql.host'         => 'HSP_MYSQL_HOST',
            'database.mysql.port'         => 'HSP_MYSQL_PORT',
            'database.mysql.name'         => 'HSP_MYSQL_NAME',
            'database.mysql.user'         => 'HSP_MYSQL_USER',
            'database.mysql.password'     => 'HSP_MYSQL_PASSWORD',
            'database.pgsql.host'         => 'HSP_PGSQL_HOST',
            'database.pgsql.port'         => 'HSP_PGSQL_PORT',
            'database.pgsql.name'         => 'HSP_PGSQL_NAME',
            'database.pgsql.user'         => 'HSP_PGSQL_USER',
            'database.pgsql.password'     => 'HSP_PGSQL_PASSWORD',
            'queue.visibility_timeout'    => 'HSP_QUEUE_VISIBILITY_TIMEOUT',
            'queue.retry_limit'           => 'HSP_QUEUE_RETRY_LIMIT',
            'logging.level'               => 'HSP_LOG_LEVEL',
            'observability.metrics.enabled' => 'HSP_METRICS_ENABLED',
        ];

        foreach ($map as $configKey => $envVar) {
            $value = self::get($envVar);
            if ($value !== null) {
                $overrides[$configKey] = $value;
            }
        }

        return $overrides;
    }
}
