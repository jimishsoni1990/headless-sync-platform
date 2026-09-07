<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Container\Container;
use HSP\Core\Contracts\ModuleInterface;
use HSP\Core\Contracts\ServiceProviderInterface;
use HSP\Core\Module\ModuleLoader;
use HSP\Core\Module\ModuleManifest;
use HSP\Core\Module\Exception\InvalidManifestException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModuleLoader.
 *
 * Verifies (FLAG-P1AS6-2 Gap B — architect ruling 2026-06-24):
 *   - A no-dependency module class is returned via the fallback new $class() path.
 *   - A class that does not exist throws InvalidManifestException.
 *   - A class that exists but does not implement ModuleInterface throws InvalidManifestException.
 *   - A module with required constructor params is resolved via the container binding.
 *   - A module with required constructor params but NO container binding throws InvalidManifestException.
 */
final class ModuleLoaderTest extends TestCase
{
    private function makeContainer(): Container
    {
        return new Container();
    }

    private function makeManifest(string $class): ModuleManifest
    {
        return ModuleManifest::fromArray([
            'name'           => 'test',
            'version'        => '1.0.0',
            'module_class'   => $class,
            'schema_version' => '1.0.0',
            'requires'       => [],
        ], '/fake/module.json');
    }

    // -------------------------------------------------------------------------
    // Existing behaviour: no-arg module class (fallback path)
    // -------------------------------------------------------------------------

    public function testLoadsValidModuleClassWithNoArgs(): void
    {
        $loader   = new ModuleLoader($this->makeContainer());
        $manifest = $this->makeManifest(FakeModule::class);

        $module = $loader->load($manifest);

        $this->assertInstanceOf(FakeModule::class, $module);
    }

    public function testThrowsWhenClassDoesNotExist(): void
    {
        $loader   = new ModuleLoader($this->makeContainer());
        $manifest = $this->makeManifest('NonExistent\\Completely\\MissingClass');

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('NonExistent\\Completely\\MissingClass');

        $loader->load($manifest);
    }

    public function testThrowsWhenClassDoesNotImplementModuleInterface(): void
    {
        $loader   = new ModuleLoader($this->makeContainer());
        $manifest = $this->makeManifest(\stdClass::class);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('stdClass');

        $loader->load($manifest);
    }

    // -------------------------------------------------------------------------
    // Container-resolved path (FLAG-P1AS6-2 Gap B)
    // -------------------------------------------------------------------------

    public function testResolvesModuleThroughContainerWhenBindingExists(): void
    {
        $container = $this->makeContainer();
        $injected  = new \stdClass();

        $moduleWithDep = new class($injected) implements ModuleInterface {
            public function __construct(public readonly \stdClass $dep) {}
            public function getName(): string { return 'dep-module'; }
            public function getServiceProvider(): ServiceProviderInterface
            {
                return new class implements ServiceProviderInterface {
                    public function register(object $c): void {}
                    public function boot(object $c): void {}
                };
            }
            public function getMigrations(): array { return []; }
            public function getEventTypes(): array { return []; }
            public function register(): void {}
            public function boot(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function upgrade(): void {}
        };

        $class = get_class($moduleWithDep);
        $container->singleton($class, fn () => $moduleWithDep);

        $loader   = new ModuleLoader($container);
        $manifest = $this->makeManifest($class);
        $resolved = $loader->load($manifest);

        $this->assertSame($moduleWithDep, $resolved);
        $this->assertSame($injected, $resolved->dep);
    }

    public function testThrowsWhenRequiredConstructorArgsExistButNoContainerBinding(): void
    {
        // A class with a required constructor arg but no container binding registered.
        $class = get_class(new class(new \stdClass()) implements ModuleInterface {
            public function __construct(private readonly \stdClass $dep) {}
            public function getName(): string { return 'bad'; }
            public function getServiceProvider(): ServiceProviderInterface
            {
                return new class implements ServiceProviderInterface {
                    public function register(object $c): void {}
                    public function boot(object $c): void {}
                };
            }
            public function getMigrations(): array { return []; }
            public function getEventTypes(): array { return []; }
            public function register(): void {}
            public function boot(): void {}
            public function activate(): void {}
            public function deactivate(): void {}
            public function upgrade(): void {}
        });

        $loader   = new ModuleLoader($this->makeContainer());
        $manifest = $this->makeManifest($class);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('required constructor parameters');

        $loader->load($manifest);
    }

    // -------------------------------------------------------------------------
    // Container binding takes priority over fallback even for no-arg modules
    // -------------------------------------------------------------------------

    public function testContainerBindingTakesPriorityOverFallback(): void
    {
        $container = $this->makeContainer();
        $canonical = new FakeModule('canonical');
        $container->singleton(FakeModule::class, fn () => $canonical);

        $loader   = new ModuleLoader($container);
        $manifest = $this->makeManifest(FakeModule::class);
        $resolved = $loader->load($manifest);

        $this->assertSame($canonical, $resolved);
        $this->assertSame('canonical', $resolved->getName());
    }
}
