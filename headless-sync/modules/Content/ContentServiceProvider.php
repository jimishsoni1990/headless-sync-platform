<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\CategoryResource;
use HSP\Modules\Content\Resources\PageResource;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Rest\ContentRestRegistrar;
use HSP\Modules\Content\Rest\ContentRestRegistrarFactory;

/**
 * Registers all Content module bindings in the DI container.
 *
 * Explicit registration only — no reflection-based autowiring (ADR-012,
 * IMPLEMENTATION_PLAN.md §4 "explicit registration only").
 *
 * The ContentModule binding is the key entry-point: ModuleLoader resolves it
 * through the container so that HookWiring and EventProvider are injected
 * via constructor injection (ADR-012), not new $class().
 *
 * Authority:
 *   ADR-012 — constructor injection only; service-locator prohibited in business logic.
 *   DECISION E v1.6 — DatabaseConnectionInterface for all PG queries.
 *   FLAG-P1AS6-2 — Gap B fix: ContentModule must be container-resolved.
 *   Doc 9 §7 — REST wiring belongs at module boot boundary.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(object $container): void
    {
        assert($container instanceof Container);

        // Query providers — each requires a DatabaseConnectionInterface (PG delivery).
        $container->singleton(PageQueryProvider::class, fn (Container $c) =>
            new PageQueryProvider($c->get(DatabaseConnectionInterface::class))
        );

        $container->singleton(PostQueryProvider::class, fn (Container $c) =>
            new PostQueryProvider($c->get(DatabaseConnectionInterface::class))
        );

        $container->singleton(CategoryQueryProvider::class, fn (Container $c) =>
            new CategoryQueryProvider($c->get(DatabaseConnectionInterface::class))
        );

        // Resources — no dependencies; singletons for efficiency.
        $container->singleton(PageResource::class, fn () => new PageResource());
        $container->singleton(PostResource::class, fn () => new PostResource());
        $container->singleton(CategoryResource::class, fn () => new CategoryResource());

        // REST registrar — depends on all three query providers and resources.
        $container->singleton(ContentRestRegistrar::class, fn (Container $c) =>
            new ContentRestRegistrar(
                $c->get(PageQueryProvider::class),
                $c->get(PostQueryProvider::class),
                $c->get(CategoryQueryProvider::class),
                $c->get(PageResource::class),
                $c->get(PostResource::class),
                $c->get(CategoryResource::class),
            )
        );

        // HookWiring — depends on EventProviderInterface (content-scoped).
        $container->singleton(HookWiring::class, fn (Container $c) =>
            new HookWiring($c->get(EventProviderInterface::class))
        );

        // Content-scoped EventProviderInterface — backed by EventProvider.
        // Registered under the interface so ContentModule receives an
        // EventProviderInterface through its constructor (ADR-012).
        $container->singleton(EventProviderInterface::class, fn (Container $c) =>
            new EventProvider($c->get(OutboxWriterInterface::class))
        );

        // ContentModule — resolved through the container so its constructor
        // dependencies are injected (FLAG-P1AS6-2 Gap B fix).
        //
        // ContentRestRegistrarFactory is passed instead of a bare \Closure so that
        // ContentModule holds no Container reference (ADR-012 / FLAG-P1AS6A-5).
        // Each per-dep factory closure delegates to the already-registered container
        // singleton at call-time; construction is deferred to rest_api_init so no
        // PG connection is opened at plugins_loaded:5 module-load time.
        $container->singleton(ContentModule::class, fn (Container $c) =>
            new ContentModule(
                $c->get(HookWiring::class),
                $c->get(EventProviderInterface::class),
                new ContentRestRegistrarFactory(
                    fn () => $c->get(PageQueryProvider::class),
                    fn () => $c->get(PostQueryProvider::class),
                    fn () => $c->get(CategoryQueryProvider::class),
                    fn () => $c->get(PageResource::class),
                    fn () => $c->get(PostResource::class),
                    fn () => $c->get(CategoryResource::class),
                ),
            )
        );
    }
}
