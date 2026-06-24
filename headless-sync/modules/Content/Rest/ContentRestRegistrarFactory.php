<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Rest;

use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\CategoryResource;
use HSP\Modules\Content\Resources\PageResource;
use HSP\Modules\Content\Resources\PostResource;

/**
 * Lazy factory for ContentRestRegistrar — defined and owned by the composition root
 * (ContentServiceProvider). Passed to ContentModule as an opaque callable so that
 * ContentModule holds no Container reference (ADR-012).
 *
 * Construction of ContentRestRegistrar (and the PG connection it pulls transitively
 * via the query providers) is deferred to first invocation, which occurs inside
 * WordPress's rest_api_init hook — not at module-load time (plugins_loaded:5).
 *
 * The query-provider and resource factories are injected by ContentServiceProvider;
 * this class never calls Container::get() or any service-locator. Each factory is a
 * zero-arg callable that returns the corresponding singleton from the container's
 * already-registered binding.
 */
final class ContentRestRegistrarFactory
{
    private ?ContentRestRegistrar $instance = null;

    /**
     * @param \Closure(): PageQueryProvider     $pageQueryProviderFactory
     * @param \Closure(): PostQueryProvider     $postQueryProviderFactory
     * @param \Closure(): CategoryQueryProvider $categoryQueryProviderFactory
     * @param \Closure(): PageResource          $pageResourceFactory
     * @param \Closure(): PostResource          $postResourceFactory
     * @param \Closure(): CategoryResource      $categoryResourceFactory
     */
    public function __construct(
        private readonly \Closure $pageQueryProviderFactory,
        private readonly \Closure $postQueryProviderFactory,
        private readonly \Closure $categoryQueryProviderFactory,
        private readonly \Closure $pageResourceFactory,
        private readonly \Closure $postResourceFactory,
        private readonly \Closure $categoryResourceFactory,
    ) {}

    /**
     * Returns the singleton ContentRestRegistrar, constructing it on first call.
     *
     * Each factory closure is called once; results are cached via singleton bindings
     * in the container (the closures delegate to $c->get() on the composition-root side).
     */
    public function __invoke(): ContentRestRegistrar
    {
        return $this->instance ??= new ContentRestRegistrar(
            ($this->pageQueryProviderFactory)(),
            ($this->postQueryProviderFactory)(),
            ($this->categoryQueryProviderFactory)(),
            ($this->pageResourceFactory)(),
            ($this->postResourceFactory)(),
            ($this->categoryResourceFactory)(),
        );
    }
}
