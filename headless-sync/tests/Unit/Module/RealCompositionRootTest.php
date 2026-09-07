<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Module;

use HSP\Core\Container\ContainerBuilder;
use HSP\Core\Contracts\ProjectionRegistryInterface;
use HSP\Core\Contracts\ReconciliationSourceRegistryInterface;
use HSP\Core\Contracts\ReplayEmitterRegistryInterface;
use HSP\Core\Module\ModuleRegistry;
use PHPUnit\Framework\TestCase;

/**
 * P2-S1 acceptance — the REAL composition root, against the REAL modules directory.
 *
 * Nothing previously built the actual container in a test, so the wiring that AG-1 changed
 * had no coverage at all: a module discovered from its manifest, its provider composed
 * generically, and its registrations landing in the core-owned registries. That is the
 * whole seam Commerce will arrive through, so it is asserted here rather than assumed.
 *
 * Resolution stays lazy (DECISION Z), so building the container opens no socket and this
 * runs in the Unit suite with no database.
 */
final class RealCompositionRootTest extends TestCase
{
    private mixed $priorWpdb = null;

    /**
     * The container graph reaches `global $wpdb` in the outbox provider's composition root
     * (OutboxServiceProvider reads `$wpdb->prefix`), which is null in a headless PHPUnit
     * process. ALIGN-S2 hit exactly this when it first drove a real Application::activate()
     * and resolved it the same way: a minimal, test-scoped stub exposing only ->prefix. It
     * is the sole WordPress substitution here and deliberately NOT in tests/bootstrap.php.
     */
    protected function setUp(): void
    {
        $this->priorWpdb  = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb']  = new class { public string $prefix = 'wp_'; };
    }

    protected function tearDown(): void
    {
        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }
    }

    private function build(): \HSP\Core\Container\Container
    {
        $config = [
            'worker' => ['reconciliation' => ['page_size' => 500]],
        ];

        return (new ContainerBuilder())->build($config, \dirname(__DIR__, 3) . '/modules/');
    }

    public function testTheContainerBuildsWithNoConcreteModuleReferenceInCore(): void
    {
        $container = $this->build();

        self::assertTrue(
            $container->has(\HSP\Modules\Content\ContentModule::class),
            'the Content module binding must come from its OWN provider, discovered via module.json'
        );
    }

    public function testTheDiscoveredModuleIsRegisteredAndAvailable(): void
    {
        $container = $this->build();

        /** @var list<string> $available */
        $available = $container->get('module.available_names');
        self::assertContains('content', $available);
        self::assertSame([], $container->get('module.unavailable_names'));

        /** @var ModuleRegistry $registry */
        $registry = $container->get('module.registry');
        $registry->register();

        self::assertArrayHasKey('content', $registry->all());
        self::assertSame('content', $registry->all()['content']->getName());
    }

    /**
     * getServiceProvider() must return a REAL provider now — it used to hand back an
     * anonymous no-op that nothing ever called (DECISION AG AG-1).
     */
    public function testModuleReturnsItsRealServiceProvider(): void
    {
        $container = $this->build();

        $module = $container->get(\HSP\Modules\Content\ContentModule::class);

        self::assertInstanceOf(
            \HSP\Modules\Content\ContentServiceProvider::class,
            $module->getServiceProvider(),
        );
    }

    /**
     * The three registries must be populated by the module's own boot(), not by core.
     * An empty registry here would mean replay, reconciliation and backfill silently
     * covering nothing — the DECISION AC failure mode, one level up.
     */
    public function testModuleRegistrationsLandInTheCoreOwnedRegistries(): void
    {
        $container = $this->build();

        $expected = ['page', 'post', 'category', 'tag', 'media'];

        /** @var ReplayEmitterRegistryInterface $emitters */
        $emitters = $container->get(ReplayEmitterRegistryInterface::class);
        self::assertEqualsCanonicalizing($expected, $emitters->aggregateTypes());

        /** @var ReconciliationSourceRegistryInterface $sources */
        $sources = $container->get(ReconciliationSourceRegistryInterface::class);
        self::assertEqualsCanonicalizing($expected, $sources->aggregateTypes());

        /** @var ProjectionRegistryInterface $projections */
        $projections = $container->get(ProjectionRegistryInterface::class);
        self::assertEqualsCanonicalizing($expected, $projections->aggregateTypes());
    }

    /**
     * The shared taxonomy projection must carry its discriminator through the registry —
     * without it the orphan sweep claims every tag row as a category candidate, and the
     * backfill inflates the category total by every tag (both real bugs, DECISION AA).
     */
    public function testSharedTaxonomyProjectionsCarryTheirDiscriminator(): void
    {
        /** @var ProjectionRegistryInterface $projections */
        $projections = $this->build()->get(ProjectionRegistryInterface::class);

        $category = $projections->get('category');
        $tag      = $projections->get('tag');

        self::assertSame('content.taxonomies', $category->table);
        self::assertSame('content.taxonomies', $tag->table);
        self::assertTrue($category->isDiscriminated());
        self::assertTrue($tag->isDiscriminated());
        self::assertSame('category', $category->discriminatorValue);
        self::assertSame('post_tag', $tag->discriminatorValue);

        // A table holding exactly one aggregate type needs no discriminator.
        self::assertFalse($projections->get('post')->isDiscriminated());
    }

    public function testReplayServiceIsConstructedByCoreNotByTheModule(): void
    {
        $container = $this->build();

        // Core owns the service; the module contributes only its emitter (AG-2).
        self::assertInstanceOf(
            \HSP\Core\Replay\ReplayService::class,
            $container->get(\HSP\Core\Replay\ReplayService::class),
        );
    }
}
