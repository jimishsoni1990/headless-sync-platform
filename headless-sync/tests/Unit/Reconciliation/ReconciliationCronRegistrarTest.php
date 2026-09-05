<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Reconciliation;

use HSP\Core\Reconciliation\ReconciliationCronRegistrar;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use PHPUnit\Framework\TestCase;

/**
 * ReconciliationCronRegistrar (DECISION U) — the three reconciliation WP-Cron triggers.
 *
 * The behaviour under test is WHEN the first run of a freshly scheduled event falls. Reconciliation
 * is a periodic backstop, and scheduling it at time() made it due on the very first cron tick after
 * plugin activation: a FULL reconciliation re-emitted the entire corpus before the operator ever
 * opened the onboarding page, so the first-run backfill found everything already projected and
 * degraded to a no-op — the onboarding flow completed itself with no user action (DECISION W
 * (b)/(d)). Every event must therefore start one full interval out.
 *
 * Uses the WP-Cron stubs in tests/bootstrap.php (opt-in recording).
 */
final class ReconciliationCronRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_filters']      = [];
        $GLOBALS['_hsp_stub_actions']      = [];
        $GLOBALS['_hsp_stub_scheduled']    = [];
        $GLOBALS['_hsp_stub_scheduled_at'] = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_hsp_stub_filters'],
            $GLOBALS['_hsp_stub_actions'],
            $GLOBALS['_hsp_stub_scheduled'],
            $GLOBALS['_hsp_stub_scheduled_at'],
        );
    }

    /**
     * The strategy is never invoked by register() — only bound as a callback — so it is built
     * without its constructor rather than dragging in a whole ReconciliationService graph.
     *
     * @param array<string,mixed> $config
     */
    private function makeRegistrar(array $config = []): ReconciliationCronRegistrar
    {
        $strategy = (new \ReflectionClass(ReconciliationWorkerStrategy::class))
            ->newInstanceWithoutConstructor();

        return new ReconciliationCronRegistrar($strategy, $config);
    }

    public function test_register_schedules_all_three_reconciliation_events(): void
    {
        $this->makeRegistrar()->register();

        self::assertArrayHasKey(ReconciliationCronRegistrar::HOOK_DRIFT, $GLOBALS['_hsp_stub_scheduled']);
        self::assertArrayHasKey(ReconciliationCronRegistrar::HOOK_INCREMENTAL, $GLOBALS['_hsp_stub_scheduled']);
        self::assertArrayHasKey(ReconciliationCronRegistrar::HOOK_FULL, $GLOBALS['_hsp_stub_scheduled']);
    }

    /**
     * The regression guard. A first run at (or before) now means activation triggers a full
     * re-emission on the next tick.
     */
    public function test_no_reconciliation_event_is_due_immediately(): void
    {
        $now = time();

        $this->makeRegistrar()->register();

        foreach ($GLOBALS['_hsp_stub_scheduled_at'] as $hook => $timestamp) {
            self::assertGreaterThan(
                $now,
                $timestamp,
                "{$hook} must not be due at activation time — it would pre-empt the onboarding backfill",
            );
        }
    }

    /** Each event's first run is one period of its own configured cadence out, not a flat delay. */
    public function test_first_run_is_one_full_interval_out_per_schedule(): void
    {
        $now = time();

        $this->makeRegistrar()->register();

        $at = $GLOBALS['_hsp_stub_scheduled_at'];

        // hourly / hsp_nightly / hsp_weekly are the defaults the registrar applies.
        self::assertEqualsWithDelta($now + HOUR_IN_SECONDS, $at[ReconciliationCronRegistrar::HOOK_DRIFT], 5);
        self::assertEqualsWithDelta($now + DAY_IN_SECONDS, $at[ReconciliationCronRegistrar::HOOK_INCREMENTAL], 5);
        self::assertEqualsWithDelta($now + 7 * DAY_IN_SECONDS, $at[ReconciliationCronRegistrar::HOOK_FULL], 5);
    }

    /** An already-scheduled event is left alone — re-registering must not shift its next run. */
    public function test_register_does_not_reschedule_an_existing_event(): void
    {
        $GLOBALS['_hsp_stub_scheduled'][ReconciliationCronRegistrar::HOOK_FULL] = 'hsp_weekly';

        $this->makeRegistrar()->register();

        self::assertArrayNotHasKey(
            ReconciliationCronRegistrar::HOOK_FULL,
            $GLOBALS['_hsp_stub_scheduled_at'],
            'wp_schedule_event must not be called for an event that is already scheduled',
        );
    }

    /** Cadence stays config-driven: a configured schedule name drives the first-run offset too. */
    public function test_configured_schedule_name_drives_the_offset(): void
    {
        $now = time();

        $this->makeRegistrar(['schedules' => ['drift' => 'daily']])->register();

        self::assertSame('daily', $GLOBALS['_hsp_stub_scheduled'][ReconciliationCronRegistrar::HOOK_DRIFT]);
        self::assertEqualsWithDelta(
            $now + DAY_IN_SECONDS,
            $GLOBALS['_hsp_stub_scheduled_at'][ReconciliationCronRegistrar::HOOK_DRIFT],
            5,
        );
    }
}
