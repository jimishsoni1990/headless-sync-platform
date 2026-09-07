<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Migrations\Connection\ConnectionInterface;

/**
 * Writes system.module_versions (DECISION AG AG-6).
 *
 * The table has existed since migration 0009 and is READ by
 * OperationsQueryReader for the System Information panel, and MigrationInterface's docblock
 * says module-owned migrations must register against it — but nothing has ever written it.
 * With one module that was an invisible empty panel; with two it becomes a real "which
 * module version is installed?" question with no answer.
 *
 * Rules from AG-6:
 *   - `system.schema_versions` remains the AUTHORITATIVE migration-state record. This table
 *     is version/history metadata and must NOT become the source used to decide whether
 *     migrations are applied — onboarding readiness keeps reading active migration state.
 *   - The write happens only AFTER a module's migration batch has successfully reached its
 *     declared schema version, never before.
 *   - It is idempotent (UNIQUE(module_name, schema_version) + ON CONFLICT DO NOTHING).
 *   - Historical rows are never deleted on rollback — the table is a history, not a pointer.
 *   - `schema_version` means an explicitly declared MODULE SCHEMA version (module.json's
 *     `schema_version`), not the plugin version and not the code version.
 *
 * Uses the migration engine's existing DDL connection rather than a runtime handle: the write
 * belongs to the migration lifecycle, and the four-handle runtime topology (DECISION L
 * Ruling 0) stays untouched. No new `pg_*` wrapper (DECISION E).
 */
final class ModuleVersionRecorder
{
    public function __construct(private readonly ConnectionInterface $conn)
    {
    }

    /**
     * Record that $moduleName has reached $schemaVersion.
     *
     * @return bool True when a new row was written, false when this version was already
     *              recorded. Never throws on a duplicate — re-running the lifecycle must be
     *              safe, and a repeat evaluation is the normal case on every page load.
     */
    public function record(string $moduleName, string $schemaVersion, ?string $notes = null): bool
    {
        if ($moduleName === '' || $schemaVersion === '') {
            return false;
        }

        $affected = $this->conn->insert(
            'INSERT INTO system.module_versions (id, module_name, schema_version, applied_at, notes)
             VALUES ($1, $2, $3, NOW(), $4)
             ON CONFLICT (module_name, schema_version) DO NOTHING',
            [$this->uuid(), $moduleName, $schemaVersion, $notes],
        );

        return $affected > 0;
    }

    /**
     * UUIDv7 — time-ordered, matching the platform identity canon (ADR-015 / OPEN-3).
     */
    private function uuid(): string
    {
        $unixTsMs = (int) (microtime(true) * 1000);

        $bytes = pack('J', $unixTsMs);
        $bytes = substr($bytes, 2);            // low 48 bits of the timestamp
        $bytes .= random_bytes(10);

        // Version 7 and RFC 4122 variant bits.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
