<?php

declare(strict_types=1);

namespace Psr\Container;

/**
 * PSR-11 ContainerInterface stub.
 *
 * Included as a local stub so the container is PSR-11 compatible without
 * adding a composer require entry (P0-S1 scope: autoload only).
 * Replace with the real psr/container package when composer dependencies
 * are added in a later session.
 */
interface ContainerInterface
{
    public function get(string $id): mixed;
    public function has(string $id): bool;
}
