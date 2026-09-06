<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding\Backfill;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * A read-only DatabaseConnectionInterface double for ONB-S2 backfill-reader unit tests.
 *
 * query() returns scripted rows matched by a substring of the SQL (so tests need not reproduce
 * exact whitespace). Any WRITE (execute/beginTransaction/commit) FAILS the test — the backfill
 * path is read-only + re-emission only (DECISION W (b)); a write here would be a bug. Optionally
 * throws on connect (via a resolver) to exercise the unreachable-DB fallbacks.
 */
final class ScriptedConnection implements DatabaseConnectionInterface
{
    /** @var array<string, array<int, array<string, mixed>>> SQL substring → rows */
    private array $scripted = [];

    public int $writeAttempts = 0;

    /** @var list<string> every SQL passed to query(), for assertions. */
    public array $queries = [];

    /** Script a query: any SQL containing $needle returns $rows. */
    public function on(string $needle, array $rows): self
    {
        $this->scripted[$needle] = $rows;

        return $this;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queries[] = $sql;

        foreach ($this->scripted as $needle => $rows) {
            if (str_contains($sql, $needle)) {
                return $rows;
            }
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->writeAttempts++;
        throw new \RuntimeException('backfill path must not write: ' . $sql);
    }

    public function beginTransaction(): void
    {
        $this->writeAttempts++;
        throw new \RuntimeException('backfill path must not open a write transaction');
    }

    public function commit(): void
    {
        $this->writeAttempts++;
        throw new \RuntimeException('backfill path must not commit');
    }

    public function rollback(): void {}
}
