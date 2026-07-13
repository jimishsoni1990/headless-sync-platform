<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Reconciliation;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Decorator over a real DatabaseConnectionInterface that counts write operations.
 *
 * Used to prove DECISION U's core invariant: ReconciliationService repairs ONLY by
 * re-emission and performs NO direct PG projection write. Detection reads (query) pass
 * through; any execute()/beginTransaction()/commit() would be a direct write and is counted.
 */
final class WriteSpyConnection implements DatabaseConnectionInterface
{
    public int $executeCount = 0;
    public int $beginCount   = 0;

    public function __construct(
        private readonly DatabaseConnectionInterface $inner,
    ) {}

    public function execute(string $sql, array $params = []): int
    {
        $this->executeCount++;
        return $this->inner->execute($sql, $params);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->inner->query($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->beginCount++;
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }
}
