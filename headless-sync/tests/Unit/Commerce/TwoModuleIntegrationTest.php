<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\PartitionRouterInterface;
use HSP\Core\Contracts\ProjectionRegistryInterface;
use HSP\Core\Contracts\ReconciliationSourceRegistryInterface;
use HSP\Core\Contracts\ReplayEmitterRegistryInterface;
use HSP\Core\Container\Container;
use HSP\Core\Container\ContainerBuilder;
use HSP\Modules\Commerce\CommerceServiceProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * P2-S2 acceptance — Content and Commerce register SIMULTANEOUSLY through the exact generic
 * mechanism P2-S1 proved with a fixture (DECISION AG AG-1, AG-2, AG-3, AG-4).
 *
 * P2-S1 could only assert this against a test-scoped second-domain module, because it ships no
 * Commerce code. This is the real thing: two production modules, composed from their manifests,
 * neither displacing the other.
 *
 * WooCommerce is not installed in a headless PHPUnit process, so `CommerceServiceProvider`
 * reports unavailable and the composition root correctly skips it. To exercise the
 * both-modules-present path the availability probe is satisfied by declaring the class the
 * probe looks for — the narrowest possible stand-in, and notably NOT a stub of WooCommerce
 * behaviour: nothing here calls a WooCommerce function, so the test cannot accidentally assert
 * against a fake API instead of the real one.
 *
 * SEPARATE PROCESSES, deliberately: declaring the marker class is irreversible within a PHP
 * process, so without isolation it would leak into every later test and silently flip Commerce
 * to "available" for suites that assert the opposite — including
 * RealCompositionRootTest's WooCommerce-absent assertions, whose whole value is that they run
 * in the absent state.
 */
#[RunTestsInSeparateProcesses]
final class TwoModuleIntegrationTest extends TestCase
{
    private mixed $priorWpdb = null;

    public static function setUpBeforeClass(): void
    {
        // The availability probe is `class_exists(\WooCommerce::class, false) ||
        // function_exists('wc_get_product')`. Declaring the marker class is enough to flip it,
        // and it stays declared for the process — which is why every assertion below is about
        // WIRING, never about WooCommerce behaviour.
        if (! class_exists(\WooCommerce::class, false)) {
            eval('class WooCommerce {}');
        }
    }

    protected function setUp(): void
    {
        if (! (new CommerceServiceProvider())->isAvailable()) {
            self::markTestSkipped('WooCommerce marker class could not be declared.');
        }

        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
        };
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
    }

    private function build(): Container
    {
        return (new ContainerBuilder())->build(
            ['worker' => ['reconciliation' => ['page_size' => 500]]],
            \dirname(__DIR__, 3) . '/modules/',
        );
    }

    public function testBothModulesComposeSimultaneously(): void
    {
        $available = $this->build()->get('module.available_names');

        self::assertContains('content', $available);
        self::assertContains('commerce', $available);
    }

    /**
     * The assertion AG-1 exists for: adding a whole second module introduced no concrete
     * Commerce reference under core/. Checked as a compile-time dependency (`use` statements),
     * because that is what actually couples code — a docblock mentioning Commerce does not.
     */
    public function testAddingCommerceRequiredNoConcreteReferenceInCore(): void
    {
        $offenders = [];

        foreach ([\dirname(__DIR__, 3) . '/core', \dirname(__DIR__, 3) . '/bootstrap'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());

                if (preg_match('/^\s*use\s+HSP\\\\Modules\\\\Commerce\\\\/m', $contents) === 1) {
                    $offenders[] = $file->getPathname();
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * Neither module displaces the other in any core-owned registry. Before AG-2 the second
     * module to bind a shared interface key would have silently deleted the first's emitter.
     */
    public function testNeitherModuleDisplacesTheOtherInTheRegistries(): void
    {
        $container = $this->build();

        /** @var ReplayEmitterRegistryInterface $emitters */
        $emitters = $container->get(ReplayEmitterRegistryInterface::class);
        /** @var ReconciliationSourceRegistryInterface $sources */
        $sources = $container->get(ReconciliationSourceRegistryInterface::class);
        /** @var ProjectionRegistryInterface $projections */
        $projections = $container->get(ProjectionRegistryInterface::class);

        $expected = [
            'page', 'post', 'category', 'tag', 'media',
            'product', 'product_category', 'attribute', 'attribute_term', 'product_variation',
            'inventory',
        ];

        self::assertEqualsCanonicalizing($expected, $emitters->aggregateTypes());
        self::assertEqualsCanonicalizing($expected, $sources->aggregateTypes());
        self::assertEqualsCanonicalizing($expected, $projections->aggregateTypes());
    }

    /**
     * Aggregate types are PLATFORM-WIDE keys, not per-module ones: a projection descriptor maps
     * one aggregate type to exactly one table, so `category` cannot mean `content.taxonomies`
     * for one module and `commerce.taxonomies` for another.
     *
     * Commerce therefore owns `product_category`, not `category`. The first implementation used
     * the bare name and the AG-2 duplicate guard rejected it at boot — which is exactly the
     * failure mode that guard exists for. Before P2-S1 the second module to register would
     * simply have taken ownership, with nothing reported.
     */
    public function testTheTwoDomainsOwnDistinctCategoryAggregates(): void
    {
        /** @var ProjectionRegistryInterface $projections */
        $projections = $this->build()->get(ProjectionRegistryInterface::class);

        self::assertSame('content.taxonomies', $projections->get('category')->table);
        self::assertSame('commerce.taxonomies', $projections->get('product_category')->table);

        // Both are SHARED tables, so both descriptors must carry a discriminator — otherwise
        // an orphan sweep claims another taxonomy's rows (the DECISION AA defect class).
        self::assertTrue($projections->get('category')->isDiscriminated());
        self::assertTrue($projections->get('product_category')->isDiscriminated());
        self::assertSame('product_cat', $projections->get('product_category')->discriminatorValue);
    }

    /** Production routing, not a fixture: each domain reaches its own partition (AG-4). */
    public function testEachDomainRoutesToItsOwnPartition(): void
    {
        /** @var PartitionRouterInterface $router */
        $router = $this->build()->get(PartitionRouterInterface::class);

        self::assertSame('content', $router->partitionForEventType('content.post.updated'));
        self::assertSame('commerce', $router->partitionForEventType('commerce.product.created'));
        self::assertEqualsCanonicalizing(['content', 'commerce'], $router->activePartitions());
    }

    /**
     * commerce.products holds exactly one aggregate type, so it carries no discriminator —
     * unlike the shared content.taxonomies, which must always be scoped.
     */
    public function testTheProductProjectionIsRegisteredUndiscriminated(): void
    {
        /** @var ProjectionRegistryInterface $projections */
        $projections = $this->build()->get(ProjectionRegistryInterface::class);

        $product = $projections->get('product');

        self::assertSame('commerce.products', $product->table);
        self::assertSame('source_product_id', $product->sourceIdColumn);
        self::assertFalse($product->isDiscriminated());
    }

    /**
     * Both modules number their migrations from 0001, which is only safe because the schema
     * CONTEXT differs — system.schema_versions is unique on (migration_name, schema_context).
     *
     * Built directly rather than through the module, because CommerceModule::getMigrations()
     * resolves the DDL connection lazily and would demand HSP_PG_* here. That laziness is the
     * point (FLAG-P1AS6A-5): module construction must not open a socket.
     */
    public function testCommerceMigrationsUseADistinctSchemaContext(): void
    {
        $conn = new class implements \HSP\Core\Migrations\Connection\ConnectionInterface {
            public function execute(string $sql): void {}
            /** @return array<int, array<string, mixed>> */
            public function query(string $sql, array $params = []): array { return []; }
            public function insert(string $sql, array $params = []): int { return 0; }
        };

        $migrations = [
            new \HSP\Modules\Commerce\Migrations\CreateCommerceSchemaMigration($conn),
            new \HSP\Modules\Commerce\Migrations\CreateCommerceProductsMigration($conn),
        ];

        $names    = array_map(static fn ($m): string => $m->getName(), $migrations);
        $contexts = array_map(static fn ($m): string => $m->getSchemaContext(), $migrations);

        self::assertSame(['0001_create_commerce_schema', '0002_create_commerce_products'], $names);
        self::assertSame(['commerce/pgsql', 'commerce/pgsql'], $contexts);

        // The collision that would occur if both modules shared one context: Content also
        // ships a 0001. Distinct contexts are what keep them apart.
        self::assertNotSame('content/pgsql', $contexts[0]);
    }
}
