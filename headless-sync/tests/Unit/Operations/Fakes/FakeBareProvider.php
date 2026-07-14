<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\Operations\OperationsProviderInterface;

/**
 * A provider that implements ONLY the base interface — proves the Refresh Coordinator
 * rejects providers without a known data-provider interface.
 */
final class FakeBareProvider implements OperationsProviderInterface
{
    public function __construct(private readonly string $key = 'bare') {}

    public function key(): string { return $this->key; }
}
