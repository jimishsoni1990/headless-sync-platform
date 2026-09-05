<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Preflight;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;
use HSP\Core\Onboarding\OnboardingConnectionProbe;

/**
 * Migration-state hard-block check: the migration engine state shows the required core +
 * content migrations applied.
 *
 * DECISION W (f) amendment (v1.22, 2026-07-17): this check is a **backfill prerequisite evaluated
 * in ONB-S2**, NOT part of the ONB-S1b environment preflight. It ships in ONB-S1b (bound + tested)
 * so ONB-S2 reuses it, but it is deliberately excluded from the ONB-S1b PreflightRunner. Same
 * hard-block semantics, same delivery-handle read path — moved to where delivery schema/data
 * readiness actually gates work (immediately before backfill).
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
        // 0011 adds UNIQUE(event_id) on system.queue_jobs — the dispatcher's ON CONFLICT idempotent
        // enqueue REQUIRES it (DECISION L v1.12); without it the first dispatch fails. Pipeline-critical.
        '0011_add_unique_event_id_to_queue_jobs',
        '0005_create_system_aggregate_versions',
        '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
        // Content projection tables (Blog MVP delivery targets).
        '0002_create_content_pages',
        '0003_create_content_posts',
        '0004_create_content_taxonomies',
        // Media hooks are wired from activation, so media events start flowing immediately;
        // without this table every one of them would fail projection and land in the DLQ.
        '0006_create_content_media',
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
