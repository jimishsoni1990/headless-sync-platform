<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Module\ModuleDiscovery;
use HSP\Core\Module\Exception\InvalidManifestException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModuleDiscovery.
 *
 * Uses a real temporary directory so glob() behaves naturally.
 * No real database or WordPress required.
 */
final class ModuleDiscoveryTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/hsp_mod_disc_' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeManifest(string $moduleName, array $data): void
    {
        $dir = $this->tempDir . '/' . $moduleName;
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/module.json', json_encode($data));
    }

    private function validManifestData(string $name = 'content'): array
    {
        return [
            'name'           => $name,
            'version'        => '1.0.0',
            'module_class'   => 'HSP\\Modules\\' . ucfirst($name) . '\\Module',
            'schema_version' => '1.0.0',
            'requires'       => [],
        ];
    }

    // -----------------------------------------------------------------------
    // Happy path
    // -----------------------------------------------------------------------

    public function testDiscoversNothingFromEmptyDirectory(): void
    {
        $discovery = new ModuleDiscovery($this->tempDir);
        $this->assertSame([], $discovery->discover());
    }

    public function testDiscoversSingleModule(): void
    {
        $this->writeManifest('Content', $this->validManifestData('content'));

        $discovery = new ModuleDiscovery($this->tempDir);
        $manifests = $discovery->discover();

        $this->assertCount(1, $manifests);
        $this->assertSame('content', $manifests[0]->name);
        $this->assertSame('HSP\\Modules\\Content\\Module', $manifests[0]->moduleClass);
    }

    public function testDiscoversMultipleModulesInAlphabeticalOrder(): void
    {
        // Write in reverse alpha order; discovery must sort by path (dir name)
        $this->writeManifest('Commerce', $this->validManifestData('commerce'));
        $this->writeManifest('Blog',     $this->validManifestData('blog'));
        $this->writeManifest('Auth',     $this->validManifestData('auth'));

        $discovery = new ModuleDiscovery($this->tempDir);
        $manifests = $discovery->discover();

        $this->assertCount(3, $manifests);
        // Sorted by full manifest path (Auth < Blog < Commerce)
        $this->assertSame('auth',    $manifests[0]->name);
        $this->assertSame('blog',    $manifests[1]->name);
        $this->assertSame('commerce', $manifests[2]->name);
    }

    public function testIgnoresDirectoriesWithoutManifest(): void
    {
        // Module dir with no module.json — should be invisible to discovery
        mkdir($this->tempDir . '/NoManifest', 0777, true);
        $this->writeManifest('Content', $this->validManifestData('content'));

        $discovery = new ModuleDiscovery($this->tempDir);
        $manifests = $discovery->discover();

        $this->assertCount(1, $manifests);
        $this->assertSame('content', $manifests[0]->name);
    }

    // -----------------------------------------------------------------------
    // Error cases
    // -----------------------------------------------------------------------

    public function testThrowsOnMalformedJson(): void
    {
        $dir = $this->tempDir . '/BadJson';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/module.json', '{not valid json}');

        $discovery = new ModuleDiscovery($this->tempDir);

        $this->expectException(InvalidManifestException::class);
        $discovery->discover();
    }

    public function testThrowsOnMissingRequiredField(): void
    {
        $data = $this->validManifestData('content');
        unset($data['module_class']);
        $this->writeManifest('Content', $data);

        $discovery = new ModuleDiscovery($this->tempDir);

        $this->expectException(InvalidManifestException::class);
        $discovery->discover();
    }

    public function testDiscoveryFromNonExistentBasePathReturnsEmpty(): void
    {
        $discovery = new ModuleDiscovery('/nonexistent/path/that/does/not/exist');
        $this->assertSame([], $discovery->discover());
    }
}
