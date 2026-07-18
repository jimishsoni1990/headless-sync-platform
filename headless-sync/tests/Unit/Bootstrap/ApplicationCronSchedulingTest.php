<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Bootstrap;

use HSP\Bootstrap\Application;
use HSP\Core\Reconciliation\ReconciliationCronRegistrar;
use HSP\Core\Workers\ProcessingCronRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Application activation/deactivation cron lifecycle (ADR-054 / Doc 8 v2.0 §24; DECISION X).
 *
 * deactivate() clears the processing-cycle event and the three reconciliation events via
 * wp_clear_scheduled_hook — proving the deactivation cleanup path (T2b). Activation scheduling
 * (which boots the container) is proven end-to-end in the live-PG integration test.
 */
final class ApplicationCronSchedulingTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_scheduled'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_scheduled']);
    }

    public function test_deactivate_clears_all_scheduled_hsp_cron_events(): void
    {
        // Pre-seed all four HSP cron events as scheduled.
        $GLOBALS['_hsp_stub_scheduled'] = [
            ProcessingCronRegistrar::HOOK             => 'hsp_processing',
            ReconciliationCronRegistrar::HOOK_DRIFT       => 'hourly',
            ReconciliationCronRegistrar::HOOK_INCREMENTAL => 'hsp_nightly',
            ReconciliationCronRegistrar::HOOK_FULL        => 'hsp_weekly',
        ];

        Application::getInstance()->deactivate();

        self::assertSame([], $GLOBALS['_hsp_stub_scheduled'], 'deactivate() clears every HSP scheduled cron event');
    }
}
