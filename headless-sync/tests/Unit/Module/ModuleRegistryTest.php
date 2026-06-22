<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Module\Exception\InvalidManifestException;
use HSP\Core\Module\ModuleDiscovery;
use HSP\Core\Module\ModuleLoader;
use HSP\Core\Module\ModuleManifest;
use HSP\Core\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModuleRegistry.
 *
 * ModuleDiscovery and ModuleLoader are final — replaced with concrete
 * test-double subclasses defined below so no mocking framework is needed.
 *
 * DoD requirements tested:
 *   - Discovery from manifest + resolution of module_class
 *   - Exact lifecycle ordering: ALL register() calls before ANY boot() call
 *   - activate / deactivate / upgrade forwarded to all modules
 *   - Rejection of a module missing a required interface method
 *   - Rejection of malformed / missing module.json
 */
final class ModuleRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        FakeModule::$globalCallLog = [];
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeManifest(string $name, string $class): ModuleManifest
    {
        return ModuleManifest::fromArray([
            'name'           => $name,
            'version'        => '1.0.0',
            'module_class'   => $class,
            'schema_version' => '1.0.0',
            'requires'       => [],
        ], "/modules/{$name}/module.json");
    }

    /**
     * Returns a ModuleDiscovery that always returns the given manifests.
     *
     * @param ModuleManifest[] $manifests
     */
    private function discoveryReturning(array $manifests): ModuleDiscovery
    {
        return new class($manifests) extends ModuleDiscovery {
            public function __construct(private readonly array $manifests)
            {
                // parent constructor requires $modulesBasePath — supply an unused value
                parent::__construct('/dev/null');
            }

            public function discover(): array
            {
                return $this->manifests;
            }
        };
    }

    /**
     * Returns a ModuleLoader that maps module_class strings to FakeModule instances.
     *
     * @param array<string, FakeModule> $map
     */
    private function loaderWith(array $map): ModuleLoader
    {
        return new class($map) extends ModuleLoader {
            public function __construct(private readonly array $map) {}

            public function load(ModuleManifest $manifest): ModuleInterface
            {
                if (! isset($this->map[$manifest->moduleClass])) {
                    throw new InvalidManifestException(
                        "No stub for '{$manifest->moduleClass}'"
                    );
                }
                return $this->map[$manifest->moduleClass];
            }
        };
    }

    /** Returns a ModuleLoader that always throws InvalidManifestException. */
    private function loaderAlwaysThrowing(): ModuleLoader
    {
        return new class extends ModuleLoader {
            public function load(ModuleManifest $manifest): ModuleInterface
            {
                throw new InvalidManifestException(
                    "Class does not implement ModuleInterface."
                );
            }
        };
    }

    // -----------------------------------------------------------------------
    // Discovery + registration
    // -----------------------------------------------------------------------

    public function testRegisterAndBootAreCalledOnDiscoveredModule(): void
    {
        $module   = new FakeModule('content');
        $manifest = $this->makeManifest('content', 'Fake\ContentModule');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([$manifest]),
            $this->loaderWith(['Fake\ContentModule' => $module]),
        );

        $registry->register();
        $registry->boot();

        $this->assertSame(['register', 'boot'], $module->calls);
    }

    public function testGetReturnsRegisteredModuleByName(): void
    {
        $module   = new FakeModule('content');
        $manifest = $this->makeManifest('content', 'Fake\ContentModule');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([$manifest]),
            $this->loaderWith(['Fake\ContentModule' => $module]),
        );

        $registry->register();

        $this->assertSame($module, $registry->get('content'));
        $this->assertNull($registry->get('nonexistent'));
    }

    public function testAllReturnsAllRegisteredModulesKeyedByName(): void
    {
        $m1 = new FakeModule('alpha');
        $m2 = new FakeModule('beta');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([
                $this->makeManifest('alpha', 'Fake\AlphaModule'),
                $this->makeManifest('beta',  'Fake\BetaModule'),
            ]),
            $this->loaderWith([
                'Fake\AlphaModule' => $m1,
                'Fake\BetaModule'  => $m2,
            ]),
        );

        $registry->register();

        $all = $registry->all();
        $this->assertCount(2, $all);
        $this->assertSame($m1, $all['alpha']);
        $this->assertSame($m2, $all['beta']);
    }

    // -----------------------------------------------------------------------
    // Lifecycle ordering (DoD: boot strictly after ALL register calls)
    // -----------------------------------------------------------------------

    public function testAllRegisterCallsFireBeforeAnyBootCall(): void
    {
        $m1 = new FakeModule('alpha');
        $m2 = new FakeModule('beta');
        $m3 = new FakeModule('gamma');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([
                $this->makeManifest('alpha', 'Fake\Alpha'),
                $this->makeManifest('beta',  'Fake\Beta'),
                $this->makeManifest('gamma', 'Fake\Gamma'),
            ]),
            $this->loaderWith([
                'Fake\Alpha' => $m1,
                'Fake\Beta'  => $m2,
                'Fake\Gamma' => $m3,
            ]),
        );

        $registry->register();
        $registry->boot();

        $expected = [
            'alpha:register',
            'beta:register',
            'gamma:register',
            'alpha:boot',
            'beta:boot',
            'gamma:boot',
        ];

        $this->assertSame($expected, FakeModule::$globalCallLog,
            'boot() must not be called on any module until register() has been called on ALL modules');
    }

    public function testBootOnFirstModuleDoesNotFireBeforeSecondModuleRegisters(): void
    {
        $m1 = new FakeModule('first');
        $m2 = new FakeModule('second');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([
                $this->makeManifest('first',  'Fake\First'),
                $this->makeManifest('second', 'Fake\Second'),
            ]),
            $this->loaderWith([
                'Fake\First'  => $m1,
                'Fake\Second' => $m2,
            ]),
        );

        $registry->register();
        $registry->boot();

        $log = FakeModule::$globalCallLog;

        $secondRegisterPos = array_search('second:register', $log, true);
        $firstBootPos      = array_search('first:boot',      $log, true);

        $this->assertNotFalse($secondRegisterPos);
        $this->assertNotFalse($firstBootPos);
        $this->assertLessThan(
            $firstBootPos,
            $secondRegisterPos,
            "'second:register' must appear before 'first:boot' in the call log"
        );
    }

    // -----------------------------------------------------------------------
    // activate / deactivate / upgrade forwarding
    // -----------------------------------------------------------------------

    public function testActivateForwardedToAllModules(): void
    {
        $m1 = new FakeModule('a');
        $m2 = new FakeModule('b');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([
                $this->makeManifest('a', 'Fake\A'),
                $this->makeManifest('b', 'Fake\B'),
            ]),
            $this->loaderWith(['Fake\A' => $m1, 'Fake\B' => $m2]),
        );

        $registry->register();
        $registry->activate();

        $this->assertContains('activate', $m1->calls);
        $this->assertContains('activate', $m2->calls);
    }

    public function testDeactivateForwardedToAllModules(): void
    {
        $m1 = new FakeModule('a');
        $m2 = new FakeModule('b');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([
                $this->makeManifest('a', 'Fake\A'),
                $this->makeManifest('b', 'Fake\B'),
            ]),
            $this->loaderWith(['Fake\A' => $m1, 'Fake\B' => $m2]),
        );

        $registry->register();
        $registry->deactivate();

        $this->assertContains('deactivate', $m1->calls);
        $this->assertContains('deactivate', $m2->calls);
    }

    public function testUpgradeForwardedToAllModules(): void
    {
        $m1 = new FakeModule('a');
        $m2 = new FakeModule('b');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([
                $this->makeManifest('a', 'Fake\A'),
                $this->makeManifest('b', 'Fake\B'),
            ]),
            $this->loaderWith(['Fake\A' => $m1, 'Fake\B' => $m2]),
        );

        $registry->register();
        $registry->upgrade();

        $this->assertContains('upgrade', $m1->calls);
        $this->assertContains('upgrade', $m2->calls);
    }

    // -----------------------------------------------------------------------
    // Full lifecycle sequence
    // -----------------------------------------------------------------------

    public function testFullLifecycleSequenceOnSingleModule(): void
    {
        $module   = new FakeModule('content');
        $manifest = $this->makeManifest('content', 'Fake\Content');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([$manifest]),
            $this->loaderWith(['Fake\Content' => $module]),
        );

        $registry->register();
        $registry->boot();
        $registry->activate();
        $registry->deactivate();
        $registry->upgrade();

        $this->assertSame(
            ['register', 'boot', 'activate', 'deactivate', 'upgrade'],
            $module->calls
        );
    }

    // -----------------------------------------------------------------------
    // Double-register guard
    // -----------------------------------------------------------------------

    public function testRegisterIsIdempotentWhenCalledTwice(): void
    {
        $module   = new FakeModule('content');
        $manifest = $this->makeManifest('content', 'Fake\Content');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([$manifest]),
            $this->loaderWith(['Fake\Content' => $module]),
        );

        $registry->register();
        $registry->register(); // second call must be a no-op

        $registerCalls = array_filter($module->calls, fn($c) => $c === 'register');
        $this->assertCount(1, $registerCalls,
            'register() on a module must be called exactly once');
    }

    // -----------------------------------------------------------------------
    // Rejection of non-ModuleInterface class
    // -----------------------------------------------------------------------

    public function testLoaderRejectionPropagates(): void
    {
        $manifest = $this->makeManifest('bad', 'Bad\Module');

        $registry = new ModuleRegistry(
            $this->discoveryReturning([$manifest]),
            $this->loaderAlwaysThrowing(),
        );

        $this->expectException(InvalidManifestException::class);
        $registry->register();
    }

    // -----------------------------------------------------------------------
    // Empty module set
    // -----------------------------------------------------------------------

    public function testBootOnEmptyRegistryPerformsNoLifecycleCalls(): void
    {
        $registry = new ModuleRegistry(
            $this->discoveryReturning([]),
            $this->loaderWith([]),
        );

        $registry->register();
        $registry->boot();

        $this->assertSame([], FakeModule::$globalCallLog);
        $this->assertSame([], $registry->all());
    }
}
