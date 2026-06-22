<?php

declare(strict_types=1);

namespace HSP\Core\Migrations;

use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Migrations\Connection\ConnectionInterface;
use HSP\Core\Migrations\Exception\MigrationException;

/**
 * Base for migrations that execute a single SQL file.
 *
 * Concrete subclasses supply:
 *   - getName()          — matches the SQL filename stem (e.g. '0001_create_hsp_outbox')
 *   - getSchemaContext() — engine-qualified context (e.g. 'core/mysql', 'core/pgsql')
 *   - getSqlFilePath()   — absolute path to the .sql file
 *   - getConnection()    — the ConnectionInterface to execute against
 *
 * getSql() is public so MigrationRunner can hash the exact SQL for the checksum column.
 * down() is a no-op by default; override in subclasses where rollback is implemented.
 */
abstract class AbstractSqlMigration implements MigrationInterface
{
    private ?string $cachedSql = null;

    abstract protected function getSqlFilePath(): string;
    abstract protected function getConnection(): ConnectionInterface;

    public function getSql(): string
    {
        if ($this->cachedSql === null) {
            $path = $this->getSqlFilePath();

            if (! file_exists($path)) {
                throw new MigrationException("Migration SQL file not found: {$path}");
            }

            $this->cachedSql = file_get_contents($path);

            if ($this->cachedSql === false) {
                throw new MigrationException("Could not read migration SQL file: {$path}");
            }
        }

        return $this->cachedSql;
    }

    public function up(): void
    {
        $this->getConnection()->execute($this->getSql());
    }

    public function down(): void
    {
        // Rollback not implemented at this layer; override in concrete subclass if needed.
    }
}
