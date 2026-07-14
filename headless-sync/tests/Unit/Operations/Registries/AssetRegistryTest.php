<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Registries;

use HSP\Core\Contracts\Operations\ConsoleAsset;
use HSP\Core\Operations\Registries\AssetRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AssetRegistry (Doc 12 §4; ADR-048/ADR-052; DECISION V (a) — no bundle).
 */
final class AssetRegistryTest extends TestCase
{
    private AssetRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new AssetRegistry();
    }

    public function test_registers_scripts_and_styles(): void
    {
        $this->registry->register(new ConsoleAsset('ops-poll', ConsoleAsset::TYPE_SCRIPT, 'resources/ops/poll.js', 'operations'));
        $this->registry->register(new ConsoleAsset('ops-css', ConsoleAsset::TYPE_STYLE, 'resources/ops/ops.css', 'operations'));

        self::assertCount(2, $this->registry->all());
    }

    public function test_for_page_filters_by_page_slug(): void
    {
        $this->registry->register(new ConsoleAsset('ops-poll', ConsoleAsset::TYPE_SCRIPT, 'a.js', 'operations'));
        $this->registry->register(new ConsoleAsset('play-poll', ConsoleAsset::TYPE_SCRIPT, 'b.js', 'playground'));

        $handles = array_map(
            static fn (ConsoleAsset $a): string => $a->handle,
            $this->registry->forPage('operations'),
        );
        self::assertSame(['ops-poll'], $handles);
    }

    public function test_invalid_type_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("must be 'script' or 'style'");
        $this->registry->register(new ConsoleAsset('bad', 'image', 'x.png', 'operations'));
    }

    public function test_duplicate_handle_registration_throws(): void
    {
        $this->registry->register(new ConsoleAsset('ops-poll', ConsoleAsset::TYPE_SCRIPT, 'a.js', 'operations'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('already registered');
        $this->registry->register(new ConsoleAsset('ops-poll', ConsoleAsset::TYPE_SCRIPT, 'b.js', 'operations'));
    }

    public function test_empty_handle_path_and_page_are_rejected(): void
    {
        $cases = [
            new ConsoleAsset('', ConsoleAsset::TYPE_SCRIPT, 'a.js', 'operations'),
            new ConsoleAsset('h', ConsoleAsset::TYPE_SCRIPT, '', 'operations'),
            new ConsoleAsset('h', ConsoleAsset::TYPE_SCRIPT, 'a.js', ''),
        ];

        foreach ($cases as $asset) {
            $threw = false;
            try {
                (new AssetRegistry())->register($asset);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            self::assertTrue($threw, 'Expected InvalidArgumentException for empty field.');
        }
    }
}
