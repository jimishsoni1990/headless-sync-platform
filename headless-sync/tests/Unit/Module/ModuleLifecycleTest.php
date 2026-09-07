<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Module\ModuleBootstrapState;
use HSP\Core\Module\ModuleLifecycleCoordinator;
use PHPUnit\Framework\TestCase;

/**
 * P2-S1 acceptance — module availability, readiness and bootstrap lifecycle
 * (DECISION AG AG-12).
 *
 * The scenario these protect is the one Doc 11 §11 never covered: HSP already installed and
 * globally onboarded, then a new domain module becomes available with source data already
 * present. Without an explicit lifecycle only future edits would sync.
 *
 * Asserted against a test-scoped fixture, not Commerce — P2-S1 ships no Commerce code.
 */
final class ModuleLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        // tests/bootstrap.php backs get_option()/update_option() with this global.
        $GLOBALS['_hsp_stub_options'] = [];
    }

    /** @param list<string> $ready module names whose migrations have applied */
    private function coordinator(array $ready, ?array &$recorded = null): ModuleLifecycleCoordinator
    {
        $recorded ??= [];

        return new ModuleLifecycleCoordinator(
            new ModuleBootstrapState(),
            static fn (string $module): bool => in_array($module, $ready, true),
            static function (string $module, string $version) use (&$recorded): void {
                $recorded[] = $module . '@' . $version;
            },
        );
    }

    // -------------------------------------------------------------------------
    // Availability is not readiness
    // -------------------------------------------------------------------------

    /**
     * The window AG-12 exists to close: available, but migrations have not applied. Marking
     * such a module pending would invite a backfill against schema that does not exist.
     */
    public function testAvailableButUnmigratedModuleIsNotReadyAndNotPending(): void
    {
        $status = $this->coordinator(ready: [])->evaluate('secondary', '1.0.0');

        self::assertFalse($status->ready);
        self::assertSame(ModuleBootstrapState::UNKNOWN, $status->bootstrapState);
        self::assertFalse($status->needsBootstrap());
    }

    public function testAFailingMigrationProbeLeavesTheModuleNotReadyRatherThanFataling(): void
    {
        $coordinator = new ModuleLifecycleCoordinator(
            new ModuleBootstrapState(),
            static fn (string $m): bool => throw new \RuntimeException('PostgreSQL unreachable'),
            static fn (string $m, string $v) => null,
        );

        // An unconfigured or unreachable database is a normal state on a fresh site
        // (ADR-054 Principle 8), never a page-load fatal.
        $status = $coordinator->evaluate('secondary', '1.0.0');

        self::assertFalse($status->ready);
    }

    // -------------------------------------------------------------------------
    // The upgrade path: a module becomes ready later
    // -------------------------------------------------------------------------

    public function testNewlyReadyModuleBecomesBootstrapPending(): void
    {
        $status = $this->coordinator(ready: ['secondary'])->evaluate('secondary', '1.0.0');

        self::assertTrue($status->ready);
        self::assertSame(ModuleBootstrapState::PENDING, $status->bootstrapState);
        self::assertTrue($status->needsBootstrap(), 'its existing source data still owes a projection');
    }

    public function testConvergenceFlipsPendingToComplete(): void
    {
        $coordinator = $this->coordinator(ready: ['secondary']);
        $coordinator->evaluate('secondary', '1.0.0');

        $coordinator->markBootstrapComplete('secondary');

        $status = $coordinator->evaluate('secondary', '1.0.0');
        self::assertSame(ModuleBootstrapState::COMPLETE, $status->bootstrapState);
        self::assertFalse($status->needsBootstrap());
    }

    public function testEvaluationIsIdempotentAndDoesNotReopenACompletedBootstrap(): void
    {
        $coordinator = $this->coordinator(ready: ['secondary']);
        $coordinator->evaluate('secondary', '1.0.0');
        $coordinator->markBootstrapComplete('secondary');

        for ($i = 0; $i < 3; $i++) {
            $coordinator->evaluate('secondary', '1.0.0');
        }

        self::assertSame(ModuleBootstrapState::COMPLETE, (new ModuleBootstrapState())->get('secondary'));
    }

    /**
     * A fresh install whose global onboarding already covered the module must NOT run a
     * second, duplicate backfill afterwards (AG-12's explicit carve-out).
     */
    public function testGlobalOnboardingAdoptionSkipsADuplicateBootstrap(): void
    {
        $coordinator = $this->coordinator(ready: ['secondary']);

        $coordinator->adoptGlobalOnboarding('secondary');

        self::assertFalse($coordinator->evaluate('secondary', '1.0.0')->needsBootstrap());
    }

    // -------------------------------------------------------------------------
    // AG-6 — the module version is recorded only once ready
    // -------------------------------------------------------------------------

    public function testSchemaVersionIsRecordedOnlyAfterTheModuleIsReady(): void
    {
        $recorded = [];

        $this->coordinator(ready: [], recorded: $recorded)->evaluate('secondary', '2.1.0');
        self::assertSame([], $recorded, 'nothing may be recorded before the migration batch succeeds');

        $this->coordinator(ready: ['secondary'], recorded: $recorded)->evaluate('secondary', '2.1.0');
        self::assertSame(['secondary@2.1.0'], $recorded);
    }

    // -------------------------------------------------------------------------
    // Lost-update safety — the requirement AG-12 states explicitly
    // -------------------------------------------------------------------------

    public function testUpdatingOneModuleNeverErasesASibling(): void
    {
        $state = new ModuleBootstrapState();

        $state->markComplete('content');
        $state->markPending('secondary');
        $state->markComplete('secondary');

        self::assertSame(ModuleBootstrapState::COMPLETE, $state->get('content'));
        self::assertSame(ModuleBootstrapState::COMPLETE, $state->get('secondary'));

        // Separate option rows are what makes this structural rather than a matter of
        // careful read-modify-write sequencing.
        self::assertNotSame(
            ModuleBootstrapState::optionName('content'),
            ModuleBootstrapState::optionName('secondary'),
        );
    }

    public function testUnknownIsDistinctFromPending(): void
    {
        $state = new ModuleBootstrapState();

        self::assertSame(ModuleBootstrapState::UNKNOWN, $state->get('never-seen'));

        $state->markPending('never-seen');
        self::assertSame(ModuleBootstrapState::PENDING, $state->get('never-seen'));
    }
}
