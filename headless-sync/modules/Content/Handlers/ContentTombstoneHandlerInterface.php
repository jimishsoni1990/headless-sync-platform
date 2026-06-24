<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Handlers;

use HSP\Core\Contracts\EventInterface;

/**
 * Marker/contract for soft-delete handlers: consume event envelope only → adapter tombstone.
 *
 * Authority: DECISION I (tombstone path; no WP reload; no extract/transform).
 */
interface ContentTombstoneHandlerInterface
{
    public function handle(EventInterface $event): void;
}
