<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Handlers;

use HSP\Core\Contracts\EventInterface;

/**
 * Marker/contract for create/update handlers: reload WP state → extract → transform → persist.
 *
 * Authority: DECISION H (state-sync reload); DECISION 3 (three-op atomicity in adapter).
 */
interface ContentUpsertHandlerInterface
{
    public function handle(EventInterface $event): void;
}
