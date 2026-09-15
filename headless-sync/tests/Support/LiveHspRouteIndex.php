<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

use HSP\Core\Container\Container;
use HSP\Core\Container\ContainerBuilder;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Module\ModuleRegistry;
use HSP\Core\Operations\Services\OperationsService;

/**
 * The two independent surfaces the ADR-055 drift guards compare, and nothing else.
 *
 *   (a) the LIVE `hsp/v1` route index — what the real registrars actually register with
 *       WordPress, captured by driving the real boot funnel against the bootstrap's
 *       `register_rest_route` stub; and
 *   (b) the ENDPOINT METADATA REGISTRY — `OperationsService::endpointDescriptors()`, the same
 *       source the generator and the API Playground read.
 *
 * Extracted so the route-existence guard (OpenApiDriftGuardTest) and the parameter-contract guard
 * (EndpointParameterDriftGuardTest) cannot disagree about what "live" means. A second hand-rolled
 * copy of this enumeration is exactly how eight Commerce routes went unguarded once already
 * (FLAG-OAPI-DRIFT-COMMERCE-1): the copy that was not updated kept passing.
 *
 * NON-CIRCULARITY (ADR-055 (f), v1.28) is a property of this file. (a) is produced by the real
 * boot path and never by the registry it is compared against, so neither guard can be satisfied
 * by the code it is checking. Production may share module-owned semantic constants between the
 * two registrations — `ProductScope::SUPPORTED_TYPES`, `PublicStatus::SET` — because the two
 * DECLARATIONS remain separate: a developer who changes one still has to change the other, and
 * the behavioural tests prove the runtime actually enforces what is declared.
 */
trait LiveHspRouteIndex
{
    private ?Container $sharedContainer = null;

    private mixed $priorWpdbValue = null;

    /**
     * Two conditions the real composition root needs, and one is load-bearing.
     *
     * `$wpdb` — OutboxServiceProvider reads `$wpdb->prefix` at composition time; a headless
     * PHPUnit process has no `$wpdb`.
     *
     * WooCommerce marker — Commerce's availability probe is EXACTLY
     * `class_exists(\WooCommerce::class, false)` (CommerceServiceProvider::isAvailable(), AG-12).
     * WITHOUT it Commerce is discovered-but-unavailable, registers nothing, and a guard quietly
     * goes back to checking Content alone — passing while the Commerce surface sits unguarded.
     * The condition mirrors the probe and NOTHING else; no assertion anywhere calls a WooCommerce
     * function.
     */
    private function bootWordPressPreconditions(): void
    {
        $this->priorWpdbValue = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
        };

        if (! class_exists(\WooCommerce::class, false)) {
            eval('class WooCommerce {}');
        }
    }

    private function restoreWordPressPreconditions(): void
    {
        unset($GLOBALS['_hsp_stub_rest_routes'], $GLOBALS['_hsp_stub_action_callbacks']);

        if ($this->priorWpdbValue === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdbValue;
        }
    }

    /**
     * Every live `hsp/v1` registration, keyed by descriptor route key (`hsp/v1/posts/{slug}`),
     * with the per-parameter `args` WordPress will actually dispatch against.
     *
     * Both funnels production uses are driven, and neither is hand-listed here:
     *   (a) CORE — `RestRegistrarRegistry::coreRegistrarKeys()`, the one list `headless-sync.php`
     *       iterates, so a new core registrar is picked up for free; and
     *   (b) MODULES — the real `ModuleRegistry` lifecycle (register-all → boot-all) over every
     *       AVAILABLE module, each hooking its own registrar exactly as production does.
     * Firing `do_action('rest_api_init')` runs them all, so a module that ships routes cannot
     * escape a guard by not appearing in a test array.
     *
     * @return array<string,array<string,array<string,mixed>>> route key => arg name => arg spec
     */
    private function captureLiveHspV1Registrations(): array
    {
        $GLOBALS['_hsp_stub_rest_routes']      = [];
        $GLOBALS['_hsp_stub_action_callbacks'] = [];

        $container = $this->bootContainer();

        add_action('rest_api_init', static function () use ($container): void {
            foreach (\HSP\Core\Rest\RestRegistrarRegistry::coreRegistrarKeys() as $registrarKey) {
                $container->get($registrarKey)->register();
            }
        });

        /** @var ModuleRegistry $modules */
        $modules = $container->get('module.registry');
        $modules->register();
        $modules->boot();

        do_action('rest_api_init');

        $registrations = [];
        foreach ($GLOBALS['_hsp_stub_rest_routes'] as $registration) {
            if ((string) $registration['namespace'] !== 'hsp/v1') {
                continue;
            }

            $key = 'hsp/v1' . $this->normaliseWpRoute((string) $registration['route']);
            /** @var array<string,mixed> $definition */
            $definition = $registration['args'];
            /** @var array<string,array<string,mixed>> $args */
            $args = $definition['args'] ?? [];

            $registrations[$key] = $args;
        }

        unset($GLOBALS['_hsp_stub_action_callbacks']);

        return $registrations;
    }

    /**
     * The raw WordPress route string for a descriptor route key, so a guard can compare a
     * published `pattern` against the capture group that structurally enforces it.
     *
     * @return array<string,string> route key => raw WP route (`/posts/(?P<slug>[a-z0-9_-]+)`)
     */
    private function captureLiveHspV1RawRoutes(): array
    {
        $this->captureLiveHspV1Registrations();

        $raw = [];
        foreach ($GLOBALS['_hsp_stub_rest_routes'] ?? [] as $registration) {
            if ((string) $registration['namespace'] !== 'hsp/v1') {
                continue;
            }
            $raw['hsp/v1' . $this->normaliseWpRoute((string) $registration['route'])]
                = (string) $registration['route'];
        }

        return $raw;
    }

    /**
     * The ONE frozen structural exemption prefix (ADR-055 (f), v1.28 — DECISION W (e)):
     * the first-run onboarding admin surface, which is outside the published delivery contract.
     *
     * A method rather than a trait constant because the platform minimum is PHP 8.1 and trait
     * constants need 8.2 (phpstan.neon.dist pins phpVersion 80100).
     */
    private function exemptPrefix(): string
    {
        return 'hsp/v1/onboarding/';
    }

    /** @return list<string> */
    private function captureLiveHspV1Routes(): array
    {
        return array_values(array_unique(array_keys($this->captureLiveHspV1Registrations())));
    }

    /**
     * Subtract the ONE frozen structural exemption (the onboarding admin prefix).
     *
     * @param list<string> $routes
     * @return list<string>
     */
    private function guardedRoutes(array $routes): array
    {
        $prefix = $this->exemptPrefix();

        return array_values(array_filter(
            $routes,
            static fn (string $route): bool => ! str_starts_with($route, $prefix),
        ));
    }

    /**
     * Normalise a WP route to OpenAPI template form: `(?P<slug>[a-z0-9_-]+)` → `{slug}`.
     * Never used as the generation source (ADR-055 (a)).
     */
    private function normaliseWpRoute(string $route): string
    {
        return preg_replace('/\(\?P<([a-zA-Z_]+)>[^)]*\)/', '{$1}', $route) ?? $route;
    }

    /**
     * The aggregated registry snapshot, read the way the generator and the Playground read it.
     *
     * @return EndpointDescriptor[]
     */
    private function registryDescriptors(): array
    {
        /** @var OperationsService $operations */
        $operations = $this->bootContainer()->get(OperationsService::class);

        return $operations->endpointDescriptors();
    }

    /**
     * The REAL composition root — `ContainerBuilder` against the REAL `modules/` directory, so
     * module coverage is DISCOVERED exactly as production discovers it (AG-1): a third module is
     * guarded with no edit to any test.
     *
     * The ONE substitution is the delivery handle: the real binding is a FORCE_NEW libpq
     * connection (DECISION K) needing live PostgreSQL. Route registration and endpoint metadata
     * never touch it, so a non-connecting stand-in keeps this in the Unit suite.
     */
    private function bootContainer(): Container
    {
        if ($this->sharedContainer !== null) {
            return $this->sharedContainer;
        }

        // `tests/Support` → `headless-sync/` → `headless-sync/modules/`. Asserted rather than
        // assumed: a wrong path here discovers NO modules, which makes every guard that uses this
        // trait pass vacuously against an empty route index — a silent false green, which is the
        // exact failure mode FLAG-RESTARGDRIFT-1 exists to eliminate.
        $modulesDirectory = dirname(__DIR__, 2) . '/modules/';

        if (! is_dir($modulesDirectory)) {
            throw new \RuntimeException(
                "The modules directory was not found at {$modulesDirectory}. The drift guards "
                . 'would otherwise run against an empty route index and pass for the wrong reason.'
            );
        }

        $container = (new ContainerBuilder())->build(
            ['worker' => ['reconciliation' => ['page_size' => 500]]],
            $modulesDirectory,
        );

        $container->singleton(
            DatabaseConnectionInterface::class,
            fn () => new class implements DatabaseConnectionInterface {
                public function execute(string $sql, array $params = []): int
                {
                    return 0;
                }

                /** @return array<int,array<string,mixed>> */
                public function query(string $sql, array $params = []): array
                {
                    return [];
                }

                public function beginTransaction(): void
                {
                }

                public function commit(): void
                {
                }

                public function rollback(): void
                {
                }
            }
        );

        return $this->sharedContainer = $container;
    }
}
