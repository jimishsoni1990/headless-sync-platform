<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Contracts\WorkerInterface;
use HSP\Core\Workers\ProcessingCronRegistrar;
use HSP\Core\Workers\ProcessingCycleResult;
use PHPUnit\Framework\TestCase;

/**
 * ProcessingCronRegistrar (ADR-054 / Doc 8 v2.0 §23; DECISION X) — the WP-Cron trigger that
 * fires ONE bounded Processing Engine cycle per tick.
 *
 * Proves: registers a custom cadence interval + the recurring event; schedules only when not
 * already scheduled (wp_next_scheduled guard); the callback runs exactly one bounded cycle;
 * clearScheduled() removes the event; config-driven schedule name + interval (no hardcoded
 * cadence at the call site). Uses the WP-Cron stubs in tests/bootstrap.php (opt-in recording).
 */
final class ProcessingCronRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_filters']   = [];
        $GLOBALS['_hsp_stub_actions']   = [];
        $GLOBALS['_hsp_stub_scheduled'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_filters'], $GLOBALS['_hsp_stub_actions'], $GLOBALS['_hsp_stub_scheduled']);
    }

    public function test_register_adds_custom_interval_and_binds_callback(): void
    {
        $registrar = new ProcessingCronRegistrar(new SpyEngine());
        $registrar->register();

        self::assertContains('cron_schedules', $GLOBALS['_hsp_stub_filters'], 'custom interval filter registered');
        self::assertContains(ProcessingCronRegistrar::HOOK, $GLOBALS['_hsp_stub_actions'], 'the cycle callback is bound to the event');
    }

    public function test_register_schedules_the_processing_event_when_not_scheduled(): void
    {
        $registrar = new ProcessingCronRegistrar(new SpyEngine(), ['schedule' => 'hsp_processing']);
        $registrar->register();

        self::assertArrayHasKey(ProcessingCronRegistrar::HOOK, $GLOBALS['_hsp_stub_scheduled']);
        self::assertSame('hsp_processing', $GLOBALS['_hsp_stub_scheduled'][ProcessingCronRegistrar::HOOK]);
    }

    public function test_register_is_idempotent_does_not_double_schedule(): void
    {
        // Pre-seed the event as already scheduled with a marker schedule name.
        $GLOBALS['_hsp_stub_scheduled'][ProcessingCronRegistrar::HOOK] = 'already';

        (new ProcessingCronRegistrar(new SpyEngine()))->register();

        // wp_next_scheduled returned truthy → wp_schedule_event was NOT called again (marker unchanged).
        self::assertSame('already', $GLOBALS['_hsp_stub_scheduled'][ProcessingCronRegistrar::HOOK]);
    }

    public function test_custom_interval_uses_configured_period(): void
    {
        $registrar = new ProcessingCronRegistrar(new SpyEngine(), ['interval_seconds' => 45]);
        $schedules = $registrar->addSchedule([]);

        self::assertSame(45, $schedules['hsp_processing']['interval']);
    }

    public function test_callback_runs_exactly_one_bounded_cycle(): void
    {
        $engine = new SpyEngine();
        (new ProcessingCronRegistrar($engine))->runCycle();

        self::assertSame(1, $engine->cycles, 'the cron callback runs exactly one bounded cycle — no loop');
    }

    public function test_clear_scheduled_removes_the_event(): void
    {
        $GLOBALS['_hsp_stub_scheduled'][ProcessingCronRegistrar::HOOK] = 'hsp_processing';

        (new ProcessingCronRegistrar(new SpyEngine()))->clearScheduled();

        self::assertArrayNotHasKey(ProcessingCronRegistrar::HOOK, $GLOBALS['_hsp_stub_scheduled']);
    }
}

/** A WorkerInterface double that counts runCycle() invocations. */
final class SpyEngine implements WorkerInterface
{
    public int $cycles = 0;

    public function runCycle(): ProcessingCycleResult
    {
        $this->cycles++;
        return new ProcessingCycleResult(
            workerId: '01900000-0000-7000-8000-000000000001',
            relayed: 0, dispatched: 0, projected: 0,
            maintenanceSwept: true, budgetExhausted: false, elapsedSeconds: 0.0,
        );
    }

    public function getWorkerId(): string
    {
        return '01900000-0000-7000-8000-000000000001';
    }
}
