<?php

declare(strict_types=1);

namespace HSP\Core\Migrations;

/**
 * Immutable value object representing one row in system.schema_versions.
 */
final class MigrationRecord
{
    public function __construct(
        public readonly string $id,
        public readonly string $migrationName,
        public readonly string $schemaContext,
        public readonly string $appliedAt,
        public readonly ?string $rolledBackAt,
        public readonly string $checksum,
    ) {}
}
