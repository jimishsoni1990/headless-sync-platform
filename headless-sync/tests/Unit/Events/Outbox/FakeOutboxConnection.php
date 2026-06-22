<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events\Outbox;

use HSP\Core\Events\Outbox\Connection\OutboxConnectionInterface;

/**
 * In-memory fake for OutboxConnectionInterface.
 *
 * Records all calls and supports pre-seeded query results and simulated failures.
 * Allows RelayWorkerStrategy tests to run without a real database.
 */
class FakeOutboxConnection implements OutboxConnectionInterface
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $executeCalls = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $queryCalls = [];

    public int $beginCount  = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;

    /** Pre-seeded rows returned by the next query() call. */
    public array $nextQueryRows = [];

    /** When true, the next execute() call throws. */
    public bool $failNextExecute = false;

    /** When true, the next query() call throws. */
    public bool $failNextQuery = false;

    public function execute(string $sql, array $params = []): int
    {
        $this->executeCalls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failNextExecute) {
            $this->failNextExecute = false;
            throw new \HSP\Core\Events\Outbox\Exception\OutboxWriteException(
                'Simulated execute failure'
            );
        }

        return 1;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queryCalls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failNextQuery) {
            $this->failNextQuery = false;
            throw new \HSP\Core\Events\Outbox\Exception\OutboxWriteException(
                'Simulated query failure'
            );
        }

        $rows               = $this->nextQueryRows;
        $this->nextQueryRows = [];
        return $rows;
    }

    public function beginTransaction(): void  { ++$this->beginCount; }
    public function commit(): void            { ++$this->commitCount; }
    public function rollback(): void          { ++$this->rollbackCount; }
}
