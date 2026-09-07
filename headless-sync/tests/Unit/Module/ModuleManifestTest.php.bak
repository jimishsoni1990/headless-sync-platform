<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Module\ModuleManifest;
use HSP\Core\Module\Exception\InvalidManifestException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ModuleManifest::fromArray().
 *
 * Verifies: valid parse, missing fields, empty fields, invalid requires type.
 */
final class ModuleManifestTest extends TestCase
{
    private function validData(): array
    {
        return [
            'name'           => 'content',
            'version'        => '1.0.0',
            'module_class'   => 'HSP\\Modules\\Content\\ContentModule',
            'schema_version' => '1.0.0',
            'requires'       => [],
        ];
    }

    public function testParsesValidManifest(): void
    {
        $manifest = ModuleManifest::fromArray($this->validData(), '/fake/module.json');

        $this->assertSame('content', $manifest->name);
        $this->assertSame('1.0.0', $manifest->version);
        $this->assertSame('HSP\\Modules\\Content\\ContentModule', $manifest->moduleClass);
        $this->assertSame('1.0.0', $manifest->schemaVersion);
        $this->assertSame([], $manifest->requires);
        $this->assertSame('/fake/module.json', $manifest->manifestPath);
    }

    public function testParsesRequiresArrayCorrectly(): void
    {
        $data              = $this->validData();
        $data['requires']  = ['core', 'other-module'];

        $manifest = ModuleManifest::fromArray($data, '/fake/module.json');

        $this->assertSame(['core', 'other-module'], $manifest->requires);
    }

    public function testDefaultsRequiresToEmptyArrayWhenAbsent(): void
    {
        $data = $this->validData();
        unset($data['requires']);

        $manifest = ModuleManifest::fromArray($data, '/fake/module.json');
        $this->assertSame([], $manifest->requires);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingFieldProvider')]
    public function testThrowsWhenRequiredFieldIsMissing(string $field): void
    {
        $data = $this->validData();
        unset($data[$field]);

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage($field);

        ModuleManifest::fromArray($data, '/fake/module.json');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('missingFieldProvider')]
    public function testThrowsWhenRequiredFieldIsEmpty(string $field): void
    {
        $data         = $this->validData();
        $data[$field] = '';

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage($field);

        ModuleManifest::fromArray($data, '/fake/module.json');
    }

    public static function missingFieldProvider(): array
    {
        return [
            ['name'],
            ['version'],
            ['module_class'],
            ['schema_version'],
        ];
    }

    public function testThrowsWhenRequiresIsNotAnArray(): void
    {
        $data              = $this->validData();
        $data['requires']  = 'not-an-array';

        $this->expectException(InvalidManifestException::class);
        ModuleManifest::fromArray($data, '/fake/module.json');
    }

    public function testManifestPathIsIncludedInExceptionMessage(): void
    {
        $data = $this->validData();
        unset($data['name']);

        try {
            ModuleManifest::fromArray($data, '/path/to/my/module.json');
            $this->fail('Expected InvalidManifestException');
        } catch (InvalidManifestException $e) {
            $this->assertStringContainsString('/path/to/my/module.json', $e->getMessage());
        }
    }
}
