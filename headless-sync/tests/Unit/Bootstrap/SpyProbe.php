<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Bootstrap;

/**
 * Duck-typed stand-in for OnboardingConnectionProbe (which is final). The activation guard calls
 * ->isReachable() on whatever the container returns; this records the call so the guard test can
 * prove the probe is NOT consulted when the connection-free constants gate already fails.
 */
final class SpyProbe
{
    public int $reachableCalls = 0;

    public function __construct(private readonly bool $isReachable) {}

    public function isReachable(): bool
    {
        $this->reachableCalls++;

        return $this->isReachable;
    }
}
