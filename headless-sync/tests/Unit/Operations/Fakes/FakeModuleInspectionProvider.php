<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\Operations\ModuleInspection;
use HSP\Core\Contracts\Operations\ModuleInspectionProviderInterface;

/**
 * In-memory ModuleInspectionProviderInterface fake — descriptive metadata only, no queries.
 */
final class FakeModuleInspectionProvider implements ModuleInspectionProviderInterface
{
    public function __construct(
        private readonly string $name,
        private readonly string $version = '1.0.0',
    ) {}

    public function inspect(): ModuleInspection
    {
        return new ModuleInspection(
            name: $this->name,
            version: $this->version,
            eventTypes: [$this->name . '.thing.created'],
        );
    }
}
