<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding\Backfill;

use HSP\Core\Onboarding\Backfill\BackfillGate;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ONB-S2 backfill gate (DECISION W (c)/(f) v1.22).
 *
 * Proves the two hard prerequisites: a LIVE worker heartbeat (fresh vs stale vs absent, against the
 * offline threshold — DECISION P) and applied migrations (reusing MigrationsAppliedCheck). Each
 * unmet gate hard-blocks with remediation; both pass → ready. All reads are read-only (the scripted
 * connection throws on any write).
 */
final class BackfillGateTest extends TestCase
{
    /** All required migrations, so the migration gate always passes in these heartbeat-focused tests. */
    private const ALL_MIGRATIONS = [
        '0002_create_system_events', '0003_create_system_queue_jobs',
        '0005_create_system_aggregate_versions', '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
        '0002_create_content_pages', '0003_create_content_posts', '0004_create_content_taxonomies',
    ];

    public function test_ready_when_heartbeat_is_fresh_and_migrations_applied(): void
    {
        $gate = $this->gate(heartbeatAge: 5.0, offlineAfter: 60);

        self::assertTrue($gate->isReady());
        $summary = $gate->summary();
        self::assertTrue($summary['ready']);
        self::assertCount(2, $summary['gates']);
        foreach ($summary['gates'] as $g) {
            self::assertTrue($g['passed']);
            self::assertSame('', $g['remediation']);
        }
    }

    public function test_blocks_when_heartbeat_is_stale(): void
    {
        $gate = $this->gate(heartbeatAge: 120.0, offlineAfter: 60);

        self::assertFalse($gate->isReady());
        $worker = $this->gateByKey($gate->summary(), BackfillGate::GATE_WORKER);
        self::assertFalse($worker['passed']);
        self::assertNotSame('', $worker['remediation']);
        self::assertStringNotContainsStringIgnoringCase('restart workers', $worker['remediation']);
    }

    public function test_blocks_when_no_heartbeat_row_exists(): void
    {
        // No 'worker_heartbeats' script → MAX(...) age is null → no worker seen.
        $reader = new BackfillReader(fn (): ScriptedConnection => (new ScriptedConnection())
            ->on('system.worker_heartbeats', [['age' => null]]));
        $gate   = new BackfillGate($reader, $this->migrations(self::ALL_MIGRATIONS), 60);

        self::assertFalse($gate->isReady());
        $worker = $this->gateByKey($gate->summary(), BackfillGate::GATE_WORKER);
        self::assertFalse($worker['passed']);
        self::assertStringContainsStringIgnoringCase('no worker', $worker['detail']);
    }

    public function test_blocks_when_migrations_missing_even_with_a_live_worker(): void
    {
        $reader = new BackfillReader(fn (): ScriptedConnection => (new ScriptedConnection())
            ->on('system.worker_heartbeats', [['age' => 3.0]]));
        // Only a subset applied → migration gate fails.
        $gate = new BackfillGate($reader, $this->migrations(['0002_create_system_events']), 60);

        self::assertFalse($gate->isReady());
        $mig = $this->gateByKey($gate->summary(), BackfillGate::GATE_MIGRATIONS);
        self::assertFalse($mig['passed']);
        self::assertNotSame('', $mig['remediation']);
    }

    // --- helpers ------------------------------------------------------------

    private function gate(float $heartbeatAge, int $offlineAfter): BackfillGate
    {
        $reader = new BackfillReader(fn (): ScriptedConnection => (new ScriptedConnection())
            ->on('system.worker_heartbeats', [['age' => $heartbeatAge]]));

        return new BackfillGate($reader, $this->migrations(self::ALL_MIGRATIONS), $offlineAfter);
    }

    /** @param list<string> $applied */
    private function migrations(array $applied): MigrationsAppliedCheck
    {
        $rows  = array_map(static fn (string $n) => ['migration_name' => $n], $applied);
        $probe = new OnboardingConnectionProbe(
            fn (): ScriptedConnection => (new ScriptedConnection())->on('system.schema_versions', $rows),
        );

        return new MigrationsAppliedCheck($probe);
    }

    /**
     * @param array{ready:bool,gates:list<array<string,mixed>>} $summary
     * @return array<string,mixed>
     */
    private function gateByKey(array $summary, string $key): array
    {
        foreach ($summary['gates'] as $g) {
            if ($g['key'] === $key) {
                return $g;
            }
        }
        self::fail("gate '{$key}' not found");
    }
}
