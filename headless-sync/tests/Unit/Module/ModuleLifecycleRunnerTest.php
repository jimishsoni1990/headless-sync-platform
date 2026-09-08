<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Contracts\ServiceProviderInterface;
use HSP\Core\Module\ModuleBootstrapState;
use HSP\Core\Module\ModuleLifecycleCoordinator;
use HSP\Core\Module\ModuleLifecycleRunner;
use PHPUnit\Framework\TestCase;

/**
 * AG-12's automatic activation transition, driven end to end.
 *
 * `ModuleLifecycleTest` proves the coordinator's LOGIC and `CommerceBootstrapTest` proves the
 * Commerce module's shape. Both passed while the transition was completely dead, because nothing
 * called `evaluate()` anywhere in production code — a repo-wide grep found only the container
 * binding and tests that construct the coordinator directly.
 *
 * The consequence turned up on a live site: HSP installed, onboarding complete, Content fully
 * synced, WooCommerce active with eighteen products, and no `commerce` schema in PostgreSQL at
 * all. AG-12 requires that case to converge with "no reactivation, no manual migrate and no
 * manual reconcile"; all three were needed.
 *
 * These tests therefore assert the SEQUENCE rather than any single decision: not-ready triggers
 * migrations, newly-ready owes a bootstrap, the bootstrap is module-scoped, failure is contained
 * and retried, and a converged module then costs nothing.
 *
 * The real {@see ModuleBootstrapState} is used throughout — it is `final`, and more usefully it
 * reads and writes through the suite's WordPress option stubs, so these tests exercise the actual
 * persistence rather than a stand-in that could disagree with it.
 */
final class ModuleLifecycleRunnerTest extends TestCase
{
    /** @var list<string> */
    private array $migrationCalls = [];

    /** @var list<list<string>> the aggregate scopes reconciliation was asked for */
    private array $reconciled = [];

    private ModuleBootstrapState $state;

    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_options'] = [];

        $this->migrationCalls = [];
        $this->reconciled     = [];
        $this->state          = new ModuleBootstrapState();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_options']);
    }

    /**
     * @param list<string> $readyModules              modules whose migrations are already applied
     * @param list<string> $becomeReadyAfterMigrations modules that become ready once migrations run
     * @param array<string, ModuleInterface>|null $modules
     */
    private function runner(
        array $readyModules,
        array $becomeReadyAfterMigrations = [],
        bool $migrationsSucceed = true,
        ?array $modules = null,
    ): ModuleLifecycleRunner {
        $ready = $readyModules;

        $coordinator = new ModuleLifecycleCoordinator(
            $this->state,
            static function (string $name) use (&$ready): bool {
                return in_array($name, $ready, true);
            },
            static function (string $name, string $version): void {
            },
        );

        $modules ??= [
            'content'  => new FakeLifecycleModule('content', ['content.post.created', 'content.page.created']),
            'commerce' => new FakeLifecycleModule('commerce', [
                'commerce.product.created',
                'commerce.product.updated',
                'commerce.inventory.updated',
            ]),
        ];

        return new ModuleLifecycleRunner(
            $coordinator,
            $this->state,
            static fn (): array => $modules,
            function () use (&$ready, $becomeReadyAfterMigrations, $migrationsSucceed): array {
                $this->migrationCalls[] = 'apply';

                if (! $migrationsSucceed) {
                    return ['ran' => false, 'error' => 'PostgreSQL unreachable'];
                }

                foreach ($becomeReadyAfterMigrations as $m) {
                    if (! in_array($m, $ready, true)) {
                        $ready[] = $m;
                    }
                }

                return ['ran' => true, 'error' => ''];
            },
            function (array $aggregates): void {
                $this->reconciled[] = $aggregates;
            },
        );
    }

    // -------------------------------------------------------------------------

    /**
     * THE headline case: a module that becomes available later converges on its own.
     *
     * No reactivation, no manual migrate, no manual reconcile — the three things AG-12 forbids
     * requiring, and the three things the live site actually needed.
     */
    public function testANewlyAvailableModuleGetsMigrationsThenBootstrapsItself(): void
    {
        // Content is already converged; Commerce has just appeared and has no schema yet.
        $this->state->markComplete('content');

        $this->runner(
            readyModules: ['content'],
            becomeReadyAfterMigrations: ['commerce'],
        )->run();

        self::assertSame(['apply'], $this->migrationCalls, 'migrations must be attempted');
        self::assertTrue($this->state->isComplete('commerce'), 'and the module must end up converged');
        self::assertCount(1, $this->reconciled, 'exactly one bootstrap re-emission');
    }

    /**
     * The bootstrap is MODULE-SCOPED — Commerce's aggregates, not Content's.
     *
     * An unscoped pass would also work, but would re-scan a sibling that converged long ago, and
     * the scope is what AG-12 asks for.
     */
    public function testTheBootstrapIsScopedToTheModulesOwnAggregates(): void
    {
        $this->state->markComplete('content');

        $this->runner(['content'], ['commerce'])->run();

        self::assertSame(
            [['product', 'inventory']],
            $this->reconciled,
            "Commerce's aggregates only, derived from its declared event vocabulary",
        );
    }

    /** A converged module costs nothing — no migration attempt, no reconciliation. */
    public function testAConvergedModuleIsUntouched(): void
    {
        $this->state->markComplete('content');
        $this->state->markComplete('commerce');

        $this->runner(['content', 'commerce'])->run();

        self::assertSame([], $this->migrationCalls, 'no PostgreSQL round trip in the steady state');
        self::assertSame([], $this->reconciled);
    }

    /**
     * Migrations that cannot run leave the module not ready — and crucially, not bootstrapped.
     *
     * AG-12: a module is never runtime-ready merely because it is available, and marking it
     * pending here would let a backfill start against schema that does not exist.
     */
    public function testAModuleWhoseMigrationsFailIsNotBootstrapped(): void
    {
        $this->state->markComplete('content');

        $this->runner(['content'], [], migrationsSucceed: false)->run();

        self::assertSame([], $this->reconciled, 'no bootstrap against missing schema');
        self::assertFalse($this->state->isComplete('commerce'));
    }

    /** And it is retried on the next cycle rather than being given up on. */
    public function testAFailedModuleIsRetriedOnTheNextCycle(): void
    {
        $this->state->markComplete('content');

        $this->runner(['content'], [], migrationsSucceed: false)->run();
        self::assertFalse($this->state->isComplete('commerce'));

        // A fresh runner is what the next cron cycle builds.
        $this->runner(['content'], ['commerce'])->run();

        self::assertTrue($this->state->isComplete('commerce'));
        self::assertCount(1, $this->reconciled);
    }

    /**
     * One module's failure must never stop another's progress (AG-12).
     */
    public function testOneModulesFailureDoesNotStopTheOthers(): void
    {
        $this->runner(
            readyModules: ['broken', 'content'],
            modules: [
                'broken'  => new ThrowingLifecycleModule('broken'),
                'content' => new FakeLifecycleModule('content', ['content.post.created']),
            ],
        )->run();

        self::assertSame([['post']], $this->reconciled, 'the healthy module still converged');
        self::assertTrue($this->state->isComplete('content'));
    }

    /** A module resolution failure is survivable — the cron cycle must still run. */
    public function testAnUnresolvableModuleListIsNotFatal(): void
    {
        $runner = new ModuleLifecycleRunner(
            new ModuleLifecycleCoordinator(
                $this->state,
                static fn (string $n): bool => true,
                static function (string $n, string $v): void {
                },
            ),
            $this->state,
            static fn (): array => throw new \RuntimeException('container exploded'),
            static fn (): array => ['ran' => true, 'error' => ''],
            static function (array $a): void {
            },
        );

        $runner->run();

        self::assertTrue(true, 'run() returned without throwing');
    }

    /** A module owning no aggregates is complete immediately — nothing to converge. */
    public function testAModuleWithNoAggregatesNeedsNoBootstrap(): void
    {
        $this->runner(
            readyModules: ['empty'],
            modules: ['empty' => new FakeLifecycleModule('empty', [])],
        )->run();

        self::assertTrue($this->state->isComplete('empty'));
        self::assertSame([], $this->reconciled);
    }
}

/** A module that declares a name and an event vocabulary; nothing else is reached. */
class FakeLifecycleModule implements ModuleInterface
{
    /** @param list<string> $eventTypes */
    public function __construct(
        private readonly string $name,
        private readonly array $eventTypes = [],
    ) {
    }

    public function getName(): string { return $this->name; }
    public function getServiceProvider(): ServiceProviderInterface { throw new \LogicException('not reached'); }
    /** @return array<int,mixed> */
    public function getMigrations(): array { return []; }
    /** @return list<string> */
    public function getEventTypes(): array { return $this->eventTypes; }
    public function register(): void {}
    public function boot(): void {}
    public function activate(): void {}
    public function deactivate(): void {}
    public function upgrade(): void {}
}

/** A module whose event vocabulary blows up — the containment case. */
final class ThrowingLifecycleModule extends FakeLifecycleModule
{
    /** @return list<string> */
    public function getEventTypes(): array
    {
        throw new \RuntimeException('this module is broken');
    }
}
