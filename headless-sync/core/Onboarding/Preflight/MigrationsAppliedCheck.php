<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Preflight;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;
use HSP\Core\Onboarding\OnboardingConnectionProbe;

/**
 * Preflight check 4 (DECISION W (f)): the migration engine state shows the required core +
 * content migrations applied.
 *
 * Read via system.schema_versions (OPEN-8 read path) through the EXISTING delivery handle
 * ({@see OnboardingConnectionProbe} — no fifth handle, no new pg_* wrapper). The pipeline cannot
 * project content until the core system tables (events / queue / processed / aggregate versions)
 * AND the content projection tables (pages / posts / taxonomies) exist, so those migrations form
 * the required set. A missing member is a hard block naming exactly what to apply.
 *
 * The check matches by the recorded `migration_name` (e.g. `0002_create_system_events`), which
 * is stable and schema-context independent, so it does not depend on the delivery handle's search
 * path. When the DB is unreachable the probe returns an empty applied set and every required
 * migration reads as missing — the remediation then points at running migrations (and implicitly
 * at the reachability check above).
 */
final class MigrationsAppliedCheck implements PreflightCheckInterface
{
    public const KEY = 'migrations_applied';

    /**
     * Required migration names (core system + content projections). Kept as the pipeline-critical
     * subset — not every migration — so the check stays meaningful without being brittle to
     * additive non-critical migrations.
     *
     * @var list<string>
     */
    private const REQUIRED = [
        // Core system pipeline tables.
        '0002_create_system_events',
        '0003_create_system_queue_jobs',
        '0005_create_system_aggregate_versions',
        '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
        // Content projection tables (Blog MVP delivery targets).
        '0002_create_content_pages',
        '0003_create_content_posts',
        '0004_create_content_taxonomies',
    ];

    public function __construct(
        private readonly OnboardingConnectionProbe $probe,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function run(): PreflightResult
    {
        $applied = $this->probe->appliedMigrationNames();
        $missing = array_values(array_diff(self::REQUIRED, $applied));

        $passed = $missing === [];

        return new PreflightResult(
            self::KEY,
            'Required migrations applied',
            $passed,
            $passed
                ? 'All required core and content migrations are applied.'
                : count($missing) . ' required migration(s) not applied: ' . implode(', ', $missing) . '.',
            $passed
                ? ''
                : 'Run the HSP migration engine to apply the outstanding core and content migrations '
                    . '(ensure PostgreSQL is reachable first).',
        );
    }
}
