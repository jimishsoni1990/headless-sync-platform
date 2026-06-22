<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Queue;

use HSP\Core\Queue\Exception\QueueException;
use HSP\Core\Queue\Providers\Database\QueueConnectionInterface;

/**
 * In-memory test double for QueueConnectionInterface.
 *
 * Records all calls; supports pre-seeded query results and simulated failures.
 * Allows DatabaseQueueProvider unit tests to run without a real database.
 *
 * Usage pattern:
 *   $fake->queryResultQueue[] = [['id' => 'abc', ...]];  // one result-set per query call
 *   $fake->failNextExecute = true;
 */
class FakeQueueConnection implements QueueConnectionInterface
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $executeCalls = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $queryCalls = [];

    public int $beginCount    = 0;
    public int $commitCount   = 0;
    public int $rollbackCount = 0;

    /**
     * Queue of result-sets.  Each query() call shifts one entry off the front.
     * If the queue is empty, query() returns [].
     *
     * @var list<list<array<string, mixed>>>
     */
    public array $queryResultQueue = [];

    /** When true, the next execute() call throws. */
    public bool $failNextExecute = false;

    /** When true, the next query() call throws. */
    public bool $failNextQuery = false;

    /** Override execute() return value (default 1). */
    public int $executeReturnValue = 1;

    public function execute(string $sql, array $params = []): int
    {
        $this->executeCalls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failNextExecute) {
            $this->failNextExecute = false;
            throw new QueueException('Simulated execute failure');
        }

        return $this->executeReturnValue;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queryCalls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failNextQuery) {
            $this->failNextQuery = false;
            throw new QueueException('Simulated query failure');
        }

        if (! empty($this->queryResultQueue)) {
            return array_shift($this->queryResultQueue);
        }

        return [];
    }

    public function beginTransaction(): void { ++$this->beginCount; }
    public function commit(): void           { ++$this->commitCount; }
    public function rollback(): void         { ++$this->rollbackCount; }
}
