<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Content;

use HSP\Core\Database\PostgresDatabaseConnection;

/**
 * A real PostgreSQL connection that counts query() calls.
 *
 * Used to assert "exactly one query" properties against LIVE PostgreSQL rather than against a
 * fake — the claim being tested is about the SQL itself (a recursive CTE resolving a whole
 * ancestor chain in one round trip instead of walking it in PHP), so the query has to actually run.
 *
 * @see PagePathAddressingIntegrationTest::test_path_resolution_costs_exactly_one_query_at_any_depth
 */
final class CountingPgConnection extends PostgresDatabaseConnection
{
    public int $queries = 0;

    /** The last statement executed — so an EXPLAIN can run the REAL query, never a hand-copy. */
    public string $lastSql = '';

    /** @var list<mixed> */
    public array $lastParams = [];

    public function query(string $sql, array $params = []): array
    {
        $this->queries++;
        $this->lastSql    = $sql;
        $this->lastParams = $params;

        return parent::query($sql, $params);
    }
}
