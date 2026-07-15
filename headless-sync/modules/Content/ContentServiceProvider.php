<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Contracts\ReplayEmitterInterface;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Contracts\Operations\WidgetRegistryInterface;
use HSP\Core\Operations\Admin\AdminPageController;
use HSP\Core\Operations\Diagnostics\ModuleInspector;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Core\Replay\ReplayService;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Operations\ContentMetricsProvider;
use HSP\Modules\Content\Operations\ContentModuleInspection;
use HSP\Modules\Content\Reconciliation\WpReconciliationSource;
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
use HSP\Modules\Content\Replay\ContentReplayEmitter;
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
        // Replay (DECISION T) — the Content module owns the ReplayEmitterInterface impl
        // (reads current WP state, decides .updated/.deleted, emits via the outbox path).
        // ReplayService is core orchestration; it reads system.events for date-range
        // discovery via the existing delivery DatabaseConnectionInterface handle (no
        // fifth handle — DECISION L Ruling 0) and delegates each emit to the emitter.
        // -------------------------------------------------------------------------

        $container->singleton(ReplayEmitterInterface::class, fn (Container $c) =>
            new ContentReplayEmitter(
                $c->get(EventProviderInterface::class),
                $c->get(WpContentLoader::class),
            )
        );

        $container->singleton(ReplayService::class, fn (Container $c) =>
            new ReplayService(
                $c->get(DatabaseConnectionInterface::class),
                [$c->get(ReplayEmitterInterface::class)],
            )
        );

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
        // Reconciliation (DECISION U) — the Content module owns the WP-side detection
        // source (WP reads + checksum recompute + pending-outbox). ReconciliationService
        // (core) is bound in WorkerServiceProvider (it needs config page-size); here we
        // provide only the module-owned source, mirroring the ReplayEmitterInterface split.
        // -------------------------------------------------------------------------

        $container->singleton(WpReconciliationSourceInterface::class, fn (Container $c) =>
            new WpReconciliationSource(
                $c->get(WpContentLoader::class),
                $c->get(PageExtractor::class),
                $c->get(PostExtractor::class),
                $c->get(CategoryExtractor::class),
                $c->get(PageTransformer::class),
                $c->get(PostTransformer::class),
                $c->get(CategoryTransformer::class),
            )
        );

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

        // -------------------------------------------------------------------------
        // Operations Console — module-provided diagnostics/metrics + self-description
        // (OPSC-S2). All implement core-owned contracts under core/Contracts/Operations/
        // (Rule 5 — the module depends on core contracts only). Read-only; the metrics
        // provider reads content.* on the delivery DatabaseConnectionInterface handle
        // (DECISION V (g) — no fifth handle, no new pg_* wrapper). Registered with the
        // RefreshCoordinator / ModuleInspector in boot() (after all providers exist).
        // -------------------------------------------------------------------------

        $container->singleton(ContentMetricsProvider::class, fn (Container $c) =>
            new ContentMetricsProvider($c->get(DatabaseConnectionInterface::class))
        );

        $container->singleton(ContentEndpointProvider::class, fn () =>
            new ContentEndpointProvider()
        );

        $container->singleton(ContentModuleInspection::class, fn () =>
            new ContentModuleInspection(self::moduleVersion())
        );
    }

    /**
     * Register the Content module's Operations providers with the console coordinator and
     * inspector (composition-root wiring — runs after all register() calls, so the core
     * OperationsServiceProvider bindings already exist).
     */
    public function boot(object $container): void
    {
        assert($container instanceof Container);

        /** @var RefreshCoordinator $coordinator */
        $coordinator = $container->get(RefreshCoordinator::class);
        $coordinator->addProvider($container->get(ContentMetricsProvider::class));
        $coordinator->addProvider($container->get(ContentEndpointProvider::class));

        /** @var ModuleInspector $inspector */
        $inspector = $container->get(ModuleInspector::class);
        $inspector->add($container->get(ContentModuleInspection::class));

        // Module-provided dashboard widget over the module's own metrics snapshot (OPSC-S3).
        // The module names only its provider key and target page slug (both core-owned
        // contracts — Rule 5); the widget reads its snapshot through OperationsService and
        // never polls infrastructure (Doc 12 §7/§8).
        /** @var WidgetRegistryInterface $widgets */
        $widgets = $container->get(WidgetRegistryInterface::class);
        $widgets->register(new ConsoleWidget(
            'content.metrics',
            'Content Projections',
            AdminPageController::PAGE_OPERATIONS,
            ContentMetricsProvider::KEY,
            50,
        ));
    }

    /**
     * Content module version, read from module.json (authoritative source — avoids drift
     * between the descriptor and the manifest).
     */
    private static function moduleVersion(): string
    {
        $manifest = __DIR__ . '/module.json';
        if (is_readable($manifest)) {
            $data = json_decode((string) file_get_contents($manifest), true);
            if (is_array($data) && isset($data['version']) && is_string($data['version'])) {
                return $data['version'];
            }
        }

        return 'unknown';
    }
}
