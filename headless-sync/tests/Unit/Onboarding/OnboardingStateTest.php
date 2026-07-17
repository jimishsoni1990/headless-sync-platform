<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Onboarding\OnboardingState;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OnboardingState — the `hsp_onboarding_state` WP-option round-trip (ONB-S1b;
 * DECISION W (d)). get_option/update_option are stubbed in tests/bootstrap.php over
 * $GLOBALS['_hsp_stub_options'], so the MySQL-option round-trip is assertable without WordPress.
 * No schema change, no PG handle.
 */
final class OnboardingStateTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_options'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_options']);
    }

    public function test_defaults_to_pending_when_option_absent(): void
    {
        $state = new OnboardingState();

        self::assertSame(OnboardingStateInterface::PENDING, $state->current());
        self::assertFalse($state->isComplete());
    }

    public function test_marks_complete_round_trips_through_the_wp_option(): void
    {
        $state = new OnboardingState();
        $state->markComplete();

        // Persisted to the exact WP option name (MySQL) — no schema change.
        self::assertSame(
            OnboardingStateInterface::COMPLETE,
            $GLOBALS['_hsp_stub_options'][OnboardingStateInterface::OPTION_NAME],
        );

        // A fresh instance reads the same durable value back.
        $reread = new OnboardingState();
        self::assertTrue($reread->isComplete());
        self::assertSame(OnboardingStateInterface::COMPLETE, $reread->current());
    }

    public function test_rejects_an_unknown_state(): void
    {
        $state = new OnboardingState();

        $this->expectException(\InvalidArgumentException::class);
        $state->set('nonsense');
    }

    public function test_treats_an_unknown_persisted_value_as_pending(): void
    {
        $GLOBALS['_hsp_stub_options'][OnboardingStateInterface::OPTION_NAME] = 'garbage';

        self::assertSame(OnboardingStateInterface::PENDING, (new OnboardingState())->current());
    }
}
