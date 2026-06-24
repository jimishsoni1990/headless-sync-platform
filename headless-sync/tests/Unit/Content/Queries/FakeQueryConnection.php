<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Queries;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Minimal controllable fake for DatabaseConnectionInterface used in Query Provider tests.
 *
 * Records every query() call (SQL + params) for assertion.
 * Allows enqueuing result sets to be consumed in order.
 */
final class FakeQueryConnection implements DatabaseConnectionInterface
{
    /** @var array<int,array{sql:string,params:list<mixed>}> */
    public array $queries = [];

    /** @var list<array<int,array<string,mixed>>> */
    private array $resultQueue = [];

    /** Enqueue result sets to be consumed in order by successive query() calls. */
    public function queueResults(array ...$sets): void
    {
        foreach ($sets as $set) {
            $this->resultQueue[] = $set;
        }
    }

    public function execute(string $sql, array $params = []): int
    {
        return 0;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queries[] = ['sql' => $sql, 'params' => $params];
        if (! empty($this->resultQueue)) {
            return array_shift($this->resultQueue);
        }
        return [];
    }

    public function beginTransaction(): void {}
    public function commit(): void {}
    public function rollback(): void {}

    /** Return the SQL of the nth query() call (0-indexed). */
    public function sqlAt(int $index): string
    {
        return $this->queries[$index]['sql'] ?? '';
    }

    /** Return the params of the nth query() call (0-indexed). */
    public function paramsAt(int $index): array
    {
        return $this->queries[$index]['params'] ?? [];
    }
}
