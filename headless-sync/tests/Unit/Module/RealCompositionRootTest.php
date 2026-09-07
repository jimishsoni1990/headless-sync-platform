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

    /**
     * These assertions describe the WooCommerce-ABSENT state, which is only observable while
     * the process has no WooCommerce marker.
     *
     * TwoModuleIntegrationTest declares that marker to exercise the both-modules-present path,
     * and a class declaration cannot be undone within a PHP process — so when the two run in
     * one process, whichever goes second sees the other's world. Rather than assert something
     * order-dependent, the absent-path tests self-skip when the condition they describe is not
     * available, matching how the integration suite skips without a live database. Run this
     * file alone and they execute.
     */
    private function requireWooCommerceAbsent(): void
    {
        if (class_exists(\WooCommerce::class, false) || function_exists('wc_get_product')) {
            self::markTestSkipped(
                'WooCommerce is present in this process (declared by the two-module test), so the '
                . 'WooCommerce-absent path cannot be observed here.'
            );
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

    /**
     * Content is available; Commerce is DISCOVERED BUT UNAVAILABLE, because WooCommerce is not
     * loaded in a headless PHPUnit process.
     *
     * That is the AG-12 contract holding on real modules rather than a fixture: a module whose
     * external dependency is absent contributes nothing, and — the part that matters — its
     * absence does not degrade the module that is present.
     */
    public function testContentIsAvailableAndCommerceIsDiscoveredButUnavailable(): void
    {
        $this->requireWooCommerceAbsent();

        $container = $this->build();

        /** @var list<string> $available */
        $available = $container->get('module.available_names');
        /** @var list<string> $unavailable */
        $unavailable = $container->get('module.unavailable_names');

        self::assertContains('content', $available);
        self::assertContains(
            'commerce',
            $unavailable,
            'WooCommerce is not loaded here, so Commerce must report unavailable — not fail, and '
            . 'not register.',
        );
        self::assertNotContains('commerce', $available);

        /** @var ModuleRegistry $registry */
        $registry = $container->get('module.registry');
        $registry->register();

        self::assertArrayHasKey('content', $registry->all());
        self::assertSame('content', $registry->all()['content']->getName());
        self::assertArrayNotHasKey(
            'commerce',
            $registry->all(),
            'an unavailable module must not enter the lifecycle at all',
        );
    }

    /**
     * The sharpest form of "WooCommerce absent is a normal state" (AG-12): with Commerce
     * unavailable, Content's replay, reconciliation and projection registrations must be
     * exactly what they were before Commerce existed — no Commerce aggregate types leak in,
     * and nothing Content owns is displaced.
     */
    public function testAnUnavailableModuleContributesNothingToTheRegistries(): void
    {
        $this->requireWooCommerceAbsent();

        $container = $this->build();

        /** @var ReplayEmitterRegistryInterface $emitters */
        $emitters = $container->get(ReplayEmitterRegistryInterface::class);
        /** @var ProjectionRegistryInterface $projections */
        $projections = $container->get(ProjectionRegistryInterface::class);

        self::assertNotContains('product', $emitters->aggregateTypes());
        self::assertFalse($projections->has('product'));

        // And Content is untouched.
        self::assertEqualsCanonicalizing(
            ['page', 'post', 'category', 'tag', 'media'],
            $projections->aggregateTypes(),
        );
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
        $this->requireWooCommerceAbsent();

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
