<?php

declare(strict_types=1);

namespace HSP\Bootstrap;

/**
 * Resolves database credentials using the precedence defined in DECISION O (v1.15):
 *
 *   1. PHP define() constant (wp-config.php — highest precedence, idiomatic WP)
 *   2. getenv() fallback (Docker, CI, legacy putenv() callers)
 *   3. Documented default, or hard failure for credentials with no safe default
 *
 * Required PostgreSQL credentials (host, user, password, dbname) throw a
 * \RuntimeException if unresolvable — no silent wrong defaults.
 *
 * MySQL derives from WordPress DB_* constants by default; HSP_MYSQL_* overrides
 * apply only when present. HSP never duplicates WP credentials.
 *
 * Authority: DECISION O (v1.15). ADR-012: injected via constructor; never
 * retrieved via service-locator.
 */
final class CredentialResolver
{
    /**
     * Resolve a single credential by trying define() then getenv(), returning
     * $default if neither yields a non-empty string.
     */
    public function resolve(string $constantName, mixed $default = null): mixed
    {
        if (defined($constantName)) {
            $v = constant($constantName);
            if ($v !== null && $v !== '') {
                return $v;
            }
        }

        $env = getenv($constantName);
        if ($env !== false && $env !== '') {
            return $env;
        }

        return $default;
    }

    /**
     * Resolve a required credential; throws if both sources yield empty/null.
     *
     * @throws \RuntimeException with a clear diagnostic naming the missing credential.
     */
    public function required(string $constantName): string
    {
        $value = $this->resolve($constantName);

        if ($value === null || $value === '') {
            throw new \RuntimeException(
                "HSP: required credential '{$constantName}' is not set. "
                . "Define it in wp-config.php as define('{$constantName}', '…') "
                . "or export it as an environment variable.",
            );
        }

        return (string) $value;
    }

    // -------------------------------------------------------------------------
    // PostgreSQL — DECISION O §(b): required keys fail loud; port defaults to 5432
    // -------------------------------------------------------------------------

    public function pgHost(): string
    {
        return $this->required('HSP_PG_HOST');
    }

    public function pgPort(): int
    {
        return (int) $this->resolve('HSP_PG_PORT', 5432);
    }

    public function pgDbname(): string
    {
        return $this->required('HSP_PG_DBNAME');
    }

    public function pgUser(): string
    {
        return $this->required('HSP_PG_USER');
    }

    public function pgPassword(): string
    {
        return $this->required('HSP_PG_PASSWORD');
    }

    /**
     * Returns a ready-to-use libpq DSN string for the HSP PostgreSQL database.
     * Validation (required checks) runs inside each accessor above.
     */
    public function pgDsn(): string
    {
        return sprintf(
            'host=%s port=%d dbname=%s user=%s password=%s',
            $this->pgHost(),
            $this->pgPort(),
            $this->pgDbname(),
            $this->pgUser(),
            $this->pgPassword(),
        );
    }

    // -------------------------------------------------------------------------
    // MySQL — DECISION O §(c): derive from WP DB_* constants; HSP_MYSQL_* overrides
    // -------------------------------------------------------------------------

    public function mysqlHost(): string
    {
        // HSP_MYSQL_HOST overrides the WP default; if absent, fall through to DB_HOST.
        $override = $this->resolve('HSP_MYSQL_HOST');
        if ($override !== null && $override !== '') {
            return (string) $override;
        }

        // WordPress sets DB_HOST before any plugin runs (DECISION H: wp-config defines
        // are available in the worker process).
        if (defined('DB_HOST') && DB_HOST !== '') {
            return DB_HOST;
        }

        return 'localhost';
    }

    public function mysqlPort(): int
    {
        $override = $this->resolve('HSP_MYSQL_PORT');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        return 3306;
    }

    public function mysqlDbname(): string
    {
        $override = $this->resolve('HSP_MYSQL_NAME');
        if ($override !== null && $override !== '') {
            return (string) $override;
        }

        if (defined('DB_NAME') && DB_NAME !== '') {
            return DB_NAME;
        }

        return '';
    }

    public function mysqlUser(): string
    {
        $override = $this->resolve('HSP_MYSQL_USER');
        if ($override !== null && $override !== '') {
            return (string) $override;
        }

        if (defined('DB_USER') && DB_USER !== '') {
            return DB_USER;
        }

        return '';
    }

    public function mysqlPassword(): string
    {
        $override = $this->resolve('HSP_MYSQL_PASSWORD');
        if ($override !== null && $override !== '') {
            return (string) $override;
        }

        if (defined('DB_PASSWORD')) {
            return (string) DB_PASSWORD;
        }

        return '';
    }
}
