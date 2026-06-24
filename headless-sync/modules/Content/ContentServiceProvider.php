<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Events\EventRegistry;
use HSP\Modules\Content\Adapters\CategoryAdapter;
use HSP\Modules\Content\Adapters\PageAdapter;
use HSP\Modules\Content\Adapters\PostAdapter;
use HSP\Modules\Content\Events\ContentEventTypes;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Handlers\CategoryTombstoneHandler;
use HSP\Modules\Content\Handlers\CategoryUpsertHandler;
use HSP\Modules\Content\Handlers\PageTombstoneHandler;
use HSP\Modules\Content\Handlers\PageUpsertHandler;
use HSP\Modules\Content\Handlers\PostTombstoneHandler;
use HSP\Modules\Content\Handlers\PostUpsertHandler;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\CategoryResource;
use HSP\Modules\Content\Resources\PageResource;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Rest\ContentRestRegistrar;
use HSP\Modules\Content\Rest\ContentRestRegistrarFactory;
use HSP\Modules\Content\Subscribers\ContentSubscriber;
use HSP\Modules\Content\Subscribers\ContentSubscriberRegistrar;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;

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
        // ContentSubscriberRegistrar follows the same typed-factory pattern so that
        // EventRegistry wiring happens during ContentModule::register() without a
        // Container reference in ContentModule.
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
                new ContentSubscriberRegistrar(
                    fn () => $c->get(EventRegistry::class),
                    fn () => $c->get(ContentSubscriber::class),
                ),
            )
        );

        // -------------------------------------------------------------------------
        // Adapters (P1A-S4 implementations — injected into handlers)
        // -------------------------------------------------------------------------

        $container->singleton(PageAdapter::class, fn (Container $c) =>
            new PageAdapter($c->get(DatabaseConnectionInterface::class))
        );

        $container->singleton(PostAdapter::class, fn (Container $c) =>
            new PostAdapter($c->get(DatabaseConnectionInterface::class))
        );

        $container->singleton(CategoryAdapter::class, fn (Container $c) =>
            new CategoryAdapter($c->get(DatabaseConnectionInterface::class))
        );

        // -------------------------------------------------------------------------
        // WpContentLoader — live WP implementation wired here; fake injected in tests.
        // -------------------------------------------------------------------------

        $container->singleton(WpContentLoader::class, fn () => new WpContentLoaderImpl());

        // -------------------------------------------------------------------------
        // Validators, extractors, transformers (stateless; safe to share as singletons)
        // -------------------------------------------------------------------------

        $container->singleton(PageValidator::class,    fn () => new PageValidator());
        $container->singleton(PostValidator::class,    fn () => new PostValidator());
        $container->singleton(CategoryValidator::class, fn () => new CategoryValidator());

        $container->singleton(PageExtractor::class,    fn (Container $c) => new PageExtractor($c->get(PageValidator::class)));
        $container->singleton(PostExtractor::class,    fn (Container $c) => new PostExtractor($c->get(PostValidator::class)));
        $container->singleton(CategoryExtractor::class, fn (Container $c) => new CategoryExtractor($c->get(CategoryValidator::class)));

        $container->singleton(PageTransformer::class,     fn () => new PageTransformer());
        $container->singleton(PostTransformer::class,     fn () => new PostTransformer());
        $container->singleton(CategoryTransformer::class, fn () => new CategoryTransformer());

        // -------------------------------------------------------------------------
        // Upsert handlers
        // -------------------------------------------------------------------------

        $container->singleton(PageUpsertHandler::class, fn (Container $c) =>
            new PageUpsertHandler(
                $c->get(WpContentLoader::class),
                $c->get(PageExtractor::class),
                $c->get(PageTransformer::class),
                $c->get(PageAdapter::class),
            )
        );

        $container->singleton(PostUpsertHandler::class, fn (Container $c) =>
            new PostUpsertHandler(
                $c->get(WpContentLoader::class),
                $c->get(PostExtractor::class),
                $c->get(PostTransformer::class),
                $c->get(PostAdapter::class),
            )
        );

        $container->singleton(CategoryUpsertHandler::class, fn (Container $c) =>
            new CategoryUpsertHandler(
                $c->get(WpContentLoader::class),
                $c->get(CategoryExtractor::class),
                $c->get(CategoryTransformer::class),
                $c->get(CategoryAdapter::class),
            )
        );

        // -------------------------------------------------------------------------
        // Tombstone handlers
        // -------------------------------------------------------------------------

        $container->singleton(PageTombstoneHandler::class,     fn (Container $c) =>
            new PageTombstoneHandler($c->get(PageAdapter::class))
        );

        $container->singleton(PostTombstoneHandler::class,     fn (Container $c) =>
            new PostTombstoneHandler($c->get(PostAdapter::class))
        );

        $container->singleton(CategoryTombstoneHandler::class, fn (Container $c) =>
            new CategoryTombstoneHandler($c->get(CategoryAdapter::class))
        );

        // -------------------------------------------------------------------------
        // ContentSubscriber — maps all 9 OPEN-1 event types to their typed handlers.
        // ContentSubscriberRegistrar (injected into ContentModule) calls register()
        // during ContentModule::register() to wire this into EventRegistry.
        // -------------------------------------------------------------------------

        $container->singleton(ContentSubscriber::class, fn (Container $c) =>
            new ContentSubscriber([
                ContentEventTypes::PAGE_CREATED     => $c->get(PageUpsertHandler::class),
                ContentEventTypes::PAGE_UPDATED     => $c->get(PageUpsertHandler::class),
                ContentEventTypes::PAGE_DELETED     => $c->get(PageTombstoneHandler::class),
                ContentEventTypes::POST_CREATED     => $c->get(PostUpsertHandler::class),
                ContentEventTypes::POST_UPDATED     => $c->get(PostUpsertHandler::class),
                ContentEventTypes::POST_DELETED     => $c->get(PostTombstoneHandler::class),
                ContentEventTypes::CATEGORY_CREATED => $c->get(CategoryUpsertHandler::class),
                ContentEventTypes::CATEGORY_UPDATED => $c->get(CategoryUpsertHandler::class),
                ContentEventTypes::CATEGORY_DELETED => $c->get(CategoryTombstoneHandler::class),
            ])
        );
    }
}
