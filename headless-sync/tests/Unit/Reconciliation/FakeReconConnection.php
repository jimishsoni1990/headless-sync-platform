<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Reconciliation;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * SQL-routing fake DatabaseConnectionInterface for reconciliation unit tests.
 *
 * ReconciliationService issues three query shapes: projection-row lookup (SELECT checksum,
 * updated_at, deleted_at ...), the in-flight events check (FROM system.events ...), and the
 * orphan candidate scan (SELECT ... deleted_at IS NULL ...). This fake dispatches by SQL
 * substring so the test can script each independently. execute() is asserted to NEVER be
 * called — ReconciliationService must not write PG directly.
 */
final class FakeReconConnection implements DatabaseConnectionInterface
{
    /** @var array<int,array{method:string,sql:string,params:list<mixed>}> */
    public array $log = [];

    /** @var array<string, array<string,mixed>|null> "type:id" → projection row (or null = absent) */
    public array $projectionRows = [];

    /** @var array<string, bool> "type:id" → has captured-not-processed event */
    public array $eventsPending = [];

    /** @var array<string, list<string>> type → orphan candidate ids (live projection rows) */
    public array $orphanCandidates = [];

    public function execute(string $sql, array $params = []): int
    {
        $this->log[] = ['method' => 'execute', 'sql' => $sql, 'params' => $params];
        throw new \LogicException('ReconciliationService must not execute() — repair is re-emission only.');
    }

    public function query(string $sql, array $params = []): array
    {
        $this->log[] = ['method' => 'query', 'sql' => $sql, 'params' => $params];

        if (str_contains($sql, 'system.events')) {
            // In-flight events check: params = [type, id].
            $key = ($params[0] ?? '') . ':' . ($params[1] ?? '');
            return ($this->eventsPending[$key] ?? false) ? [[1]] : [];
        }

        if (str_contains($sql, 'deleted_at IS NULL')) {
            // Orphan candidate scan: derive type from the table name in the SQL.
            $type = $this->typeFromTable($sql);
            $ids  = $this->orphanCandidates[$type] ?? [];
            return array_map(static fn (string $id): array => ['aggregate_id' => $id], $ids);
        }

        // Projection-row lookup: params = [id]; derive type from table name.
        $type = $this->typeFromTable($sql);
        $key  = "{$type}:" . ($params[0] ?? '');
        $row  = $this->projectionRows[$key] ?? null;

        return $row === null ? [] : [$row];
    }

    public function beginTransaction(): void { $this->log[] = ['method' => 'beginTransaction', 'sql' => '', 'params' => []]; }
    public function commit(): void           { $this->log[] = ['method' => 'commit', 'sql' => '', 'params' => []]; }
    public function rollback(): void          { $this->log[] = ['method' => 'rollback', 'sql' => '', 'params' => []]; }

    /** @return string[] */
    public function loggedMethods(): array
    {
        return array_column($this->log, 'method');
    }

    private function typeFromTable(string $sql): string
    {
        if (str_contains($sql, 'content.pages'))      { return 'page'; }
        if (str_contains($sql, 'content.posts'))      { return 'post'; }
        if (str_contains($sql, 'content.taxonomies')) { return 'category'; }
        return '';
    }
}
