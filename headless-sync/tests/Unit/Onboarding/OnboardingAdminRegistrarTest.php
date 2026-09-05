<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Onboarding\OnboardingAdminRegistrar;
use HSP\Core\Onboarding\OnboardingPageController;
use PHPUnit\Framework\TestCase;

/**
 * OnboardingAdminRegistrar — the first-run surface's wp-admin registration gate.
 *
 * Onboarding is a FIRST-RUN surface, so it must stop registering once it has completed. Without
 * the gate the sidebar kept a permanent "HSP Onboarding" top-level item beside "HSP Operations"
 * forever — a setup wizard advertising itself long after setup.
 *
 * This gate and ConsoleAdminRegistrar's are exact complements (DECISION W (f)): before completion
 * only onboarding registers; after completion only the console does. Exactly one HSP top-level
 * menu exists at any time.
 *
 * Uses the WP hook stubs in tests/bootstrap.php (opt-in recording).
 */
final class OnboardingAdminRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_actions'] = [];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_actions']);
    }

    private function makeRegistrar(bool $complete): OnboardingAdminRegistrar
    {
        return new OnboardingAdminRegistrar(
            new OnboardingPageController('1.0.0'),
            new FakeOnboardingState($complete),
        );
    }

    public function test_registers_the_onboarding_page_while_onboarding_is_incomplete(): void
    {
        $this->makeRegistrar(complete: false)->register();

        self::assertContains('admin_menu', $GLOBALS['_hsp_stub_actions']);
        self::assertContains('admin_enqueue_scripts', $GLOBALS['_hsp_stub_actions']);
    }

    public function test_registers_nothing_once_onboarding_is_complete(): void
    {
        $this->makeRegistrar(complete: true)->register();

        self::assertSame([], $GLOBALS['_hsp_stub_actions']);
    }
}

/** Minimal OnboardingStateInterface double — the gate only reads isComplete(). */
final class FakeOnboardingState implements OnboardingStateInterface
{
    public function __construct(private bool $complete) {}

    public function current(): string
    {
        return $this->complete ? 'complete' : 'pending';
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function set(string $state): void
    {
        $this->complete = $state === 'complete';
    }

    public function markComplete(): void
    {
        $this->complete = true;
    }
}
