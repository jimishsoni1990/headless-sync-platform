<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * No-op heartbeat publisher used when no monitoring backend is configured.
 *
 * OPS-S1 will replace this with a concrete implementation that writes
 * heartbeat records to a fast store or system.* table (Doc 8 §15).
 * This null implementation keeps the DI container valid during P0-S6 and
 * avoids a hard dependency on the monitoring infrastructure before it exists.
 */
final class NullHeartbeatPublisher implements HeartbeatPublisherInterface
{
    public function publish(HeartbeatRecord $record): void
    {
        // Intentional no-op — OPS-S1 provides the real publisher.
    }
}
