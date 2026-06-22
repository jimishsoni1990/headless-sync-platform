<?php

declare(strict_types=1);

namespace HSP\Core\Workers;

/**
 * Publishes worker heartbeat records.
 *
 * Authority: Doc 8 §15 — heartbeat publication is a worker infrastructure concern.
 * OPS-S1 will provide a concrete implementation backed by system.* tables or a
 * fast store. During P0-S6 the engine requires this interface for testability.
 *
 * DECISION E: implementations must not introduce a new raw pg_* wrapper.
 */
interface HeartbeatPublisherInterface
{
    public function publish(HeartbeatRecord $record): void;
}
