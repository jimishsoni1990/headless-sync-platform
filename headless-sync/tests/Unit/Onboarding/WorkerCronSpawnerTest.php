<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Onboarding\WorkerCronSpawner;
use HSP\Core\Workers\ProcessingCronRegistrar;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for WorkerCronSpawner — the heartbeat-gate remediation (ONB-S2 self-remediation;
 * DECISION W (c); DECISION X (4); ADR-054 §5/Principle 8).
 *
 * Proves: it ensures the processing cron is scheduled and issues a NON-BLOCKING WP-Cron spawn
 * (never runs a cycle inline — no in-request drain, DECISION W (c)); when DISABLE_WP_CRON is set it
 * spawns nothing and returns an explicit WP-Cron-only warning (never supervisor / systemd / daemon
 * wording — ADR-054 §5). add_filter / wp_next_scheduled / wp_schedule_event / spawn_cron are stubbed
 * in tests/bootstrap.php.
 */
final class WorkerCronSpawnerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_scheduled']        = [];
        $GLOBALS['_hsp_stub_spawn_cron_calls'] = 0;
        // The engine is never materialised — spawn() must not run a cycle inline.
        $GLOBALS['_hsp_stub_engine_ran'] = 0;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_hsp_stub_scheduled'],
            $GLOBALS['_hsp_stub_spawn_cron_calls'],
            $GLOBALS['_hsp_stub_engine_ran'],
        );
    }

    public function test_spawn_ensures_the_cron_is_scheduled_and_issues_a_non_blocking_spawn(): void
    {
        $result = $this->spawner()->spawn();

        self::assertTrue($result->spawned);
        self::assertFalse($result->disabled);
        self::assertSame('', $result->warning);
        // The processing cron was scheduled (wp_next_scheduled becomes truthy).
        self::assertArrayHasKey(ProcessingCronRegistrar::HOOK, $GLOBALS['_hsp_stub_scheduled']);
        // A non-blocking WP-Cron spawn was issued...
        self::assertSame(1, $GLOBALS['_hsp_stub_spawn_cron_calls']);
        // ...and NO cycle ran inline (no in-request drain — DECISION W (c)).
        self::assertSame(0, $GLOBALS['_hsp_stub_engine_ran']);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_spawn_warns_and_does_not_spawn_when_wp_cron_is_disabled(): void
    {
        define('DISABLE_WP_CRON', true);
        $GLOBALS['_hsp_stub_scheduled']        = [];
        $GLOBALS['_hsp_stub_spawn_cron_calls'] = 0;

        $result = $this->spawner()->spawn();

        self::assertFalse($result->spawned);
        self::assertTrue($result->disabled);
        self::assertNotSame('', $result->warning);
        // No spawn issued when WP-Cron is disabled.
        self::assertSame(0, $GLOBALS['_hsp_stub_spawn_cron_calls']);
        // The warning is WP-Cron-only — never supervisor / systemd / daemon / "restart" wording.
        foreach (['supervisor', 'systemd', 'daemon', 'restart'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase($forbidden, $result->warning);
        }
        self::assertStringContainsStringIgnoringCase('wp cron', $result->warning);
    }

    private function spawner(): WorkerCronSpawner
    {
        return new WorkerCronSpawner(
            new ProcessingCronRegistrar(
                // If the engine is ever materialised, record it — spawn() must NOT do this.
                static function (): \HSP\Core\Contracts\WorkerInterface {
                    $GLOBALS['_hsp_stub_engine_ran'] = ($GLOBALS['_hsp_stub_engine_ran'] ?? 0) + 1;
                    throw new \LogicException('engine must not run during spawn');
                },
                [],
            ),
        );
    }
}
