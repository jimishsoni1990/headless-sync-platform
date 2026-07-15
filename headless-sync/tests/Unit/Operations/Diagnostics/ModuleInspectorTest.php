<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Diagnostics;

use HSP\Core\Contracts\Operations\ModuleInspection;
use HSP\Core\Operations\Diagnostics\ModuleInspector;
use HSP\Tests\Unit\Operations\Fakes\FakeModuleInspectionProvider;
use PHPUnit\Framework\TestCase;

final class ModuleInspectorTest extends TestCase
{
    public function test_all_returns_descriptors_sorted_by_module_name(): void
    {
        $inspector = new ModuleInspector([
            new FakeModuleInspectionProvider('woocommerce'),
            new FakeModuleInspectionProvider('content'),
        ]);

        $all = $inspector->all();

        self::assertContainsOnlyInstancesOf(ModuleInspection::class, $all);
        self::assertSame(['content', 'woocommerce'], array_map(static fn ($i) => $i->name, $all));
    }

    public function test_for_module_returns_named_descriptor_or_null(): void
    {
        $inspector = new ModuleInspector([new FakeModuleInspectionProvider('content', '2.1.0')]);

        self::assertSame('2.1.0', $inspector->forModule('content')?->version);
        self::assertNull($inspector->forModule('absent'));
    }

    public function test_add_after_construction_works(): void
    {
        $inspector = new ModuleInspector();
        $inspector->add(new FakeModuleInspectionProvider('content'));

        self::assertNotNull($inspector->forModule('content'));
    }

    public function test_duplicate_module_name_is_a_composition_root_error(): void
    {
        $inspector = new ModuleInspector([new FakeModuleInspectionProvider('content')]);

        $this->expectException(\LogicException::class);
        $inspector->add(new FakeModuleInspectionProvider('content'));
    }
}
