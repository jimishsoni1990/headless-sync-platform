<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding\Backfill;

use HSP\Core\Onboarding\Backfill\BackfillGate;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use HSP\Core\Workers\ProcessingCronRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ONB-S2 backfill gate, realigned to the ADR-054 cycle model
 * (DECISION X ruling (4) — Option C: the processing cron event is SCHEDULED AND a recent
 * processing heartbeat exists). Proves both halves of the Option-C prerequisite, the migration
 * gate, and that remediation is WP-Cron-only (never supervisor/systemd/daemon/restart). All
 * reads are read-only (the scripted connection throws on any write).
 */
final class BackfillGateTest extends TestCase
{
    /** All required migrations, so the migration gate always passes in these cycle-focused tests. */
    private const ALL_MIGRATIONS = [
        '0002_create_system_events', '0003_create_system_queue_jobs',
        '0011_add_unique_event_id_to_queue_jobs',
        '0005_create_system_aggregate_versions', '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
        '0002_create_content_pages', '0003_create_content_posts', '0004_create_content_taxonomies',
    ];

    protected function setUp(): void
    {
        // Opt the WP-Cron stub into recording. By default the processing cron IS scheduled;
        // individual tests clear it to exercise the not-scheduled branch.
        $GLOBALS['_hsp_stub_scheduled'] = [ProcessingCronRegistrar::HOOK => 'hsp_processing'];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_scheduled']);
    }

    public function test_ready_when_cron_scheduled_heartbeat_fresh_and_migrations_applied(): void
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

    public function test_blocks_when_heartbeat_is_stale_even_if_cron_scheduled(): void
    {
        $gate = $this->gate(heartbeatAge: 120.0, offlineAfter: 60);

        self::assertFalse($gate->isReady());
        $worker = $this->gateByKey($gate->summary(), BackfillGate::GATE_WORKER);
        self::assertFalse($worker['passed']);
        self::assertStringContainsStringIgnoringCase('not advancing', $worker['detail']);
        $this->assertWpCronOnlyRemediation($worker['remediation']);
    }

    public function test_blocks_when_no_heartbeat_row_exists_even_if_cron_scheduled(): void
    {
        // No 'worker_heartbeats' script → MAX(...) age is null → no cycle has run.
        $reader = new BackfillReader(fn (): ScriptedConnection => (new ScriptedConnection())
            ->on('system.worker_heartbeats', [['age' => null]]));
        $gate   = new BackfillGate($reader, $this->migrations(self::ALL_MIGRATIONS), 60);

        self::assertFalse($gate->isReady());
        $worker = $this->gateByKey($gate->summary(), BackfillGate::GATE_WORKER);
        self::assertFalse($worker['passed']);
        self::assertStringContainsStringIgnoringCase('no processing cycle', $worker['detail']);
        $this->assertWpCronOnlyRemediation($worker['remediation']);
    }

    public function test_blocks_when_cron_not_scheduled_even_with_a_fresh_heartbeat(): void
    {
        // Fresh heartbeat, but the processing cron event is NOT scheduled → Option-C blocks.
        $GLOBALS['_hsp_stub_scheduled'] = [];

        $gate = $this->gate(heartbeatAge: 3.0, offlineAfter: 60);

        self::assertFalse($gate->isReady());
        $worker = $this->gateByKey($gate->summary(), BackfillGate::GATE_WORKER);
        self::assertFalse($worker['passed']);
        self::assertStringContainsStringIgnoringCase('not scheduled', $worker['detail']);
        $this->assertWpCronOnlyRemediation($worker['remediation']);
    }

    public function test_blocks_when_migrations_missing_even_with_a_live_cycle(): void
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

    /**
     * Assert the worker-gate remediation references WP-Cron only and gives NO supervised-worker
     * instruction. (An ADR-054 NEGATION like "there is no worker daemon to start" is permitted —
     * we ban only affirmative supervisor/systemd/restart directions.)
     */
    private function assertWpCronOnlyRemediation(string $remediation): void
    {
        self::assertNotSame('', $remediation);
        self::assertStringContainsStringIgnoringCase('wp-cron', $remediation);
        foreach (['supervisor', 'systemd', 'restart the worker', 'restart workers', 'start the worker', 'start the daemon'] as $banned) {
            self::assertStringNotContainsStringIgnoringCase($banned, $remediation);
        }
    }

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
