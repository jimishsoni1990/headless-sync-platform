<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Bootstrap;

use HSP\Bootstrap\Application;
use HSP\Core\Container\Container;
use HSP\Core\Onboarding\MigrationApplier;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Tests\Unit\Onboarding\SpyMigrationApplier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the activation/upgrade migration guard (Application::attemptPendingMigrations()).
 *
 * The DoD requires: activation attempts pending migrations IFF HSP_PG_* are defined AND PG is
 * reachable, and NEVER fatals on an unconfigured site (OPEN-9 lifecycle; ADR-054 Principle 8;
 * DECISION W (f) v1.23). In the headless PHPUnit process no HSP_PG_* constants are defined and
 * getenv() returns false for them, so the guard's first (connection-free) predicate — PgConstantsCheck
 * — fails and the applier is NEVER reached: no connection is attempted, nothing can fatal.
 *
 * These tests drive the real private guard via reflection with a stub container, so they need no
 * live database and no full container boot (which would eagerly resolve the runtime PG handles).
 */
final class ApplicationMigrationGuardTest extends TestCase
{
    public function test_guard_is_a_silent_noop_and_does_not_reach_the_applier_when_pg_constants_are_absent(): void
    {
        // Precondition for this environment: HSP_PG_* are not defined (headless PHPUnit process).
        self::assertFalse(defined('HSP_PG_HOST'), 'this test assumes HSP_PG_* are undefined in the unit env');

        $applier = new SpyMigrationApplier(true);
        $probe   = new SpyProbe(isReachable: true);

        $container = new Container();
        $container->instance(MigrationApplier::class, $applier);
        $container->instance(OnboardingConnectionProbe::class, $probe);

        $this->invokeGuard($this->freshApplicationWith($container));

        // Constants absent → guard returns before touching the probe OR the applier. No fatal.
        self::assertSame(0, $probe->reachableCalls, 'no connection is attempted when unconfigured');
        self::assertSame(0, $applier->applyCalls, 'the engine is not invoked when unconfigured');
    }

    public function test_guard_is_a_noop_with_a_null_container(): void
    {
        // Before boot the container is null; the guard must simply return.
        $app = $this->freshApplicationWith(null);

        $this->invokeGuard($app); // must not throw

        $this->addToAssertionCount(1);
    }

    // --- helpers ------------------------------------------------------------

    private function freshApplicationWith(?Container $container): Application
    {
        // Application has a private constructor + private $container; build a detached instance so
        // this test never touches the process-wide singleton.
        $ref = new \ReflectionClass(Application::class);
        /** @var Application $app */
        $app  = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('container');
        $prop->setAccessible(true);
        $prop->setValue($app, $container);

        return $app;
    }

    private function invokeGuard(Application $app): void
    {
        $method = new \ReflectionMethod(Application::class, 'attemptPendingMigrations');
        $method->setAccessible(true);
        $method->invoke($app);
    }
}
