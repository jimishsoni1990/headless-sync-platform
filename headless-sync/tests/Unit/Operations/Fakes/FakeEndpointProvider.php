<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Fakes;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointProviderInterface;

/**
 * In-memory EndpointProvider fake (read-only hsp/v1 metadata — ADR-050). No HTTP types.
 */
final class FakeEndpointProvider implements EndpointProviderInterface
{
    public int $calls = 0;

    public function __construct(private readonly string $key = 'endpoints') {}

    public function key(): string { return $this->key; }

    public function endpoints(): array
    {
        $this->calls++;

        return [new EndpointDescriptor('GET', '/posts', 'hsp/v1', 'Content', 'List posts')];
    }
}
