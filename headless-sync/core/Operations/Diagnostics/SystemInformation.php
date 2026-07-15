<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Diagnostics;

/**
 * Immutable System Information snapshot (Doc 12 §13).
 *
 * A flat, read-only bag of current-state facts about the platform for display. Derived
 * on-demand (DECISION V (c) — zero persistence); the version reads come from OPEN-8 tables
 * (module_versions / schema_versions) and the runtime facts from the PHP/WP environment.
 *
 * @psalm-immutable
 */
final class SystemInformation
{
    /**
     * @param array<string,string> $moduleVersions module_name → schema_version (OPEN-8)
     */
    public function __construct(
        public readonly string $platformVersion,
        public readonly string $phpVersion,
        public readonly ?string $wordpressVersion,
        public readonly string $postgresVersion,
        public readonly string $queueProvider,
        public readonly int $appliedMigrationCount,
        public readonly ?string $latestMigration,
        public readonly array $moduleVersions,
    ) {}
}
