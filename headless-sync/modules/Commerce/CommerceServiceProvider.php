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
use HSP\Modules\Commerce\Adapters\AttributeAdapter;
use HSP\Modules\Commerce\Adapters\InventoryAdapter;
use HSP\Modules\Commerce\Adapters\ProductAdapter;
use HSP\Modules\Commerce\Adapters\TermAdapter;
use HSP\Modules\Commerce\Adapters\VariationAdapter;
use HSP\Modules\Commerce\Extractors\AttributeExtractor;
use HSP\Modules\Commerce\Extractors\InventoryExtractor;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\Handlers\AttributeTombstoneHandler;
use HSP\Modules\Commerce\Handlers\AttributeUpsertHandler;
use HSP\Modules\Commerce\Handlers\InventoryTombstoneHandler;
use HSP\Modules\Commerce\Handlers\InventoryUpsertHandler;
use HSP\Modules\Commerce\Handlers\ProductTombstoneHandler;
use HSP\Modules\Commerce\Handlers\ProductUpsertHandler;
use HSP\Modules\Commerce\Handlers\TermTombstoneHandler;
use HSP\Modules\Commerce\Handlers\TermUpsertHandler;
use HSP\Modules\Commerce\Handlers\VariationTombstoneHandler;
use HSP\Modules\Commerce\Handlers\VariationUpsertHandler;
use HSP\Modules\Commerce\Migrations\CreateCommerceAttributesMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceEntityTaxonomiesMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceInventoryMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceProductsMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceProductVariationsMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceTaxonomiesMigration;
use HSP\Modules\Commerce\Migrations\CreateCommerceSchemaMigration;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Queries\AttributeQueryProvider;
use HSP\Modules\Commerce\Queries\ProductQueryProvider;
use HSP\Modules\Commerce\Queries\TermQueryProvider;
use HSP\Modules\Commerce\Queries\VariationQueryProvider;
use HSP\Modules\Commerce\Reconciliation\WpCommerceReconciliationSource;
use HSP\Modules\Commerce\Replay\CommerceReplayEmitter;
use HSP\Modules\Commerce\Resources\AttributeResource;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrar;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrarFactory;
use HSP\Modules\Commerce\Subscribers\CommerceSubscriber;
use HSP\Modules\Commerce\Subscribers\CommerceSubscriberRegistrar;
use HSP\Modules\Commerce\Transformers\AttributeTransformer;
use HSP\Modules\Commerce\Transformers\InventoryTransformer;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Validation\AttributeValidator;
use HSP\Modules\Commerce\Validation\InventoryValidator;
use HSP\Modules\Commerce\Validation\ProductValidator;
use HSP\Modules\Commerce\Validation\TermValidator;
use HSP\Modules\Commerce\Validation\VariationValidator;

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

        $container->singleton(AttributeValidator::class, fn () => new AttributeValidator());
        $container->singleton(AttributeExtractor::class, fn (Container $c) =>
            new AttributeExtractor($c->get(AttributeValidator::class)));
        $container->singleton(AttributeTransformer::class, fn () => new AttributeTransformer());
        $container->singleton(AttributeAdapter::class, fn (Container $c) =>
            new AttributeAdapter($c->get(DatabaseConnectionInterface::class)));

        $container->singleton(AttributeUpsertHandler::class, fn (Container $c) =>
            new AttributeUpsertHandler(
                $c->get(WpCommerceLoader::class),
                $c->get(AttributeExtractor::class),
                $c->get(AttributeTransformer::class),
                $c->get(AttributeAdapter::class),
            ));

        $container->singleton(AttributeTombstoneHandler::class, fn (Container $c) =>
            new AttributeTombstoneHandler($c->get(AttributeAdapter::class)));

        $container->singleton(InventoryValidator::class, fn () => new InventoryValidator());
        $container->singleton(InventoryExtractor::class, fn (Container $c) =>
            new InventoryExtractor($c->get(InventoryValidator::class)));
        $container->singleton(InventoryTransformer::class, fn () => new InventoryTransformer());
        $container->singleton(InventoryAdapter::class, fn (Container $c) =>
            new InventoryAdapter($c->get(DatabaseConnectionInterface::class)));

        $container->singleton(InventoryUpsertHandler::class, fn (Container $c) =>
            new InventoryUpsertHandler(
                $c->get(WpCommerceLoader::class),
                $c->get(InventoryExtractor::class),
                $c->get(InventoryTransformer::class),
                $c->get(InventoryAdapter::class),
            ));

        $container->singleton(InventoryTombstoneHandler::class, fn (Container $c) =>
            new InventoryTombstoneHandler($c->get(InventoryAdapter::class)));

        $container->singleton(VariationValidator::class, fn () => new VariationValidator());
        $container->singleton(VariationExtractor::class, fn (Container $c) =>
            new VariationExtractor($c->get(VariationValidator::class)));
        $container->singleton(VariationTransformer::class, fn () => new VariationTransformer());
        $container->singleton(VariationAdapter::class, fn (Container $c) =>
            new VariationAdapter($c->get(DatabaseConnectionInterface::class)));

        $container->singleton(VariationUpsertHandler::class, fn (Container $c) =>
            new VariationUpsertHandler(
                $c->get(WpCommerceLoader::class),
                $c->get(VariationExtractor::class),
                $c->get(VariationTransformer::class),
                $c->get(VariationAdapter::class),
            ));

        $container->singleton(VariationTombstoneHandler::class, fn (Container $c) =>
            new VariationTombstoneHandler($c->get(VariationAdapter::class)));

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
                $c->get(AttributeUpsertHandler::class),
                $c->get(AttributeTombstoneHandler::class),
                $c->get(VariationUpsertHandler::class),
                $c->get(VariationTombstoneHandler::class),
                $c->get(InventoryUpsertHandler::class),
                $c->get(InventoryTombstoneHandler::class),
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

        $container->singleton(VariationQueryProvider::class, fn (Container $c) =>
            new VariationQueryProvider($c->get(DatabaseConnectionInterface::class)));
        $container->singleton(VariationResource::class, fn () => new VariationResource());

        $container->singleton(AttributeQueryProvider::class, fn (Container $c) =>
            new AttributeQueryProvider($c->get(DatabaseConnectionInterface::class)));
        $container->singleton(AttributeResource::class, fn () => new AttributeResource());

        // Parameterised by taxonomy: one class, one binding per taxonomy (P1B-S3 precedent).
        $container->singleton('commerce.category_query_provider', fn (Container $c) =>
            new TermQueryProvider(
                $c->get(DatabaseConnectionInterface::class),
                CommerceTaxonomies::PRODUCT_CAT,
            ));

        // The pa_* case cannot be a binding: the set of attribute taxonomies is defined by the
        // operator at runtime, so the provider is built per request from the requested taxonomy
        // (the registrar validates the `pa_` prefix before calling this).
        $container->singleton('commerce.attribute_term_query_factory', fn (Container $c) =>
            static fn (string $taxonomy): TermQueryProvider => new TermQueryProvider(
                $c->get(DatabaseConnectionInterface::class),
                $taxonomy,
            ));

        $container->singleton(CommerceRestRegistrar::class, fn (Container $c) =>
            new CommerceRestRegistrar(
                $c->get(ProductQueryProvider::class),
                $c->get(ProductResource::class),
                $c->get('commerce.category_query_provider'),
                $c->get(TermResource::class),
                $c->get(AttributeQueryProvider::class),
                $c->get(AttributeResource::class),
                $c->get('commerce.attribute_term_query_factory'),
                $c->get(VariationQueryProvider::class),
                $c->get(VariationResource::class),
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
                $c->get(AttributeExtractor::class),
                $c->get(AttributeTransformer::class),
                $c->get(VariationExtractor::class),
                $c->get(VariationTransformer::class),
                $c->get(InventoryExtractor::class),
                $c->get(InventoryTransformer::class),
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
                        new CreateCommerceAttributesMigration($conn),
                        new CreateCommerceProductVariationsMigration($conn),
                        new CreateCommerceInventoryMigration($conn),
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

        // Attribute DEFINITIONS get their own table, so no discriminator (AG-9).
        $projections->register(
            new ProjectionDescriptor('attribute', 'commerce.attributes', 'source_attribute_id'),
        );

        // Attribute TERMS share commerce.taxonomies with categories, but their discriminator is
        // a PREFIX rather than one fixed value: `pa_colour`, `pa_size` and every taxonomy an
        // operator defines later all belong to this one aggregate. An exact-match descriptor
        // could not express that without enumerating taxonomies that do not exist yet.
        $projections->register(new ProjectionDescriptor(
            'attribute_term',
            'commerce.taxonomies',
            'source_term_id',
            CommerceTaxonomies::ATTRIBUTE_PREFIX,
            'taxonomy_type',
            ProjectionDescriptor::MATCH_PREFIX,
        ));

        // No discriminator: commerce.product_variations holds exactly one aggregate type.
        $projections->register(new ProjectionDescriptor(
            'product_variation',
            'commerce.product_variations',
            'source_variation_id',
        ));

        // commerce.inventory holds one aggregate type, so no discriminator — but note the source
        // identity column is `owner_id` rather than a `source_*_id`: a stock fact is addressed by
        // whoever owns it, and that owner may be a product or a variation (AG-14).
        $projections->register(new ProjectionDescriptor(
            'inventory',
            'commerce.inventory',
            'owner_id',
        ));

        /** @var RefreshCoordinator $coordinator */
        $coordinator = $container->get(RefreshCoordinator::class);
        $coordinator->addProvider($container->get(CommerceEndpointProvider::class));
    }
}
