<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Rest;

/**
 * Lazily builds the REST registrar, memoized per request.
 *
 * The registrar's construction reaches the delivery PostgreSQL connection through the query
 * provider. `rest_api_init` fires on EVERY REST request to the site — `wp/v2` and the block
 * editor included — so building it eagerly at module boot would drag Commerce's graph into
 * requests that have nothing to do with it. That is the FLAG-P1AS6-2 Gap C shape, and the
 * reason LAZYPG-S1 exists.
 */
final class CommerceRestRegistrarFactory
{
    private ?CommerceRestRegistrar $instance = null;

    /** @param \Closure(): CommerceRestRegistrar $factory */
    public function __construct(private readonly \Closure $factory)
    {
    }

    public function __invoke(): CommerceRestRegistrar
    {
        return $this->instance ??= ($this->factory)();
    }
}
