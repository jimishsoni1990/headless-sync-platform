<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Adapters;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;

/**
 * Controllable fake for DatabaseConnectionInterface used in adapter unit tests.
 *
 * Records every call for assertion. Allows injecting a row set for query() returns
 * and optionally throwing on execute() to simulate transaction failure.
 */
final class FakeDbConnection implements DatabaseConnectionInterface
{
    /** @var array<int,array{method:string,sql:string,params:list<mixed>}> */
    public array $log = [];

    /** @var array<int,array<string,mixed>> */
    private array $queryResult = [];

    private bool $failOnNextExecute = false;

    private int $transactionDepth = 0;

    /** @var list<array<int,array<string,mixed>>> */
    private array $queryResultQueue = [];

    /** Set the rows to return from the next query() call. */
    public function willReturnRows(array $rows): void
    {
        $this->queryResult = $rows;
    }

    /**
     * Enqueue multiple result sets to be consumed in order by successive query() calls.
     * Each element is the full array of rows for that call.
     */
    public function queueQueryResults(array ...$resultSets): void
    {
        $this->queryResultQueue = array_values($resultSets);
    }

    /** Cause the next execute() call to throw DatabaseException. */
    public function failNextExecute(): void
    {
        $this->failOnNextExecute = true;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->log[] = ['method' => 'execute', 'sql' => $sql, 'params' => $params];

        if ($this->failOnNextExecute) {
            $this->failOnNextExecute = false;
            throw new DatabaseException('Simulated execute failure');
        }

        return 1;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->log[] = ['method' => 'query', 'sql' => $sql, 'params' => $params];

        // Prefer queued results (multi-call scenario) over single-shot willReturnRows.
        if (! empty($this->queryResultQueue)) {
            return array_shift($this->queryResultQueue);
        }

        $result = $this->queryResult;
        $this->queryResult = [];
        return $result;
    }

    public function beginTransaction(): void
    {
        $this->log[] = ['method' => 'beginTransaction', 'sql' => 'BEGIN', 'params' => []];
        $this->transactionDepth++;
    }

    public function commit(): void
    {
        $this->log[] = ['method' => 'commit', 'sql' => 'COMMIT', 'params' => []];
        $this->transactionDepth--;
    }

    public function rollback(): void
    {
        $this->log[] = ['method' => 'rollback', 'sql' => 'ROLLBACK', 'params' => []];
        $this->transactionDepth--;
    }

    public function getTransactionDepth(): int
    {
        return $this->transactionDepth;
    }

    /** Extract all method names from the log for order assertions. */
    public function loggedMethods(): array
    {
        return array_column($this->log, 'method');
    }

    /** Count execute() calls whose SQL contains $keyword. */
    public function countExecuteContaining(string $keyword): int
    {
        return count(array_filter(
            $this->log,
            fn($e) => $e['method'] === 'execute' && str_contains($e['sql'], $keyword)
        ));
    }
}
