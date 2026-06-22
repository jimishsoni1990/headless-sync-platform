<?php

declare(strict_types=1);

namespace HSP\Core\Migrations;

use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Migrations\Connection\ConnectionInterface;
use HSP\Core\Migrations\Exception\MigrationException;

/**
 * Migration engine — applies migrations in deterministic order, records each
 * in system.schema_versions, and is idempotent on re-run.
 *
 * Design rules (from OPEN-8 v1.4 and §4 DoD):
 *   - Migrations are ordered by getName() ascending (lexicographic — e.g. 0001_…, 0002_…).
 *   - Each applied migration is recorded with: id (UUIDv7 per ADR-015), migration_name, schema_context, applied_at (UTC), checksum (sha256 of raw SQL file template).
 *   - Re-running the runner on an already-applied migration produces zero new rows
 *     (idempotent guard via UNIQUE(migration_name, schema_context)).
 *   - system.schema_versions lives in PostgreSQL (schema_context = 'core/pgsql' etc.).
 *     MySQL migrations are tracked there too (schema_context = 'core/mysql').
 *   - system.module_versions is written by the module registry (P0-S3), not here.
 */
final class MigrationRunner
{
    /**
     * @param ConnectionInterface $schemaVersionsConn      PostgreSQL connection — owns system.schema_versions
     * @param string              $schemaVersionsSqlPath   Absolute path to 0008_create_system_schema_versions.sql.
     *                                                     bootstrap() executes this file verbatim so there is
     *                                                     exactly one DDL definition (single-source, OPEN-8 v1.4).
     */
    public function __construct(
        private readonly ConnectionInterface $schemaVersionsConn,
        private readonly string $schemaVersionsSqlPath,
    ) {}

    /**
     * Ensure system schema and system.schema_versions table exist before first run.
     *
     * Executes 0008_create_system_schema_versions.sql verbatim — the single authoritative
     * DDL source (OPEN-8 v1.4). No inline DDL copy lives here; this is the only definition.
     * Both statements use IF NOT EXISTS / UNIQUE constraints, so repeated calls are safe.
     */
    public function bootstrap(): void
    {
        if (! file_exists($this->schemaVersionsSqlPath)) {
            throw new MigrationException(
                "schema_versions SQL file not found: {$this->schemaVersionsSqlPath}"
            );
        }

        $ddl = file_get_contents($this->schemaVersionsSqlPath);
        if ($ddl === false) {
            throw new MigrationException(
                "Could not read schema_versions SQL file: {$this->schemaVersionsSqlPath}"
            );
        }

        $this->schemaVersionsConn->execute('CREATE SCHEMA IF NOT EXISTS system');
        $this->schemaVersionsConn->execute($ddl);
    }

    /**
     * Run all pending migrations from $migrations in ascending getName() order.
     *
     * Call bootstrap() once before run() on a fresh environment.
     *
     * @param list<MigrationInterface> $migrations
     */
    public function run(array $migrations): void
    {
        usort($migrations, static fn(MigrationInterface $a, MigrationInterface $b) =>
            strcmp($a->getName(), $b->getName())
        );

        $applied = $this->loadAppliedMigrations();

        foreach ($migrations as $migration) {
            $key = $migration->getName() . '|' . $migration->getSchemaContext();

            if (isset($applied[$key])) {
                continue;
            }

            $migration->up();

            $this->recordApplied($migration);
        }
    }

    /**
     * @return array<string, true>  keyed by "migration_name|schema_context"
     */
    private function loadAppliedMigrations(): array
    {
        $rows = $this->schemaVersionsConn->query(
            'SELECT migration_name, schema_context FROM system.schema_versions WHERE rolled_back_at IS NULL'
        );

        $applied = [];
        foreach ($rows as $row) {
            $applied["{$row['migration_name']}|{$row['schema_context']}"] = true;
        }

        return $applied;
    }

    private function recordApplied(MigrationInterface $migration): void
    {
        $id          = $this->uuidv7();
        $name        = $migration->getName();
        $context     = $migration->getSchemaContext();
        $appliedAt   = gmdate('Y-m-d H:i:s');
        $checksum    = $this->computeChecksum($migration);

        // ON CONFLICT DO NOTHING guards against a concurrent runner recording the same row.
        $this->schemaVersionsConn->insert(
            "INSERT INTO system.schema_versions
                 (id, migration_name, schema_context, applied_at, rolled_back_at, checksum)
             VALUES ($1, $2, $3, $4::timestamptz, NULL, $5)
             ON CONFLICT (migration_name, schema_context) DO NOTHING",
            [$id, $name, $context, $appliedAt . '+00:00', $checksum]
        );
    }

    /**
     * Compute sha256 of the migration's canonical SQL string.
     *
     * Hashes the raw file content (the {prefix} template), not the substituted SQL.
     * This keeps the checksum stable regardless of the WordPress table-prefix value.
     * If the migration exposes getSql(), that is used; otherwise the class name is hashed
     * as a stable fallback (PHP-class migrations with no separate file to hash).
     */
    private function computeChecksum(MigrationInterface $migration): string
    {
        $source = method_exists($migration, 'getSql')
            ? $migration->getSql()
            : get_class($migration);

        return hash('sha256', $source);
    }

    /**
     * Generate a UUIDv7 (time-ordered, random suffix).
     *
     * UUIDv7 is the platform-wide ID canon per ADR-015 (v1.1 canon).
     * Layout: 48-bit Unix epoch ms | version=7 (4 bits) | 12-bit random |
     *         variant=0b10 (2 bits) | 62-bit random.
     */
    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        // Bytes 0-5: 48-bit millisecond timestamp, big-endian
        $tsHex = sprintf('%012x', $ms);

        // Bytes 6-7: version nibble (7) + 12 random bits
        $rand12 = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex = sprintf('%04x', 0x7000 | $rand12);

        // Bytes 8-9: variant bits (0b10) + 14 random bits
        $rand14 = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex = sprintf('%04x', 0x8000 | $rand14);

        // Bytes 10-15: 48 random bits
        $tailHex = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tailHex;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}

