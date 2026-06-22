<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Workers\WorkerExecutionContext;
use HSP\Core\Workers\WorkerStrategyInterface;

final class FakeWorkerStrategy implements WorkerStrategyInterface
{
    /** Sequence of return values for execute(); each call pops the first entry. */
    public array $results = [];

    /** @var WorkerExecutionContext[] */
    public array $receivedContexts = [];

    public ?string $thrownMessage = null;

    public function execute(WorkerExecutionContext $context): bool
    {
        $this->receivedContexts[] = $context;

        if ($this->thrownMessage !== null) {
            throw new \RuntimeException($this->thrownMessage);
        }

        return ! empty($this->results) ? array_shift($this->results) : false;
    }

    public function getQueueNames(): array
    {
        return ['content'];
    }
}
