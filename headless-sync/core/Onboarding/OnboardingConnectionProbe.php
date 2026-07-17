<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Read-only PostgreSQL probe for the onboarding preflight (ONB-S1b; DECISION W (e)/(f)).
 *
 * Onboarding opens NO PostgreSQL handle of its own (DECISION W (e)): the two DB-touching
 * preflight checks (PG reachable, required migrations applied) reuse the EXISTING delivery
 * DatabaseConnectionInterface (DECISION K) — no fifth handle (DECISION L Ruling 0), no new pg_*
 * wrapper (DECISION E).
 *
 * The delivery handle is resolved LAZILY through an injected resolver rather than being handed in
 * pre-built. This is deliberate: DeliveryServiceProvider opens the libpq link EAGERLY inside its
 * factory and THROWS when PostgreSQL is unreachable. If the probe took a pre-resolved handle, that
 * throw would fire during container resolution — before any check runs — and surface as an
 * uncaught exception / HTTP 500 on the preflight endpoint. By resolving inside {@see connection()},
 * connection failure happens WHILE a check is running, where it is caught and reported as a
 * PreflightResult FAIL with remediation (fail-closed). The resolver still returns the delivery
 * handle — no new handle, no new wrapper — it is merely resolved on demand.
 *
 * Every method is a SELECT; nothing here writes. Both public methods catch connection/DML failure
 * and translate it to a plain return value.
 *
 * @phpstan-type HandleResolver callable(): DatabaseConnectionInterface
 */
final class OnboardingConnectionProbe
{
    /** @var callable(): DatabaseConnectionInterface */
    private $resolveConnection;

    /**
     * @param callable(): DatabaseConnectionInterface $resolveConnection resolves the EXISTING
     *        delivery handle on demand (may throw if the underlying link cannot be opened).
     */
    public function __construct(callable $resolveConnection)
    {
        $this->resolveConnection = $resolveConnection;
    }

    /**
     * True when a live PostgreSQL round-trip succeeds. Never throws — a failed connection (whether
     * it fails to OPEN or to QUERY) is a preflight failure (hard block with remediation), not an
     * exception the caller must handle.
     */
    public function isReachable(): bool
    {
        try {
            $this->connection()->query('SELECT 1 AS ok');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Names of currently-applied migrations (rolled_back_at IS NULL) from system.schema_versions
     * (OPEN-8). Returns an empty list when the connection cannot be opened or the table is
     * unreadable (unreachable DB / no schema) — the migration check then reports the required set
     * as missing.
     *
     * @return list<string>
     */
    public function appliedMigrationNames(): array
    {
        try {
            $rows = $this->connection()->query(
                'SELECT DISTINCT migration_name
                 FROM   system.schema_versions
                 WHERE  rolled_back_at IS NULL'
            );
        } catch (\Throwable) {
            return [];
        }

        $names = [];
        foreach ($rows as $row) {
            if (isset($row['migration_name'])) {
                $names[] = (string) $row['migration_name'];
            }
        }

        return $names;
    }

    /**
     * Resolve the delivery handle on demand. May throw (e.g. libpq connect failure) — callers wrap
     * this in their own try/catch so the failure becomes a preflight FAIL, never an uncaught error.
     */
    private function connection(): DatabaseConnectionInterface
    {
        return ($this->resolveConnection)();
    }
}
