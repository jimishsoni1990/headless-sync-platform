<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Workers;

use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\QueueProviderInterface;

final class FakeQueueProvider implements QueueProviderInterface
{
    /** @var array<string, mixed>|null Next value returned by claim() */
    public ?array $claimResult = null;

    public array $completeCalls  = [];
    public array $releaseCalls   = [];
    public array $deadLetterCalls = [];
    public array $enqueueCalls   = [];
    public int   $requeueCount   = 0;

    /** If set, complete() returns this instead of true. */
    public bool $completeReturns = true;
    public bool $releaseReturns  = true;
    public bool $deadLetterReturns = true;

    public function enqueue(EventInterface $event, string $queueName): string
    {
        $this->enqueueCalls[] = ['event' => $event, 'queue' => $queueName];
        return 'fake-job-id';
    }

    public function claim(string $queueName, string $workerId): ?array
    {
        return $this->claimResult;
    }

    public function complete(string $jobId, string $workerId): bool
    {
        $this->completeCalls[] = ['jobId' => $jobId, 'workerId' => $workerId];
        return $this->completeReturns;
    }

    public function release(string $jobId, string $workerId, int $delaySeconds = 0): bool
    {
        $this->releaseCalls[] = [
            'jobId'        => $jobId,
            'workerId'     => $workerId,
            'delaySeconds' => $delaySeconds,
        ];
        return $this->releaseReturns;
    }

    public function deadLetter(string $jobId, string $workerId, array $failureContext): bool
    {
        $this->deadLetterCalls[] = [
            'jobId'          => $jobId,
            'workerId'       => $workerId,
            'failureContext' => $failureContext,
        ];
        return $this->deadLetterReturns;
    }

    public function requeueTimedOut(string $queueName): int
    {
        $this->requeueCount++;
        return 0;
    }
}
