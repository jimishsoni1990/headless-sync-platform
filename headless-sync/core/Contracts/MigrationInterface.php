<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Versioned, idempotent database migration.
 *
 * Applied migrations are recorded in system.schema_versions (Doc 3 §24).
 * Module-owned migrations must also register against system.module_versions.
 * Migrations run in ascending version order; each must be idempotent (IF NOT EXISTS,
 * ON CONFLICT DO NOTHING, or equivalent).
 */
interface MigrationInterface
{
    /** Unique monotonic migration name, e.g. '0001_create_hsp_outbox'. */
    public function getName(): string;

    /** Schema context recorded in system.schema_versions, e.g. 'core', 'content'. */
    public function getSchemaContext(): string;

    /** Apply the migration. Must be idempotent. */
    public function up(): void;

    /** Revert the migration, if supported. */
    public function down(): void;
}
