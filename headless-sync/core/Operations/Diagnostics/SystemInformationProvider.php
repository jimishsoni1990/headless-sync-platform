<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Diagnostics;

/**
 * Builds the System Information snapshot (Doc 12 §13) — a diagnostics service, read-only.
 *
 * Combines runtime environment facts (platform / PHP / WordPress / queue provider) supplied
 * at construction with DB-derived facts (PostgreSQL version, migration state, module
 * versions) read on demand through the delivery-handle OperationsQueryReader (DECISION V (g)).
 *
 * Environment facts are injected as plain values rather than read from globals here, so the
 * WordPress-boundary read (get_bloginfo) happens once at the wiring site (an entry point) and
 * this class stays a pure, testable derivation with no WP coupling (Rule 6 / ADR-038 spirit).
 * ZERO new persistence (DECISION V (c)); this is a current-state diagnostics surface, not a
 * provider on the RefreshCoordinator path (no OPSC-S1 provider contract fits key/value info).
 */
final class SystemInformationProvider
{
    public function __construct(
        private readonly OperationsQueryReader $reader,
        private readonly string $platformVersion,
        private readonly ?string $wordpressVersion,
        private readonly string $queueProvider,
    ) {}

    public function snapshot(): SystemInformation
    {
        $migration = $this->reader->migrationState();

        return new SystemInformation(
            platformVersion: $this->platformVersion,
            phpVersion: PHP_VERSION,
            wordpressVersion: $this->wordpressVersion,
            postgresVersion: $this->reader->postgresVersion(),
            queueProvider: $this->queueProvider,
            appliedMigrationCount: $migration['applied_count'],
            latestMigration: $migration['latest'],
            moduleVersions: $this->reader->moduleVersions(),
        );
    }
}
