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
     * Read a configuration value by constant name, following DECISION O precedence:
     *   1. PHP define() constant (wp-config.php — highest precedence)
     *   2. getenv() / $_ENV / $_SERVER fallback
     *   3. $default
     *
     * All secret retrieval must go through this method — never through wp_options.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // define() wins — idiomatic WP plugin configuration (DECISION O v1.15).
        if (defined($key)) {
            $v = constant($key);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        $value = getenv($key);

        if ($value === false) {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return ($value !== null && $value !== false) ? $value : $default;
    }

    /**
     * Return overrides to be merged as the top config layer (dot-notation keys).
     *
     * DB credentials are NOT in this map — all credential resolution goes through
     * CredentialResolver (DECISION O v1.15: define()→getenv()→default), injected
     * into every service provider that needs a DB connection. The config array
     * carries no credential values.
     *
     * Extend this map as new env-backed non-credential config keys are introduced.
     */
    public static function overrides(): array
    {
        $overrides = [];

        $map = [
            'queue.visibility_timeout'      => 'HSP_QUEUE_VISIBILITY_TIMEOUT',
            'queue.retry_limit'             => 'HSP_QUEUE_RETRY_LIMIT',
            'logging.level'                 => 'HSP_LOG_LEVEL',
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
