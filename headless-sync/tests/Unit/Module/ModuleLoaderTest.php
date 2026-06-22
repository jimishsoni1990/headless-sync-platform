<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Module\ModuleLoader;
use HSP\Core\Module\ModuleManifest;
use HSP\Core\Module\Exception\InvalidManifestException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModuleLoader.
 *
 * Verifies that:
 *   - A class that exists and implements ModuleInterface is returned.
 *   - A class that does not exist throws InvalidManifestException.
 *   - A class that exists but does not implement ModuleInterface throws InvalidManifestException.
 */
final class ModuleLoaderTest extends TestCase
{
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

    public function testLoadsValidModuleClass(): void
    {
        $loader   = new ModuleLoader();
        $manifest = $this->makeManifest(FakeModule::class);

        $module = $loader->load($manifest);

        $this->assertInstanceOf(FakeModule::class, $module);
    }

    public function testThrowsWhenClassDoesNotExist(): void
    {
        $loader   = new ModuleLoader();
        $manifest = $this->makeManifest('NonExistent\\Completely\\MissingClass');

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('NonExistent\\Completely\\MissingClass');

        $loader->load($manifest);
    }

    public function testThrowsWhenClassDoesNotImplementModuleInterface(): void
    {
        // Use a real class that is definitely autoloaded but is NOT a ModuleInterface
        $loader   = new ModuleLoader();
        $manifest = $this->makeManifest(\stdClass::class);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('stdClass');

        $loader->load($manifest);
    }
}
