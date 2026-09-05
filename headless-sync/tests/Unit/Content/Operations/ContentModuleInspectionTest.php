<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Operations;

use HSP\Core\Contracts\Operations\ModuleInspectionProviderInterface;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Operations\ContentMetricsProvider;
use HSP\Modules\Content\Operations\ContentModuleInspection;
use PHPUnit\Framework\TestCase;

final class ContentModuleInspectionTest extends TestCase
{
    public function test_implements_core_contract(): void
    {
        self::assertInstanceOf(
            ModuleInspectionProviderInterface::class,
            new ContentModuleInspection('1.0.0'),
        );
    }

    public function test_describes_content_module_from_static_facts(): void
    {
        $inspection = (new ContentModuleInspection('1.2.3'))->inspect();

        self::assertSame('content', $inspection->name);
        self::assertSame('1.2.3', $inspection->version);
        self::assertSame(array_values(ContentEventTypes::ALL), $inspection->eventTypes);
        self::assertCount(15, $inspection->eventTypes);
        self::assertContains('/posts/{slug}', $inspection->endpoints);
        self::assertContains('PostTransformer', $inspection->transformers);
        self::assertContains('PostAdapter', $inspection->adapters);
        // Provider keys the module contributes to the console.
        self::assertContains(ContentMetricsProvider::KEY, $inspection->providerKeys);
        self::assertContains(ContentEndpointProvider::KEY, $inspection->providerKeys);
        // No operational actions at OPSC-S2.
        self::assertSame([], $inspection->actionKeys);
    }
}
