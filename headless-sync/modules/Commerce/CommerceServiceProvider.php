<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

use HSP\Core\Container\Container;
use HSP\Core\Container\ServiceProvider;
use HSP\Core\Contracts\ModuleAvailabilityInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Contracts\PartitionRouterInterface;
use HSP\Core\Contracts\ProjectionDescriptor;
use HSP\Core\Contracts\ProjectionRegistryInterface;
use HSP\Core\Contracts\ReconciliationSourceRegistryInterface;
use HSP\Core\Contracts\ReplayEmitterRegistryInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Events\EventRegistry;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Handlers\ProductTombstoneHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Migrations\CreateCommerceEntityTaxonomiesMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceProductsMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceTaxonomiesMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceSchemaMigration;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Queries\TermQueryProvider;
use HSP\Modules\Commerce\Reconciliation\WpCommerceReconciliationSource;
use HSP\Modules\Commerce\Replay\CommerceReplayEmitter;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrar;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrarFactory;
use HSP\Modules\Commerce\Subscribers\CommerceSubscriber;
use HSP\Modules\Commerce\Subscribers\CommerceSubscriberRegistrar;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\TermValidator;

/**
 * Registers the Commerce module's bindings — reached generically, never imported by core.
 *
 * The composition root finds this class through `module.json`'s `service_provider` key
 * (DECISION AG AG-1). ContainerBuilder contains no reference to Commerce at all, which is the
 * property P2-S1 built and this module is the first real proof of.
 *
 * AVAILABILITY (AG-12): this provider declares itself unavailable when WooCommerce is absent,
 * and an unavailable module contributes NOTHING — no bindings, no hooks, no registry entries,
 * no endpoints, no backfill counts. Content keeps working exactly as before, and nothing about
 * HSP requires WooCommerce to be installed.
 *
 * BINDING KEYS: every cross-module contract is registered into a core-owned REGISTRY rather
 * than bound under a shared interface key. `Container::singleton()` is last-writer-wins with
 * no error, so binding `ReplayEmitterInterface` here would silently delete Content's emitter
 * (AG-2). That is why the concrete classes are bound and the registries are populated in
 * boot().
 */
final class CommerceServiceProvider extends ServiceProvider implements ModuleAvailabilityInterface
{
    /**
     * Commerce is available only when WooCommerce is loaded.
     *
     * Cheap and side-effect free by contract — it runs during composition on every request,
     * before any binding exists.
     *
     * `WooCommerce` is the plugin's main class and is the single signal used, deliberately.
     * Probing for a helper function as well (`wc_get_product`) would conflate "WooCommerce is
     * installed" with "some function of that name exists", which is exactly the kind of
     * incidental coupling that makes a test stub silently flip a module into existence.
     * `false` for the autoload argument keeps the probe from triggering an autoloader on a
     * site that does not have WooCommerce at all.
     */
    public function isAvailable(): bool
    {
        return class_exists(\WooCommerce::class, false);
    }

    public function register(object $container): void
    {
        assert($container instanceof Container);

        // --- Source boundary -------------------------------------------------
        $container->singleton(WpCommerceLoader::class, fn () => new WpCommerceLoaderImpl());

        // --- Capture ---------------------------------------------------------
        // Bound under the CONCRETE class, not EventProviderInterface: that interface is a
        // single container key and Content already owns it (AG-2).
        $container->singleton(CommerceEventProvider::class, fn (Container $c) =>
            new CommerceEventProvider($c->get(OutboxWriterInterface::class)));

        $container->singleton(HookWiring::class, fn (Container $c) =>
            new HookWiring($c->get(CommerceEventProvider::class)));

        // --- Pipeline --------------------------------------------------------
        $container->singleton(ProductValidator::class, fn () => new ProductValidator());
        $container->singleton(ProductExtractor::class, fn (Container $c) =>
            new ProductExtractor($c->get(ProductValidator::class)));
        $container->singleton(ProductTransformer::class, fn () => new ProductTransformer());

        $container->singleton(TermValidator::class, fn () => new TermValidator());
        $container->singleton(TermExtractor::class, fn (Container $c) =>
            new TermExtractor($c->get(TermValidator::class)));
        $container->singleton(TermTransformer::class, fn () => new TermTransformer());
        $container->singleton(TermAdapter::class, fn (Container $c) =>
            new TermAdapter($c->get(DatabaseConnectionInterface::class)));

        $container->singleton(TermUpsertHandler::class, fn (Container $c) =>
            new TermUpsertHandler(
                $c->get(WpCommerceLoader::class),
                $c->get(TermExtractor::class),
                $c->get(TermTransformer::class),
                $c->get(TermAdapter::class),
            ));

        $container->singleton(TermTombstoneHandler::class, fn (Container $c) =>
            new TermTombstoneHandler($c->get(TermAdapter::class)));
        $container->singleton(ProductAdapter::class, fn (Container $c) =>
            new ProductAdapter($c->get(DatabaseConnectionInterface::class)));

        $container->singleton(ProductUpsertHandler::class, fn (Container $c) =>
            new ProductUpsertHandler(
                $c->get(WpCommerceLoader::class),
                $c->get(ProductExtractor::class),
                $c->get(ProductTransformer::class),
                $c->get(ProductAdapter::class),
            ));

        $container->singleton(ProductTombstoneHandler::class, fn (Container $c) =>
            new ProductTombstoneHandler($c->get(ProductAdapter::class)));

        $container->singleton(CommerceSubscriber::class, fn (Container $c) =>
            new CommerceSubscriber(
                $c->get(ProductUpsertHandler::class),
                $c->get(ProductTombstoneHandler::class),
                $c->get(TermUpsertHandler::class),
                $c->get(TermTombstoneHandler::class),
            ));

        $container->singleton(CommerceSubscriberRegistrar::class, fn (Container $c) =>
            new CommerceSubscriberRegistrar(
                static fn (): EventRegistry => $c->get(EventRegistry::class),
                static fn (): CommerceSubscriber => $c->get(CommerceSubscriber::class),
            ));

        // --- Delivery --------------------------------------------------------
        $container->singleton(ProductQueryProvider::class, fn (Container $c) =>
            new ProductQueryProvider($c->get(DatabaseConnectionInterface::class)));
        $container->singleton(ProductResource::class, fn () => new ProductResource());
        $container->singleton(TermResource::class, fn () => new TermResource());

        // Parameterised by taxonomy: one class, one binding per taxonomy (P1B-S3 precedent).
        // P2-S4 adds a pa_* binding here rather than another class.
        $container->singleton('commerce.category_query_provider', fn (Container $c) =>
            new TermQueryProvider(
                $c->get(DatabaseConnectionInterface::class),
                CommerceTaxonomies::PRODUCT_CAT,
            ));

        $container->singleton(CommerceRestRegistrar::class, fn (Container $c) =>
            new CommerceRestRegistrar(
                $c->get(ProductQueryProvider::class),
                $c->get(ProductResource::class),
                $c->get('commerce.category_query_provider'),
                $c->get(TermResource::class),
            ));

        // --- Repair ----------------------------------------------------------
        $container->singleton(CommerceReplayEmitter::class, fn (Container $c) =>
            new CommerceReplayEmitter(
                $c->get(CommerceEventProvider::class),
                $c->get(WpCommerceLoader::class),
            ));

        $container->singleton(WpCommerceReconciliationSource::class, fn (Container $c) =>
            new WpCommerceReconciliationSource(
                $c->get(WpCommerceLoader::class),
                $c->get(ProductExtractor::class),
                $c->get(ProductTransformer::class),
                $c->get(TermExtractor::class),
                $c->get(TermTransformer::class),
            ));

        // --- Operations ------------------------------------------------------
        $container->singleton(CommerceEndpointProvider::class, fn () => new CommerceEndpointProvider());

        // --- Module ----------------------------------------------------------
        $container->singleton(CommerceModule::class, fn (Container $c) =>
            new CommerceModule(
                $c->get(HookWiring::class),
                new CommerceRestRegistrarFactory(
                    static fn (): CommerceRestRegistrar => $c->get(CommerceRestRegistrar::class),
                ),
                $c->get(CommerceSubscriberRegistrar::class),
                // Migrations resolve LAZILY: building them opens the libpq DDL link, which
                // throws on an unconfigured site, and module construction must never fatal
                // (ADR-054 Principle 8 / FLAG-P1AS6A-5).
                static function () use ($c): array {
                    $conn = $c->get('migration.connection.pgsql');

                    return [
                        new CreateCommerceSchemaMigration($conn),
                        new CreateCommerceProductsMigration($conn),
                        new CreateCommerceTaxonomiesMigration($conn),
                        new CreateCommerceEntityTaxonomiesMigration($conn),
                    ];
                },
            ));
    }

    public function boot(object $container): void
    {
        assert($container instanceof Container);

        // Domain → partition routing (AG-4). `commerce` has existed in the queue whitelist
        // since P0-S5 with nothing able to put a job in it; this is what finally does.
        /** @var PartitionRouterInterface $router */
        $router = $container->get(PartitionRouterInterface::class);
        $router->register('commerce', 'commerce');

        /** @var ReplayEmitterRegistryInterface $emitters */
        $emitters = $container->get(ReplayEmitterRegistryInterface::class);
        $emitters->register($container->get(CommerceReplayEmitter::class));

        /** @var ReconciliationSourceRegistryInterface $sources */
        $sources = $container->get(ReconciliationSourceRegistryInterface::class);
        $sources->register($container->get(WpCommerceReconciliationSource::class));

        /** @var ProjectionRegistryInterface $projections */
        $projections = $container->get(ProjectionRegistryInterface::class);
        $projections->register(
            // No discriminator: commerce.products holds exactly one aggregate type. The
            // discriminated case arrives with commerce.taxonomies in P2-S3.
            new ProjectionDescriptor('product', 'commerce.products', 'source_product_id'),
        );

        // DISCRIMINATED: commerce.taxonomies is shared, so the descriptor must carry the
        // taxonomy predicate. Without it the orphan sweep would claim every attribute term as
        // a category candidate, and the backfill would inflate the category count by every
        // pa_* term — both real bugs DECISION AA had to fix for content.taxonomies.
        $projections->register(new ProjectionDescriptor(
            'product_category',
            'commerce.taxonomies',
            'source_term_id',
            CommerceTaxonomies::PRODUCT_CAT,
            'taxonomy_type',
        ));

        /** @var RefreshCoordinator $coordinator */
        $coordinator = $container->get(RefreshCoordinator::class);
        $coordinator->addProvider($container->get(CommerceEndpointProvider::class));
    }
}
