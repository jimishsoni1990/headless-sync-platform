<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * A read-only DatabaseConnectionInterface fake for OPSC-S2 provider/reader unit tests.
 *
 * Results are matched by SQL substring (the caller registers a needle → rows mapping), so a
 * test does not depend on the exact query order a provider issues internally. Any write
 * operation (execute / beginTransaction / commit) increments a spy counter and throws — the
 * OPSC-S2 providers are READ-ONLY (DECISION V (c)), so a write is a test failure by design.
 */
final class ScriptedReaderConnection implements DatabaseConnectionInterface
{
    /** @var array<int, array{needle:string, rows:array<int,array<string,mixed>>}> */
    private array $script = [];

    /** @var list<string> every SQL passed to query(), for assertions. */
    public array $queries = [];

    public int $writeAttempts = 0;

    /**
     * Return $rows for any query() whose SQL contains $needle (first match wins).
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public function on(string $needle, array $rows): self
    {
        $this->script[] = ['needle' => $needle, 'rows' => $rows];

        return $this;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queries[] = $sql;

        foreach ($this->script as $entry) {
            if (str_contains($sql, $entry['needle'])) {
                return $entry['rows'];
            }
        }

        return [];
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->writeAttempts++;
        throw new \LogicException('OPSC-S2 providers are read-only: execute() must not be called. SQL: ' . $sql);
    }

    public function beginTransaction(): void
    {
        $this->writeAttempts++;
        throw new \LogicException('OPSC-S2 providers are read-only: beginTransaction() must not be called.');
    }

    public function commit(): void
    {
        $this->writeAttempts++;
        throw new \LogicException('OPSC-S2 providers are read-only: commit() must not be called.');
    }

    public function rollback(): void {}
}
