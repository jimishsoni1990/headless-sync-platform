<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Migrations;

use HSP\Core\Migrations\Connection\ConnectionInterface;

/**
 * In-memory fake for ConnectionInterface.
 *
 * Tracks executed SQL statements and supports pre-seeded query results,
 * allowing migration engine tests to run without a real database.
 */
final class FakeConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $executed = [];

    /** @var list<array<string, mixed>> Pre-seeded rows returned by query() */
    public array $queryRows = [];

    /** @var list<array<string, mixed>> Rows inserted via insert() */
    public array $insertedRows = [];

    public function execute(string $sql): void
    {
        $this->executed[] = $sql;
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->queryRows;
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->insertedRows[] = $params;
        return 1;
    }
}
