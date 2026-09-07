<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Contracts\ModuleAvailabilityInterface;
use HSP\Core\Contracts\ServiceProviderInterface;
use HSP\Core\Module\Exception\InvalidManifestException;
use HSP\Core\Module\ModuleDiscovery;
use HSP\Core\Module\ModuleProviderComposer;
use PHPUnit\Framework\TestCase;

/**
 * P2-S1 acceptance — the platform is GENERICALLY multi-module (DECISION AG AG-1, AG-12).
 *
 * These assertions are deliberately made against a test-scoped second-domain fixture
 * rather than a real Commerce module: P2-S1 ships no Commerce code, and requiring one
 * here would drag production Commerce into the row whose whole point is that it has
 * none. The real ContentModule + CommerceModule assertion belongs to P2-S2.
 */
final class MultiModuleCompositionTest extends TestCase
{
    private string $tmpDir = '';

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            $this->removeTree($this->tmpDir);
        }
        $this->tmpDir = '';
    }

    // -------------------------------------------------------------------------
    // AG-1 — core must not know concrete modules
    // -------------------------------------------------------------------------

    /**
     * The composition path must not import a concrete module class. This is the
     * assertion that makes "adding Commerce requires zero concrete Commerce reference
     * under core/" mechanically true instead of a matter of review discipline.
     *
     * Scans for `use HSP\Modules\...` statements — the form that creates a real compile
     * -time dependency. Prose mentions of a module in a docblock are not coupling.
     */
    public function testCoreContainsNoConcreteModuleImport(): void
    {
        $roots = [
            \dirname(__DIR__, 3) . '/core',
            \dirname(__DIR__, 3) . '/bootstrap',
            \dirname(__DIR__, 3) . '/database',
        ];

        $offenders = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                if ($contents === false) {
                    continue;
                }

                if (preg_match('/^\s*use\s+HSP\\\\Modules\\\\/m', $contents) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "core/, bootstrap/ and database/ must not import a concrete module class (DECISION AG AG-1).\n"
            . "Offending files:\n" . implode("\n", $offenders)
        );
    }

    // -------------------------------------------------------------------------
    // AG-1 — two independent modules compose simultaneously
    // -------------------------------------------------------------------------

    public function testTwoIndependentModulesComposeSimultaneously(): void
    {
        $this->makeModuleTree([
            'alpha' => SecondDomainProviderAlpha::class,
            'beta'  => SecondDomainProviderBeta::class,
        ]);

        $composition = $this->compose();

        self::assertSame(['alpha', 'beta'], $composition->availableModules);
        self::assertCount(2, $composition->providers);
        self::assertInstanceOf(SecondDomainProviderAlpha::class, $composition->providers[0]);
        self::assertInstanceOf(SecondDomainProviderBeta::class, $composition->providers[1]);
        self::assertSame([], $composition->unavailableModules);
    }

    /**
     * Registering a second module must not displace the first. Before AG-2 the container
     * was last-writer-wins, so a second module binding a shared interface key silently
     * deleted the first module's implementation with no error at all.
     */
    public function testSecondModuleDoesNotDisplaceTheFirst(): void
    {
        $this->makeModuleTree([
            'alpha' => SecondDomainProviderAlpha::class,
            'beta'  => SecondDomainProviderBeta::class,
        ]);

        $providers = $this->compose()->providers;

        $classes = array_map(static fn (ServiceProviderInterface $p): string => $p::class, $providers);

        self::assertContains(SecondDomainProviderAlpha::class, $classes);
        self::assertContains(SecondDomainProviderBeta::class, $classes);
    }

    // -------------------------------------------------------------------------
    // AG-12 — availability is declared, never inferred
    // -------------------------------------------------------------------------

    public function testUnavailableModuleContributesNothing(): void
    {
        $this->makeModuleTree([
            'alpha'       => SecondDomainProviderAlpha::class,
            'unavailable' => UnavailableDomainProvider::class,
        ]);

        $composition = $this->compose();

        self::assertSame(['alpha'], $composition->availableModules);
        self::assertSame(['unavailable'], $composition->unavailableModules);
        self::assertCount(1, $composition->providers, 'An unavailable module must contribute no provider.');
        self::assertInstanceOf(SecondDomainProviderAlpha::class, $composition->providers[0]);
    }

    public function testAvailableModuleIsComposedWhenItReportsAvailable(): void
    {
        $this->makeModuleTree(['conditional' => ConditionalDomainProvider::class]);

        ConditionalDomainProvider::$available = false;
        self::assertSame(['conditional'], $this->compose()->unavailableModules);

        ConditionalDomainProvider::$available = true;
        self::assertSame(['conditional'], $this->compose()->availableModules);
    }

    // -------------------------------------------------------------------------
    // AG-1 — misconfiguration fails loudly
    // -------------------------------------------------------------------------

    public function testDuplicateModuleNameFailsLoudly(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/hsp-mods-' . uniqid('', true);

        // Two directories, one declared name — the collision a name-keyed registry,
        // bootstrap-state option and module_versions row would all silently suffer.
        $this->writeManifest($this->tmpDir . '/one', 'dup', SecondDomainProviderAlpha::class);
        $this->writeManifest($this->tmpDir . '/two', 'dup', SecondDomainProviderBeta::class);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessageMatches('/Duplicate module name/');

        $this->compose();
    }

    public function testMissingProviderClassFailsLoudly(): void
    {
        $this->makeModuleTree(['ghost' => 'HSP\\Tests\\Support\\NoSuchProviderClass']);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessageMatches('/could not be found/');

        $this->compose();
    }

    public function testProviderRequiringConstructorArgumentsFailsLoudly(): void
    {
        $this->makeModuleTree(['needy' => NeedyDomainProvider::class]);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessageMatches('/required constructor parameters/');

        $this->compose();
    }

    public function testNonProviderClassFailsLoudly(): void
    {
        $this->makeModuleTree(['wrong' => NotAProvider::class]);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessageMatches('/does not implement/');

        $this->compose();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @param array<string,string> $modules name => provider class */
    private function makeModuleTree(array $modules): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/hsp-mods-' . uniqid('', true);

        foreach ($modules as $name => $providerClass) {
            $this->writeManifest($this->tmpDir . '/' . $name, $name, $providerClass);
        }
    }

    private function writeManifest(string $dir, string $name, string $providerClass): void
    {
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/module.json', json_encode([
            'name'             => $name,
            'version'          => '1.0.0',
            'module_class'     => 'HSP\\Tests\\Support\\FakeModuleServiceProvider',
            'service_provider' => $providerClass,
            'schema_version'   => '1.0.0',
            'requires'         => [],
        ]));
    }

    private function compose(): \HSP\Core\Module\ModuleComposition
    {
        return (new ModuleProviderComposer(new ModuleDiscovery($this->tmpDir)))->compose();
    }

    private function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}

// -----------------------------------------------------------------------------
// Test-scoped second-domain fixtures — no WooCommerce business logic (P2-S1 rule)
// -----------------------------------------------------------------------------

final class SecondDomainProviderAlpha implements ServiceProviderInterface
{
    public function register(object $container): void {}
    public function boot(object $container): void {}
}

final class SecondDomainProviderBeta implements ServiceProviderInterface
{
    public function register(object $container): void {}
    public function boot(object $container): void {}
}

final class UnavailableDomainProvider implements ServiceProviderInterface, ModuleAvailabilityInterface
{
    public function isAvailable(): bool { return false; }
    public function register(object $container): void {}
    public function boot(object $container): void {}
}

final class ConditionalDomainProvider implements ServiceProviderInterface, ModuleAvailabilityInterface
{
    public static bool $available = true;

    public function isAvailable(): bool { return self::$available; }
    public function register(object $container): void {}
    public function boot(object $container): void {}
}

final class NeedyDomainProvider implements ServiceProviderInterface
{
    public function __construct(private readonly string $required) {}
    public function register(object $container): void {}
    public function boot(object $container): void {}
}

final class NotAProvider
{
}
