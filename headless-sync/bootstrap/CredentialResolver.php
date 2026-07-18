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
        // HSP_MYSQL_HOST overrides the WP default; if absent, derive from DB_HOST.
        $override = $this->resolve('HSP_MYSQL_HOST');
        if ($override !== null && $override !== '') {
            // An override string may itself carry host:port / host:socket (mirrors WP).
            return $this->parseDbHost((string) $override)['host'];
        }

        // WordPress sets DB_HOST before any plugin runs (DECISION H: wp-config defines
        // are available in the worker process). DB_HOST may be a bare host, host:port,
        // host:socket, or an IPv6 form — mysqli does NOT parse these the way wpdb does,
        // so we split it ourselves (parse_db_host() parity) and return the bare host.
        if (defined('DB_HOST') && DB_HOST !== '') {
            return $this->parseDbHost((string) DB_HOST)['host'];
        }

        return 'localhost';
    }

    /**
     * Resolve the MySQL TCP port.
     *
     * Precedence (DECISION O §(c), HOTFIX):
     *   1. A port embedded in DB_HOST (e.g. "localhost:10004") — mirrors wpdb.
     *   2. The HSP_MYSQL_PORT override, when set.
     *   3. 0 — meaning "unconfigured": let mysqli fall back to `mysqli.default_port`
     *      (Local by Flywheel and similar stacks set this in php.ini; $wpdb connects
     *      with port 0 for exactly this reason). NEVER a hardcoded 3306 — passing an
     *      explicit 3306 overrides mysqli.default_port and breaks non-3306 stacks.
     *
     * A socket-form DB_HOST ("localhost:/path/to.sock") carries no TCP port; the port
     * is 0 and the socket is supplied via mysqlSocket() instead.
     */
    public function mysqlPort(): int
    {
        if (defined('DB_HOST') && DB_HOST !== '') {
            $parsed = $this->parseDbHost((string) DB_HOST);
            if ($parsed['port'] !== null) {
                return $parsed['port'];
            }
        }

        $override = $this->resolve('HSP_MYSQL_PORT');
        if ($override !== null && $override !== '') {
            return (int) $override;
        }

        // Unconfigured: defer to mysqli.default_port (0 tells mysqli to use the ini value).
        return 0;
    }

    /**
     * Resolve the MySQL Unix socket / named pipe path, or null when none is configured.
     *
     * A socket is present only when DB_HOST uses the "host:socket" form (the trailing
     * segment is a path, not an integer port) — mirrors wpdb::parse_db_host(). When no
     * socket is configured, null lets mysqli fall back to `mysqli.default_socket`.
     */
    public function mysqlSocket(): ?string
    {
        $override = $this->resolve('HSP_MYSQL_HOST');
        if ($override !== null && $override !== '') {
            return $this->parseDbHost((string) $override)['socket'];
        }

        if (defined('DB_HOST') && DB_HOST !== '') {
            return $this->parseDbHost((string) DB_HOST)['socket'];
        }

        return null;
    }

    /**
     * Split a WordPress-style DB host string into {host, port, socket}, matching the
     * semantics of wpdb::parse_db_host(). mysqli does not understand "host:port" or
     * "host:/path.sock" strings the way wpdb does — if the raw DB_HOST were handed to
     * mysqli verbatim it would treat the whole string as a hostname and silently use
     * the default port, so this splitting is mandatory (HOTFIX root cause).
     *
     * Recognised forms (WP parity):
     *   "localhost"                 → host=localhost,  port=null, socket=null
     *   "localhost:10004"           → host=localhost,  port=10004, socket=null
     *   "127.0.0.1:3306"            → host=127.0.0.1,  port=3306,  socket=null
     *   "localhost:/tmp/mysql.sock" → host=localhost,  port=null,  socket=/tmp/mysql.sock
     *   ":/tmp/mysql.sock"          → host='',         port=null,  socket=/tmp/mysql.sock
     *   "[::1]"                     → host=::1,        port=null,  socket=null
     *   "[::1]:3306"                → host=::1,        port=3306,  socket=null
     *
     * @return array{host: string, port: int|null, socket: string|null}
     */
    public function parseDbHost(string $hostString): array
    {
        // WP parse_db_host regex parity: optional [IPv6] or host, optional :port,
        // optional :socket-path. A leading ":/path" (empty host) is allowed.
        $pattern = '/^(?:(?:\[([0-9a-fA-F:]+)\])|([^:\/]*))(?::(\d+))?(?::(\/.+))?$/';

        if (! preg_match($pattern, $hostString, $m)) {
            // Unparseable — treat the whole thing as a bare host (safest fallback).
            return ['host' => $hostString, 'port' => null, 'socket' => null];
        }

        $host   = ($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        $port   = (isset($m[3]) && $m[3] !== '') ? (int) $m[3] : null;
        $socket = (isset($m[4]) && $m[4] !== '') ? $m[4] : null;

        return ['host' => $host, 'port' => $port, 'socket' => $socket];
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
