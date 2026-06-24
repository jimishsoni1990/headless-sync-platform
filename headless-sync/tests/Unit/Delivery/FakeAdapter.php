<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Delivery;

use HSP\Core\Contracts\AdapterInterface;
use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\EventInterface;

final class FakeAdapter implements AdapterInterface
{
    public function __construct(private readonly string $canonicalModelClass) {}

    public function persist(CanonicalModelInterface $model, EventInterface $event): void {}

    public function tombstone(string $aggregateType, string $aggregateId, EventInterface $event): void {}

    public function bulkPersist(array $models): void {}

    public function getCanonicalModelClass(): string
    {
        return $this->canonicalModelClass;
    }
}
