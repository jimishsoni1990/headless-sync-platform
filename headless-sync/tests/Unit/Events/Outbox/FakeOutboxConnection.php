<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Events\Outbox;

use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Database\Exception\DatabaseException;
use HSP\Core\Events\Outbox\Connection\MysqlOutboxConnectionInterface;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * In-memory fake for MysqlOutboxConnectionInterface (MySQL capture path).
 *
 * Records all calls and supports pre-seeded query results and simulated failures.
 * Used by RelayWorkerStrategy unit tests for the MySQL side of the relay.
 *
 * DECISION E v1.6: MySQL capture path uses MysqlOutboxConnectionInterface.
 */
class FakeMysqlOutboxConnection implements MysqlOutboxConnectionInterface
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $executeCalls = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $queryCalls = [];

    public int $beginCount    = 0;
    public int $commitCount   = 0;
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
            throw new OutboxWriteException('Simulated MySQL execute failure');
        }

        return 1;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queryCalls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failNextQuery) {
            $this->failNextQuery = false;
            throw new OutboxWriteException('Simulated MySQL query failure');
        }

        $rows              = $this->nextQueryRows;
        $this->nextQueryRows = [];
        return $rows;
    }

    public function beginTransaction(): void { ++$this->beginCount; }
    public function commit(): void           { ++$this->commitCount; }
    public function rollback(): void         { ++$this->rollbackCount; }
}

/**
 * In-memory fake for DatabaseConnectionInterface (PG delivery path).
 *
 * Records all calls and supports pre-seeded query results and simulated failures.
 * Used by RelayWorkerStrategy unit tests for the PostgreSQL side of the relay.
 *
 * DECISION E v1.6: PG delivery path uses DatabaseConnectionInterface.
 */
class FakePgsqlOutboxConnection implements DatabaseConnectionInterface
{
    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $executeCalls = [];

    /** @var list<array{sql: string, params: list<mixed>}> */
    public array $queryCalls = [];

    public int $beginCount    = 0;
    public int $commitCount   = 0;
    public int $rollbackCount = 0;

    /** When true, the next execute() call throws. */
    public bool $failNextExecute = false;

    /** When true, the next query() call throws. */
    public bool $failNextQuery = false;

    public function execute(string $sql, array $params = []): int
    {
        $this->executeCalls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failNextExecute) {
            $this->failNextExecute = false;
            throw new DatabaseException('Simulated PG execute failure');
        }

        return 1;
    }

    public function query(string $sql, array $params = []): array
    {
        $this->queryCalls[] = ['sql' => $sql, 'params' => $params];

        if ($this->failNextQuery) {
            $this->failNextQuery = false;
            throw new DatabaseException('Simulated PG query failure');
        }

        return [];
    }

    public function beginTransaction(): void { ++$this->beginCount; }
    public function commit(): void           { ++$this->commitCount; }
    public function rollback(): void         { ++$this->rollbackCount; }
}

/**
 * @deprecated Use FakeMysqlOutboxConnection or FakePgsqlOutboxConnection per DECISION E v1.6.
 *
 * Kept as a type alias of FakeMysqlOutboxConnection so any remaining reference
 * in tests not yet migrated does not produce a fatal class-not-found error.
 * Remove once all callsites are updated to the split fakes.
 */
class FakeOutboxConnection extends FakeMysqlOutboxConnection {}
