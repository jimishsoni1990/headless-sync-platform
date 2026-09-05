<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

use HSP\Core\Container\Container;
use HSP\Core\Container\Definitions\OnboardingServiceProvider;
use HSP\Core\Contracts\Operations\EndpointAuth;
use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Operations\OpenApi\OpenApiEndpointProvider;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Modules\Content\ContentModule;
use HSP\Modules\Content\ContentServiceProvider;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Tests\Unit\Content\FakeOutboxWriter;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconciliationSource;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * OpenAPI drift guard (OAPI-S1 / ADR-055 (f); enumeration scope ruled v1.28 "A-modified").
 *
 * FOUR assertions:
 *   (1) COMPLETENESS — every registered hsp/v1 route, MINUS the one frozen structural exemption
 *       `hsp/v1/onboarding/` (DECISION W (e) — first-run admin surface, outside the published
 *       delivery contract), has a complete EndpointDescriptor. Enumeration reads the FULL live
 *       hsp/v1 route index (external ground truth — the routes the real registrars register,
 *       captured via the bootstrap register_rest_route stub), NEVER the registry it checks, so the
 *       assertion cannot be circular. Net today: 13 − 6 = 7 guarded routes (six content + openapi).
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

    protected function tearDown(): void
    {
        unset($GLOBALS['_hsp_stub_rest_routes'], $GLOBALS['_hsp_stub_action_callbacks']);
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // (1) Completeness — every non-exempted live hsp/v1 route has a descriptor
    // -------------------------------------------------------------------------

    public function test_every_non_exempted_hsp_v1_route_has_a_complete_descriptor(): void
    {
        $liveRoutes = $this->guardedRoutes($this->captureLiveHspV1Routes());
        $described  = $this->describedRouteKeys($this->registryDescriptors());

        // Net today: 17 live − 6 onboarding = 11 guarded (ten content + openapi.json).
        // P1B-S1 added the two media routes, P1B-S3 the two tag routes; the exempted onboarding
        // prefix is unchanged.
        self::assertCount(11, $liveRoutes, 'Expected 11 guarded hsp/v1 routes (ten content + openapi.json).');

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
     *   (b) the MODULE funnel — each module's real `boot()` (here ContentModule::boot()), which
     *       `add_action('rest_api_init', …)`s its registrar exactly as production does.
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

        // (b) MODULE funnel — each module's real boot() hooks its own REST registrar. Driving the
        // actual ContentModule::boot() (not a hand-built registrar) means a new module's delivery
        // routes would be captured the same way, with no edit here.
        $container->get(ContentModule::class)->boot();

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

    /** @return EndpointDescriptor[] */
    private function registryDescriptors(): array
    {
        return [
            ...(new ContentEndpointProvider())->endpoints(),
            ...(new OpenApiEndpointProvider())->endpoints(),
        ];
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

    private function bootContainer(): Container
    {
        if ($this->container !== null) {
            return $this->container;
        }

        $container = new Container();

        // Stand-in delivery handle (the real one is FORCE_NEW libpq — DECISION K; opening it needs
        // live PG). Every consumer resolves through this, so no registrar opens a real connection.
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

        // Content module (real ContentServiceProvider — its ContentRestRegistrar is what boot()
        // hooks onto rest_api_init). OutboxWriterInterface is the one leaf dep it needs beyond the
        // delivery handle.
        $container->singleton(OutboxWriterInterface::class, fn () => new FakeOutboxWriter());
        (new ContentServiceProvider())->register($container);

        // Reconciliation/replay leaves the onboarding graph resolves lazily (fakes — never exercised
        // by route registration, but must resolve if touched).
        $container->singleton(WpReconciliationSourceInterface::class, fn () => new FakeReconciliationSource());
        $container->singleton(
            ReconciliationService::class,
            fn (Container $c) => new ReconciliationService(
                new FakeReconConnection(),
                $c->get(WpReconciliationSourceInterface::class),
                new ReplayService(new FakeDbConnection(), [new FakeReplayEmitter()]),
            ),
        );

        // Self-remediation upstream deps (resolved lazily by MigrationApplier / WorkerCronSpawner).
        $container->singleton(
            \HSP\Core\Workers\ProcessingCronRegistrar::class,
            fn () => new \HSP\Core\Workers\ProcessingCronRegistrar(
                static fn (): \HSP\Core\Contracts\WorkerInterface => throw new \LogicException('unused in drift guard'),
                [],
            ),
        );
        $container->singleton('migration.runner', fn () => null);
        $container->singleton('migrations.core', fn (): array => []);
        $container->singleton('module.registry', fn () => new class {
            /** @return array<string,object> */
            public function all(): array
            {
                return [];
            }
        });

        // Core service providers whose registrars appear in RestRegistrarRegistry: Onboarding +
        // Operations (the OpenApi registrar needs OperationsService).
        (new OnboardingServiceProvider())->register($container);
        (new \HSP\Core\Container\Definitions\OperationsServiceProvider())->register($container);

        return $this->container = $container;
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
