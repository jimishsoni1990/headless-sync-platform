<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;


use HSP\Core\Container\Container;
use HSP\Core\Container\ContainerBuilder;
use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Module\ModuleRegistry;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use HSP\Core\Operations\Services\OperationsService;
use PHPUnit\Framework\TestCase;

/**
 * OpenAPI drift guard (OAPI-S1 / ADR-055 (f); enumeration scope ruled v1.28 "A-modified").
 *
 * MODULE COVERAGE (FLAG-OAPI-DRIFT-COMMERCE-1). The guard used to hand-build a container, register
 * `ContentServiceProvider`, stub `module.registry` to `[]` and boot `ContentModule` by name — so it
 * could only ever see Content, and Commerce's eight live `hsp/v1` routes went unguarded from the
 * day they shipped. It now drives the REAL composition root (`ContainerBuilder` over the real
 * `modules/` directory) and the REAL `ModuleRegistry` lifecycle, and reads descriptors from
 * `OperationsService::endpointDescriptors()`. Module coverage is therefore DISCOVERED exactly as
 * production discovers it (AG-1) — a third module is guarded with no edit to this file.
 *
 * FIVE assertions:
 *   (1) COMPLETENESS — every registered hsp/v1 route, MINUS the one frozen structural exemption
 *       `hsp/v1/onboarding/` (DECISION W (e) — first-run admin surface, outside the published
 *       delivery contract), has a complete EndpointDescriptor. Enumeration reads the FULL live
 *       hsp/v1 route index (external ground truth — the routes the real registrars register,
 *       captured via the bootstrap register_rest_route stub), NEVER the registry it checks, so the
 *       assertion cannot be circular. Net today: 25 − 6 = 19 guarded routes (ten content, eight
 *       commerce, openapi.json).
 *   (5) COMMERCE PROOF — a REAL live Commerce route with its REAL descriptor withheld fails the
 *       completeness check, so the widened coverage is demonstrated to bite rather than asserted.
 *   (2) META-SCHEMA — the generated document validates against the OFFICIAL OpenAPI 3.1
 *       meta-schema. Two layers: the PHP structural pre-check (always runs, fast-fail) THEN the
 *       authoritative Node ajv gate (tools/openapi-validator/validate-openapi.mjs) over the pinned
 *       fixture (ruling D, v1.29 — opis/json-schema removed for two reproduced 2020-12 conformance
 *       defects; no conformant PHP validator exists; Node is a sanctioned dev/CI dep — DECISION W (a)).
 *   (3) EXCLUSION (v1.27) — no endpoint whose metadata marks it non-public appears in the document.
 *   (4) NON-CIRCULARITY (v1.28) — a fixture hsp/v1 route OUTSIDE the exempted prefix WITHOUT a
 *       descriptor FAILS the completeness check (proves (1) reads the external index, not the
 *       registry).
 *
 * META-SCHEMA FIXTURE PROVENANCE (PINNED — never fetched at test time):
 *   tests/fixtures/openapi-3.1-meta-schema-pinned.json
 *   $id  https://spec.openapis.org/oas/3.1/schema/2022-10-07
 *   Source: OAI/OpenAPI-Specification tag 3.1.1 (schemas/v3.1/schema.yaml → JSON), retrieved
 *   2026-07-20, with exactly FOUR semantics-preserving edits: every `{"$dynamicRef": "#meta"}` →
 *   `{"$ref": "#/$defs/schema"}` (equivalent — the fixture validates as its own root resource, so
 *   no outer dynamic scope can retarget the `meta` anchor; `$dynamicRef "#meta"` can only resolve
 *   to `$defs/schema`). Self-contained, JSON Schema draft 2020-12.
 *
 * NODE GATE ENVIRONMENT CONTRACT: with node available the ajv gate runs. With node MISSING and
 * env HSP_REQUIRE_NODE_GATE unset, the meta-schema assertion is SKIPPED with a warning naming the
 * var; with HSP_REQUIRE_NODE_GATE=1 (set in CI) and node missing, it FAILS — never skips. The
 * completeness / exemption / exclusion / non-circularity assertions are pure PHP and unaffected.
 */
final class OpenApiDriftGuardTest extends TestCase
{
    /** The ONE frozen structural exemption prefix (ADR-055 (f), v1.28 — DECISION W (e)). */
    private const EXEMPT_PREFIX = 'hsp/v1/onboarding/';

    private const VALIDATOR_SCRIPT = __DIR__ . '/../../../../tools/openapi-validator/validate-openapi.mjs';

    private mixed $priorWpdb = null;

    /**
     * Two conditions the real composition root needs, and one of them is load-bearing for this
     * guard's whole purpose.
     *
     * `$wpdb` — OutboxServiceProvider reads `$wpdb->prefix` at composition time; a headless
     * PHPUnit process has no `$wpdb`. Same minimal stub RealCompositionRootTest uses.
     *
     * WooCommerce marker — Commerce's availability probe is EXACTLY
     * `class_exists(\WooCommerce::class, false)` (CommerceServiceProvider::isAvailable(), AG-12).
     * WITHOUT the marker Commerce is discovered-but-unavailable, registers nothing, and this
     * guard quietly goes back to checking Content alone — passing while eight Commerce routes
     * sit unguarded, which is the exact failure being fixed. Declaring it makes the
     * both-modules-active surface the DETERMINISTIC subject of the guard rather than a side
     * effect of which test happened to run first.
     *
     * The condition mirrors the probe and NOTHING else: `tests/bootstrap.php` stubs
     * `wc_get_product()`, so an extra `function_exists()` arm here would skip the eval and
     * silently leave Commerce unavailable. Nothing stubs WooCommerce BEHAVIOUR — no assertion
     * below calls a WooCommerce function.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->priorWpdb = $GLOBALS['wpdb'] ?? null;
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
        };

        if (! class_exists(\WooCommerce::class, false)) {
            eval('class WooCommerce {}');
        }
    }

    /** Guard the guard: if Commerce ever stops being active here, the coverage claim is void. */
    private function assertCommerceIsActive(Container $container): void
    {
        self::assertContains(
            'commerce',
            $container->get('module.available_names'),
            'The drift guard must run with Commerce ACTIVE, or it is not guarding Commerce routes.',
        );
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_rest_routes'], $GLOBALS['_hsp_stub_action_callbacks']);

        if ($this->priorWpdb === null) {
            unset($GLOBALS['wpdb']);
        } else {
            $GLOBALS['wpdb'] = $this->priorWpdb;
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // (1) Completeness — every non-exempted live hsp/v1 route has a descriptor
    // -------------------------------------------------------------------------

    public function test_every_non_exempted_hsp_v1_route_has_a_complete_descriptor(): void
    {
        $liveRoutes = $this->guardedRoutes($this->captureLiveHspV1Routes());
        $described  = $this->describedRouteKeys($this->registryDescriptors());

        $this->assertCommerceIsActive($this->bootContainer());

        // Net today: 25 live − 6 onboarding = 19 guarded (ten content + eight commerce +
        // openapi.json). It was 11 while the guard booted Content alone: the eight Commerce
        // routes were live in production and invisible here, which is what this now fixes.
        self::assertCount(
            19,
            $liveRoutes,
            'Expected 19 guarded hsp/v1 routes (ten content + eight commerce + openapi.json).',
        );

        // Named explicitly, so a regression that silently drops Commerce from the live index
        // fails on the reason rather than on an opaque count.
        foreach (['hsp/v1/products', 'hsp/v1/products/{slug}/variations', 'hsp/v1/product-categories'] as $commerceRoute) {
            self::assertContains(
                $commerceRoute,
                $liveRoutes,
                "Commerce route '{$commerceRoute}' must be part of the guarded hsp/v1 surface.",
            );
        }

        foreach ($liveRoutes as $route) {
            self::assertContains(
                $route,
                $described,
                "hsp/v1 route '{$route}' is registered but has no complete EndpointDescriptor "
                . '(ADR-055 (f)(1) — a route without metadata fails CI).',
            );
        }
    }

    public function test_onboarding_prefix_is_the_only_thing_subtracted(): void
    {
        $all       = $this->captureLiveHspV1Routes();
        $guarded   = $this->guardedRoutes($all);
        $exempted  = array_values(array_diff($all, $guarded));

        self::assertNotEmpty($exempted, 'The onboarding surface should contribute exempted routes.');
        foreach ($exempted as $route) {
            self::assertStringStartsWith(
                self::EXEMPT_PREFIX,
                $route,
                "Only the frozen '" . self::EXEMPT_PREFIX . "' prefix may be exempted (ADR-055 (f), v1.28).",
            );
        }
    }

    // -------------------------------------------------------------------------
    // (2) The generated document validates against the OpenAPI 3.1 meta-schema
    // -------------------------------------------------------------------------

    public function test_generated_document_validates_against_openapi_3_1_meta_schema(): void
    {
        $document  = (new OpenApiGenerator())->generate($this->registryDescriptors());
        $validator = new OpenApiMetaSchemaValidator(self::VALIDATOR_SCRIPT);

        // Layer 1 — fast, readable structural pre-check (PHP, always runs).
        self::assertSame(
            [],
            $validator->structuralViolations($document),
            'OpenAPI structural pre-check failed',
        );

        // Layer 2 — authoritative meta-schema gate via Node ajv (ADR-055 (f)(2); ruling D v1.29).
        $error  = null;
        $status = $validator->gateStatus($document, $error);

        if ($status === OpenApiMetaSchemaValidator::GATE_SKIPPED) {
            // Node missing. FAIL when the CI flag demands the gate; otherwise SKIP with a warning
            // that names the env var (environment contract, ruling D part 3).
            if (getenv('HSP_REQUIRE_NODE_GATE') === '1') {
                self::fail(
                    'HSP_REQUIRE_NODE_GATE=1 requires the Node ajv meta-schema gate, but node is '
                    . 'unavailable: ' . (string) $error,
                );
            }

            self::markTestSkipped(
                'OpenAPI 3.1 meta-schema gate SKIPPED — node runtime unavailable ('
                . (string) $error . '). Set HSP_REQUIRE_NODE_GATE=1 (CI) to make this a hard failure '
                . 'instead of a skip, and run `npm install` in tools/openapi-validator/.',
            );
        }

        self::assertSame(
            OpenApiMetaSchemaValidator::GATE_VALID,
            $status,
            'Generated document failed OpenAPI 3.1 meta-schema validation (ajv): ' . (string) $error,
        );
    }

    // -------------------------------------------------------------------------
    // (3) Exclusion — non-public metadata never reaches the served document
    // -------------------------------------------------------------------------

    public function test_non_public_endpoint_is_excluded_from_the_generated_document(): void
    {
        $descriptors = [
            ...$this->registryDescriptors(),
            new EndpointDescriptor(
                method: 'GET',
                route: '/internal-secret',
                namespace: 'hsp/v1',
                displayGroup: 'Admin',
                description: 'Authenticated-only fixture route.',
                auth: EndpointAuth::Authenticated,
            ),
        ];

        $document = (new OpenApiGenerator())->generate($descriptors);

        self::assertArrayNotHasKey(
            '/hsp/v1/internal-secret',
            $document['paths'],
            'A non-public-metadata endpoint must NOT appear in the public document (ADR-055 (d)).',
        );
        // The public content endpoints are still present.
        self::assertArrayHasKey('/hsp/v1/posts', $document['paths']);
    }

    // -------------------------------------------------------------------------
    // (4) Non-circularity — an undescribed non-exempt route fails the guard
    // -------------------------------------------------------------------------

    public function test_undescribed_non_exempt_route_fails_the_completeness_guard(): void
    {
        // A live hsp/v1 route OUTSIDE the exempted prefix, with NO descriptor registered.
        $liveRoutes = $this->guardedRoutes([...$this->captureLiveHspV1Routes(), 'hsp/v1/orphan']);
        $described  = $this->describedRouteKeys($this->registryDescriptors());

        self::assertContains('hsp/v1/orphan', $liveRoutes, 'Fixture orphan route must survive the exemption subtraction.');
        self::assertNotContains(
            'hsp/v1/orphan',
            $described,
            'The orphan has no descriptor — the completeness guard MUST treat it as a failure, '
            . 'proving the guard reads the external route index (non-circular).',
        );
    }

    /**
     * The Commerce-specific proof the guard now bites (FLAG-OAPI-DRIFT-COMMERCE-1).
     *
     * The non-circularity test above uses a fixture route. This one uses a REAL, live Commerce
     * route and withholds its REAL descriptor — which is exactly the state the repository was in
     * before this fix, except that then it was every Commerce route and nothing failed.
     *
     * It deliberately asserts against the LIVE route index, not against the descriptors: a guard
     * that only checked "is every registered schema valid?" would have stayed green throughout,
     * because the Commerce schemas were valid — they were simply never compared to the routes.
     */
    public function test_a_commerce_route_without_its_descriptor_fails_the_completeness_guard(): void
    {
        $liveRoutes = $this->guardedRoutes($this->captureLiveHspV1Routes());

        self::assertContains(
            'hsp/v1/products',
            $liveRoutes,
            'The live index must contain the Commerce route for this proof to mean anything.',
        );

        // Drop every commerce-owned descriptor — simulating a Commerce endpoint shipped with no
        // registered metadata.
        $withoutCommerce = array_values(array_filter(
            $this->registryDescriptors(),
            static fn (EndpointDescriptor $d): bool => $d->moduleOwner !== 'commerce',
        ));

        $described = $this->describedRouteKeys($withoutCommerce);

        self::assertNotContains(
            'hsp/v1/products',
            $described,
            'Fixture precondition: the commerce descriptors were withheld.',
        );

        // The completeness assertion is `every live route ∈ described`. With the descriptor gone
        // that membership fails — i.e. CI fails, which is the whole point of ADR-055 (f)(1).
        $undescribed = array_values(array_diff($liveRoutes, $described));

        self::assertNotEmpty(
            $undescribed,
            'A Commerce route without a descriptor MUST fail the completeness guard (ADR-055 (f)(1)).',
        );
        self::assertContains('hsp/v1/products', $undescribed);

        // And with the descriptors restored, the same check passes — proving the failure above
        // was caused by the missing metadata and nothing else.
        self::assertSame(
            [],
            array_values(array_diff($liveRoutes, $this->describedRouteKeys($this->registryDescriptors()))),
        );
    }

    // -------------------------------------------------------------------------
    // Route enumeration — the external ground truth (drive the real registrars)
    // -------------------------------------------------------------------------

    /**
     * Capture every live `hsp/v1` route by driving the SAME boot funnel production uses, then
     * normalise to descriptor route-key form (`hsp/v1{template-route}`). This is the external index
     * the guard checks — produced by the real boot path, never by the endpoint registry.
     *
     * Omission-proof by construction (ADR-055 v1.28 requirement): routes reach `rest_api_init` via
     *   (a) the CORE funnel — `RestRegistrarRegistry::coreRegistrarKeys()`, the ONE list production
     *       (`headless-sync.php`) iterates; a new core registrar added there is picked up here for
     *       free; and
     *   (b) the MODULE funnel — the real `ModuleRegistry` lifecycle (register-all → boot-all) over
     *       every AVAILABLE module, each of which `add_action('rest_api_init', …)`s its own
     *       registrar exactly as production does. No module is named here, so a module that ships
     *       routes cannot escape the guard by not appearing in a test array — the defect that let
     *       eight Commerce routes go unguarded.
     * Both hook onto `rest_api_init`; firing `do_action('rest_api_init')` runs them all. Nothing is
     * hand-listed in this test, so a registrar added to production but not to a test array cannot
     * exist — the exact drift class the guard defends against.
     *
     * @return list<string>
     */
    private function captureLiveHspV1Routes(): array
    {
        $GLOBALS['_hsp_stub_rest_routes']     = [];
        $GLOBALS['_hsp_stub_action_callbacks'] = [];

        $container = $this->bootContainer();

        // (a) CORE funnel — the single authoritative list production iterates in headless-sync.php.
        add_action('rest_api_init', static function () use ($container): void {
            foreach (\HSP\Core\Rest\RestRegistrarRegistry::coreRegistrarKeys() as $registrarKey) {
                $container->get($registrarKey)->register();
            }
        });

        // (b) MODULE funnel — the real ModuleRegistry lifecycle (register-all → boot-all), which
        // is what production runs. Each AVAILABLE module's boot() hooks its own REST registrar.
        // This used to be a hardcoded `ContentModule::boot()`, and that is precisely why eight
        // Commerce routes went unguarded: a module the test did not name could not be seen.
        // Driving the registry means module coverage is discovered, not listed (AG-1).
        /** @var ModuleRegistry $modules */
        $modules = $container->get('module.registry');
        $modules->register();
        $modules->boot();

        // Fire the hook exactly as WordPress would — runs every registrar hooked by (a) and (b).
        do_action('rest_api_init');

        $keys = [];
        foreach ($GLOBALS['_hsp_stub_rest_routes'] as $registration) {
            $namespace = (string) $registration['namespace'];
            if ($namespace !== 'hsp/v1') {
                continue;
            }
            $keys[] = $namespace . $this->normaliseWpRoute((string) $registration['route']);
        }

        unset($GLOBALS['_hsp_stub_action_callbacks']);

        return array_values(array_unique($keys));
    }

    /**
     * Subtract the ONE frozen structural exemption (the onboarding admin prefix).
     *
     * @param list<string> $routes
     * @return list<string>
     */
    private function guardedRoutes(array $routes): array
    {
        return array_values(array_filter(
            $routes,
            static fn (string $route): bool => ! str_starts_with($route, self::EXEMPT_PREFIX),
        ));
    }

    /**
     * Normalise a WP route to OpenAPI template form: `(?P<slug>[a-z0-9_-]+)` → `{slug}`, and a
     * literal `openapi.json` stays as-is. Never used as the generation source (ADR-055 (a)).
     */
    private function normaliseWpRoute(string $route): string
    {
        $templated = preg_replace('/\(\?P<([a-zA-Z_]+)>[^)]*\)/', '{$1}', $route);

        return $templated ?? $route;
    }

    // -------------------------------------------------------------------------
    // The endpoint registry (the SAME source the generator + Playground consume)
    // -------------------------------------------------------------------------

    /**
     * The aggregated registry snapshot, read the way the generator and the API Playground read
     * it: `OperationsService::endpointDescriptors()` over every `EndpointProviderInterface` a
     * provider registered with the `RefreshCoordinator`.
     *
     * This used to hand-instantiate `ContentEndpointProvider` + `OpenApiEndpointProvider`, which
     * silently excluded Commerce from BOTH halves of the guard — the descriptors it compared
     * against AND the document it validated. Reading the real registry means any module that
     * registers a provider is covered with no edit here (ADR-055 (b): one source of truth).
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
     * The route keys a descriptor is COMPLETE for. "Complete" = has parameters coherent with the
     * route, a response schema, an auth requirement, a version and a module owner.
     *
     * @param EndpointDescriptor[] $descriptors
     * @return list<string>
     */
    private function describedRouteKeys(array $descriptors): array
    {
        $keys = [];
        foreach ($descriptors as $descriptor) {
            self::assertNotSame('', $descriptor->version, 'descriptor missing version (Doc 9 §7)');
            self::assertNotSame('', $descriptor->moduleOwner, 'descriptor missing module owner (Doc 9 §6)');
            self::assertNotNull($descriptor->responseSchema, "descriptor {$descriptor->route} missing response schema");
            $keys[] = trim($descriptor->namespace, '/') . $descriptor->route;
        }

        return array_values(array_unique($keys));
    }

    // -------------------------------------------------------------------------
    // Real container — the SAME service providers production wires, with fakes only for the
    // libpq-opening handle and reconciliation/replay leaves. No hand-built registrars.
    // -------------------------------------------------------------------------

    private ?Container $container = null;

    /**
     * The REAL composition root — `ContainerBuilder` against the REAL `modules/` directory.
     *
     * This replaced ~90 lines of hand-wired fakes, and the reason is the defect this guard
     * missed: the old container hand-registered `ContentServiceProvider` and stubbed
     * `module.registry` to return `[]`, so the guard could only ever see Content. Commerce
     * shipped eight `hsp/v1` routes that the completeness assertion never looked at.
     *
     * Driving the composition root instead means module coverage is DISCOVERED, exactly as
     * production discovers it (DECISION AG AG-1: each module declares its provider in
     * module.json; ModuleProviderComposer composes them; core hardcodes no module). A third
     * module adds no line to this file — which is the property that failed here.
     *
     * The ONE substitution is the delivery handle: the real binding is a FORCE_NEW libpq
     * connection (DECISION K) that would need live PostgreSQL. Route registration and endpoint
     * metadata never touch it, so a non-connecting stand-in keeps this in the Unit suite.
     */
    private function bootContainer(): Container
    {
        if ($this->container !== null) {
            return $this->container;
        }

        $container = (new ContainerBuilder())->build(
            ['worker' => ['reconciliation' => ['page_size' => 500]]],
            dirname(__DIR__, 4) . '/modules/',
        );

        // Stand-in delivery handle (DECISION K FORCE_NEW libpq needs live PG). Rebinding after
        // build() is safe: bindings resolve lazily, so nothing has opened a connection yet.
        $container->singleton(DatabaseConnectionInterface::class, fn () => new class implements DatabaseConnectionInterface {
            public function execute(string $sql, array $params = []): int
            {
                return 0;
            }

            /** @return array<int,array<string,mixed>> */
            public function query(string $sql, array $params = []): array
            {
                return [];
            }

            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollback(): void {}
        });

        return $this->container = $container;
    }

    // -------------------------------------------------------------------------
    // (6) Error metadata cannot silently disappear (CCF-003)
    // -------------------------------------------------------------------------

    /**
     * Every guarded route declares at least one non-2xx status, and the generated document gives
     * each one a JSON body schema.
     *
     * This is the drift class CCF-003 leaves behind. Every delivery callback is wrapped by the Core
     * error boundary, so every one of them can answer 500; a descriptor that declares nothing is
     * therefore not "an endpoint with no errors", it is an endpoint whose error metadata was
     * dropped. Reading the LIVE registry means a future module inherits the check with no edit
     * here — the same property the completeness assertion above relies on (AG-1).
     */
    public function test_every_described_route_declares_its_error_responses(): void
    {
        $descriptors = $this->registryDescriptors();
        $document    = (new OpenApiGenerator())->generate($descriptors);

        foreach ($descriptors as $descriptor) {
            self::assertNotSame(
                [],
                $descriptor->errorStatuses,
                "Descriptor {$descriptor->route} declares no error responses. Every delivery "
                . 'callback runs through the Core error boundary and can answer 500 (CCF-003).',
            );

            $path      = '/' . trim($descriptor->namespace, '/') . $descriptor->route;
            $responses = $document['paths'][$path][strtolower($descriptor->method)]['responses'];

            foreach ($descriptor->errorStatuses as $status) {
                self::assertArrayHasKey($status, $responses, "{$path} is missing its {$status}.");
                self::assertArrayHasKey(
                    'schema',
                    $responses[$status]['content']['application/json'] ?? [],
                    "{$path} documents {$status} with a description but no body schema — the "
                    . 'runtime returns JSON there (CCF-003 §22).',
                );
            }
        }
    }

    public function test_openapi_endpoint_provider_is_registered_so_the_route_self_describes(): void
    {
        // The openapi.json route both appears in the live index AND carries a descriptor (ADR-055 (4)).
        $live = $this->guardedRoutes($this->captureLiveHspV1Routes());
        self::assertContains('hsp/v1/openapi.json', $live);

        $described = $this->describedRouteKeys($this->registryDescriptors());
        self::assertContains('hsp/v1/openapi.json', $described);

        // And it is public → present in the generated document (self-describing).
        $document = (new OpenApiGenerator())->generate($this->registryDescriptors());
        self::assertArrayHasKey('/hsp/v1/openapi.json', $document['paths']);
    }
}
