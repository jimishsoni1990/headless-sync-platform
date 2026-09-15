<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\Operations\EndpointParameter;
use HSP\Core\Operations\OpenApi\OpenApiGenerator;
use HSP\Tests\Support\LiveHspRouteIndex;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR-055 (f) drift guard, at PARAMETER level (FLAG-RESTARGDRIFT-1).
 *
 * The route-level guard (OpenApiDriftGuardTest) proves every live `hsp/v1` route has a descriptor.
 * It cannot see INSIDE an operation, and that is the gap this flag came through: `/posts` had a
 * descriptor and a registration all along, and they described different request contracts —
 * `minimum: 1, maximum: 100` in the route args, a bare `integer` in the published schema, and no
 * enforcement anywhere, so `?per_page=500` answered 200.
 *
 * FIVE things are asserted, and the last is the one that would have caught the original bug:
 *
 *   (1) PARAMETER SETS MATCH — every registered arg has a descriptor parameter and vice versa.
 *       This alone catches the three phantom `status` args that `/categories`, `/media` and
 *       `/tags` used to register without implementing.
 *   (2) CONSTRAINTS MATCH — required, type, enum, minimum, maximum, pattern, format, default.
 *       Implementation-only keys (`sanitize_callback`, `validate_callback`) are NOT compared:
 *       they are not part of any published contract.
 *   (3) LOCATION IS COHERENT — a descriptor `path` parameter appears as a capture group in the
 *       route, and a `query` parameter does not.
 *   (4) MUTATION — the comparison is shown to FAIL for each supported drift category, so the
 *       guard is demonstrated to bite rather than asserted to.
 *   (5) ENFORCEMENT EXISTS — every declared constraint has a path that actually applies it.
 *       "Constraint + sanitizer + no validator" is the exact shape of the shipped defect, and it
 *       must never be green again.
 *
 * NON-CIRCULARITY. The two sides come from LiveHspRouteIndex: the live route index is produced by
 * driving the real registrars, the parameters by reading the real descriptor registry. Neither is
 * derived from the other, and no generic parameter registry generates both — production keeps two
 * separate declarations, sharing only module-owned VALUE constants (`PublicStatus::SET`,
 * `ProductScope::SUPPORTED_TYPES`), so a developer who changes one declaration must still change
 * the other. Metadata agreement is necessary and not sufficient, so the behavioural suites
 * (ContentRestArgEnforcementTest, CommerceRestArgEnforcementTest) prove the runtime enforces what
 * is declared.
 */
final class EndpointParameterDriftGuardTest extends TestCase
{
    use LiveHspRouteIndex;

    /** The published constraint keywords. Compared on both sides; nothing else is. */
    private const CONSTRAINT_KEYS = ['enum', 'minimum', 'maximum', 'pattern', 'format', 'default'];

    /**
     * Parameters whose declared constraints are enforced by a MODULE validator rather than by
     * WordPress's generic schema validation, each with the reason and the test that proves it.
     *
     * This list is the only way assertion (5) can pass without a `validate_callback`, so it is
     * deliberately short, and every entry is a deliberate asymmetry recorded with
     * FLAG-RESTARGDRIFT-1:
     *
     *   status          — ContentRestRegistrar::validateStatus() emits the CCF-003 stable code
     *                     `hsp_invalid_status` and treats an empty value as absent. Generic
     *                     validation would change both, on a path that was already correct.
     *   published_after — a CLOSURE that calls rest_validate_request_arg() for WordPress's
     *                     `date-time` grammar and then adds the one semantic it lacks: an
     *                     explicit UTC offset.
     *
     * Both are proven by ContentRestArgEnforcementTest.
     *
     * @var array<string,string>
     */
    private const DOMAIN_ENFORCED = [
        'status'          => 'ContentRestRegistrar::validateStatus() — CCF-003 hsp_invalid_status',
        'published_after' => 'ContentRestRegistrar::validatePublishedAfter() — native grammar + offset',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWordPressPreconditions();
    }

    protected function tearDown(): void
    {
        $this->restoreWordPressPreconditions();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // (1)(2)(3) The live surface and the published contract agree, parameter by parameter
    // -------------------------------------------------------------------------

    public function test_no_registered_parameter_drifts_from_its_published_contract(): void
    {
        $registrations = $this->captureLiveHspV1Registrations();
        $rawRoutes     = $this->captureLiveHspV1RawRoutes();
        $descriptors   = $this->indexDescriptorsByRoute();

        $guarded = $this->guardedRoutes(array_keys($registrations));
        self::assertNotEmpty($guarded, 'The live hsp/v1 index must not be empty.');

        $drift    = [];
        $compared = 0;

        foreach ($guarded as $route) {
            self::assertArrayHasKey(
                $route,
                $descriptors,
                "hsp/v1 route '{$route}' has no descriptor — the route-level guard covers that.",
            );

            $drift = [...$drift, ...$this->parameterDrift(
                $route,
                $registrations[$route],
                $descriptors[$route]->parameters,
                $rawRoutes[$route] ?? $route,
            )];

            $compared += max(count($registrations[$route]), count($descriptors[$route]->parameters));
        }

        self::assertSame(
            [],
            $drift,
            "Request-parameter drift between the live REST surface and the EndpointDescriptor "
            . "registry:\n  " . implode("\n  ", $drift),
        );

        // Not a snapshot of today's count — just proof the comparison had real work to do, so a
        // future refactor that silently stops finding parameters cannot pass as "no drift".
        self::assertGreaterThan(
            40,
            $compared,
            'Expected the guard to compare the whole parameter surface.',
        );
    }

    /**
     * The three phantom args, named explicitly.
     *
     * `status` was registered on all five Content listings by a shared helper, but only `/posts`
     * and `/pages` read it — the taxonomy and media handlers never validate or forward it, so
     * `/wp-json/hsp/v1` advertised a parameter those operations do not implement. Named rather
     * than left to the generic assertion above, because a shared helper is exactly the kind of
     * thing that quietly re-adds it.
     */
    public function test_listings_without_a_status_filter_do_not_register_one(): void
    {
        $registrations = $this->captureLiveHspV1Registrations();

        foreach (['hsp/v1/categories', 'hsp/v1/tags', 'hsp/v1/media'] as $route) {
            self::assertArrayNotHasKey(
                'status',
                $registrations[$route],
                "{$route} must not register a `status` arg: its handler does not implement one "
                . '(FLAG-RESTARGDRIFT-1 B-1).',
            );
        }

        foreach (['hsp/v1/posts', 'hsp/v1/pages'] as $route) {
            self::assertArrayHasKey(
                'status',
                $registrations[$route],
                "{$route} DOES implement a status filter and must declare it.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // (5) Every declared constraint has something that enforces it
    // -------------------------------------------------------------------------

    /**
     * The assertion that would have failed on the shipped code.
     *
     * A constraint is enforceable only through one of three paths:
     *   a) `validate_callback` — generic `rest_validate_request_arg`, or a module closure;
     *   b) the ROUTE REGEX, for a path parameter whose published `pattern` is exactly the route's
     *      own capture group — checked below, not taken on trust;
     *   c) a named module validator in DOMAIN_ENFORCED, each with a behavioural test.
     *
     * WordPress installs its validating default `rest_parse_request_arg` only for an arg with a
     * `type` and NO `sanitize_callback` key, so "constraint + sanitizer + no validator" means the
     * constraint is inert. That was true of 48 of the 50 args this flag audited.
     */
    public function test_every_declared_constraint_has_an_enforcement_path(): void
    {
        $registrations = $this->captureLiveHspV1Registrations();
        $rawRoutes     = $this->captureLiveHspV1RawRoutes();

        $unenforced = [];

        foreach ($this->guardedRoutes(array_keys($registrations)) as $route) {
            foreach ($registrations[$route] as $name => $spec) {
                if (! $this->declaresEnforceableConstraint($spec)) {
                    continue;
                }
                if (! empty($spec['validate_callback'])) {
                    continue;
                }
                if (isset(self::DOMAIN_ENFORCED[$name])) {
                    continue;
                }
                if ($this->patternIsEnforcedByRoute($name, $spec, $rawRoutes[$route] ?? '')) {
                    continue;
                }

                $declared = array_keys(array_intersect_key($spec, array_flip(self::CONSTRAINT_KEYS)));

                $unenforced[] = sprintf(
                    '%s?%s declares %s but nothing enforces it — a sanitize_callback REPLACES '
                    . "WordPress's validating default",
                    $route,
                    $name,
                    $declared === [] ? 'type ' . (string) ($spec['type'] ?? '?') : implode(', ', $declared),
                );
            }
        }

        self::assertSame(
            [],
            $unenforced,
            "Declared-but-unenforced request constraints:\n  " . implode("\n  ", $unenforced),
        );
    }

    /**
     * And that assertion is shown to bite: the pre-fix `per_page` declaration — bounds, a
     * sanitizer, no validator — fails every one of the three enforcement paths.
     */
    public function test_the_enforcement_assertion_rejects_the_original_defect(): void
    {
        $shipped = [
            'type'              => 'integer',
            'minimum'           => 1,
            'maximum'           => 100,
            'sanitize_callback' => 'absint',
        ];

        self::assertTrue(
            $this->declaresEnforceableConstraint($shipped),
            'Bounds on an integer are enforceable constraints.',
        );
        self::assertEmpty($shipped['validate_callback'] ?? null, 'The defect was the missing validator.');
        self::assertFalse(
            $this->patternIsEnforcedByRoute('per_page', $shipped, '/posts'),
            'A query parameter is not enforced by route matching.',
        );
        self::assertArrayNotHasKey(
            'per_page',
            self::DOMAIN_ENFORCED,
            'per_page has no module validator, so nothing would have enforced its bounds.',
        );
    }

    // -------------------------------------------------------------------------
    // OpenAPI fidelity — a constraint reaches the published document, not just the descriptor
    // -------------------------------------------------------------------------

    /**
     * Every declared constraint appears in the generated Parameter Object's `schema`.
     *
     * The descriptor agreeing with the route args is only half the invariant: if the generator
     * dropped the keywords, consumers would still be reading a bare `type` while the runtime
     * enforced bounds — the same documented-one-thing/executed-another gap, one layer further
     * out. Generation stays registry-only: the document below is built from descriptors and never
     * from the WordPress route index (ADR-055 (a)).
     */
    public function test_declared_constraints_reach_the_generated_openapi_document(): void
    {
        $descriptors = $this->registryDescriptors();
        $document    = (new OpenApiGenerator())->generate($descriptors);

        $checked = 0;

        foreach ($descriptors as $descriptor) {
            $constraints = [];
            foreach ($descriptor->parameters as $parameter) {
                if ($parameter->schemaConstraints() !== []) {
                    $constraints[$parameter->name] = $parameter->schemaConstraints();
                }
            }

            if ($constraints === []) {
                continue;
            }

            $path      = '/' . trim($descriptor->namespace, '/') . $descriptor->route;
            $published = [];
            foreach ($document['paths'][$path][strtolower($descriptor->method)]['parameters'] as $emitted) {
                $published[$emitted['name']] = $emitted;
            }

            foreach ($constraints as $name => $expected) {
                self::assertArrayHasKey($name, $published, "{$path} does not publish {$name}.");

                foreach ($expected as $keyword => $value) {
                    self::assertArrayHasKey(
                        $keyword,
                        $published[$name]['schema'],
                        "{$path}?{$name}: the generator dropped `{$keyword}` from the published schema.",
                    );
                    self::assertSame($value, $published[$name]['schema'][$keyword]);
                    $checked++;
                }
            }
        }

        self::assertGreaterThan(20, $checked, 'Expected the whole constrained surface to be checked.');
    }

    /**
     * The numbers themselves, spot-checked in the served document.
     *
     * The generic assertion above would still pass if every bound were wrong in both places, so
     * these pin the actual published contract a consumer reads — including that Commerce's two
     * page-size maxima stay DIFFERENT, which is the mistake §11 of the task warned against.
     */
    public function test_the_published_document_carries_the_ruled_contracts(): void
    {
        $document = (new OpenApiGenerator())->generate($this->registryDescriptors());

        $schema = static function (string $path, string $name) use ($document): array {
            foreach ($document['paths'][$path]['get']['parameters'] as $parameter) {
                if ($parameter['name'] === $name) {
                    return $parameter['schema'];
                }
            }

            self::fail("{$path} publishes no `{$name}` parameter.");
        };

        // Content page size: 1..100 on every listing.
        foreach (['/posts', '/pages', '/categories', '/media', '/tags'] as $route) {
            self::assertSame(
                ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                $schema('/hsp/v1' . $route, 'per_page'),
            );
        }

        // Commerce page size: 100 for products, 200 for the term-shaped listings.
        self::assertSame(
            ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            $schema('/hsp/v1/products', 'limit'),
        );
        foreach ([
            '/hsp/v1/product-categories',
            '/hsp/v1/product-attributes',
            '/hsp/v1/product-attributes/{taxonomy}/terms',
            '/hsp/v1/products/{slug}/variations',
        ] as $path) {
            self::assertSame(
                ['type' => 'integer', 'minimum' => 1, 'maximum' => 200],
                $schema($path, 'limit'),
            );
        }

        self::assertSame(
            ['type' => 'integer', 'minimum' => 0],
            $schema('/hsp/v1/product-categories', 'parent'),
        );

        self::assertSame(
            ['type' => 'string', 'enum' => ['simple', 'variable']],
            $schema('/hsp/v1/products', 'type'),
        );

        self::assertSame(
            ['type' => 'string', 'enum' => ['publish']],
            $schema('/hsp/v1/posts', 'status'),
        );

        self::assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            $schema('/hsp/v1/posts', 'published_after'),
        );

        // The hierarchical page path must NOT publish the single-slug grammar: `about/team` is
        // the whole point of the endpoint (DECISION AD).
        self::assertSame(
            ['type' => 'string', 'pattern' => '^[a-z0-9_/-]+$'],
            $schema('/hsp/v1/pages/{path}', 'path'),
        );
        self::assertSame(
            ['type' => 'string', 'pattern' => '^[a-z0-9_-]+$'],
            $schema('/hsp/v1/posts/{slug}', 'slug'),
        );

        // The cursor stays opaque: a bare string, no internals (FLAG-RESTARGDRIFT-1 D-3).
        self::assertSame(['type' => 'string'], $schema('/hsp/v1/posts', 'cursor'));
        self::assertSame(['type' => 'string'], $schema('/hsp/v1/products', 'cursor'));

        // Money filters stay strings with no generic constraint (D-2).
        self::assertSame(['type' => 'string'], $schema('/hsp/v1/products', 'min_price'));
        self::assertSame(['type' => 'string'], $schema('/hsp/v1/products', 'max_price'));
    }

    /**
     * No parameter publishes a `default`, and that is deliberate: the real page-size defaults live
     * in the query providers and differ per endpoint (20 for posts, 50 for taxonomies), so
     * declaring one on the route would move default ownership and change valid requests. The
     * `default` field exists in the contract and is compared by this guard for future endpoints
     * that intentionally declare one.
     */
    public function test_no_parameter_publishes_a_default_today(): void
    {
        foreach ($this->registryDescriptors() as $descriptor) {
            foreach ($descriptor->parameters as $parameter) {
                self::assertNull(
                    $parameter->default,
                    "{$descriptor->route}?{$parameter->name} publishes a default. Adding one moves "
                    . 'default ownership out of the query provider and changes valid requests.',
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // (4) Mutation fixtures — the comparison must FAIL for every drift category
    // -------------------------------------------------------------------------

    /**
     * @return iterable<string,array{0:array<string,array<string,mixed>>,1:list<EndpointParameter>,2:string}>
     */
    public static function driftMutationProvider(): iterable
    {
        $args  = ['per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]];
        $param = EndpointParameter::query('per_page', 'integer', 'Page size.', minimum: 1, maximum: 100);

        yield 'REST arg exists, descriptor missing it' => [$args, [], 'not published'];

        yield 'descriptor parameter exists, REST registration missing it' => [[], [$param], 'not registered'];

        yield 'type differs' => [
            ['per_page' => ['type' => 'string', 'minimum' => 1, 'maximum' => 100]], [$param], 'type differs',
        ];

        yield 'minimum differs' => [
            ['per_page' => ['type' => 'integer', 'minimum' => 5, 'maximum' => 100]], [$param], 'minimum differs',
        ];

        yield 'maximum differs' => [
            ['per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200]], [$param], 'maximum differs',
        ];

        yield 'required differs' => [
            ['per_page' => ['type' => 'integer', 'required' => true, 'minimum' => 1, 'maximum' => 100]],
            [$param],
            'required differs',
        ];

        yield 'enum differs' => [
            ['status' => ['type' => 'string', 'enum' => ['publish', 'draft']]],
            [EndpointParameter::query('status', 'string', '', enum: ['publish'])],
            'enum differs',
        ];

        yield 'enum declared on one side only' => [
            ['status' => ['type' => 'string']],
            [EndpointParameter::query('status', 'string', '', enum: ['publish'])],
            'enum differs',
        ];

        yield 'pattern differs' => [
            ['slug' => ['type' => 'string', 'required' => true, 'pattern' => '^[a-z]+$']],
            [EndpointParameter::path('slug', 'string', '', '^[a-z0-9_-]+$')],
            'pattern differs',
        ];

        yield 'format differs' => [
            ['published_after' => ['type' => 'string', 'format' => 'date']],
            [EndpointParameter::query('published_after', 'string', '', format: 'date-time')],
            'format differs',
        ];

        yield 'default differs' => [
            ['per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20]],
            [$param],
            'default differs',
        ];

        yield 'location differs — a query parameter published as a path parameter' => [
            ['per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
            [EndpointParameter::path('per_page', 'integer', 'Page size.')],
            'location differs',
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $args
     * @param list<EndpointParameter>           $parameters
     */
    #[DataProvider('driftMutationProvider')]
    public function test_the_comparison_detects_each_drift_category(
        array $args,
        array $parameters,
        string $expected
    ): void {
        $drift = $this->parameterDrift(
            'hsp/v1/fixture',
            $args,
            $parameters,
            '/fixture/(?P<slug>[a-z0-9_-]+)'
        );

        self::assertNotSame([], $drift, "The guard must detect this mutation ({$expected}).");
        self::assertStringContainsString(
            $expected,
            implode(' | ', $drift),
            'The failure must name what drifted, or a developer cannot act on it.',
        );
    }

    /** The unmutated pair must be clean, or the mutation cases above prove nothing. */
    public function test_the_comparison_passes_for_an_agreeing_pair(): void
    {
        self::assertSame([], $this->parameterDrift(
            'hsp/v1/fixture',
            [
                'slug'     => ['type' => 'string', 'required' => true, 'pattern' => '^[a-z0-9_-]+$'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
            [
                EndpointParameter::path('slug', 'string', '', '^[a-z0-9_-]+$'),
                EndpointParameter::query('per_page', 'integer', 'Page size.', minimum: 1, maximum: 100),
            ],
            '/fixture/(?P<slug>[a-z0-9_-]+)',
        ));
    }

    // -------------------------------------------------------------------------
    // The comparison itself
    // -------------------------------------------------------------------------

    /**
     * Compare one route's registered args against its published parameters.
     *
     * Returns a list of human-readable drift descriptions — empty means they agree. A pure
     * function of its four inputs, so the mutation cases above exercise the SAME code the live
     * assertion runs: a guard whose failure path is never executed is not a guard.
     *
     * @param array<string,array<string,mixed>> $args
     * @param EndpointParameter[]               $parameters
     * @return list<string>
     */
    private function parameterDrift(
        string $route,
        array $args,
        array $parameters,
        string $rawRoute
    ): array {
        $published = [];
        foreach ($parameters as $parameter) {
            $published[$parameter->name] = $parameter;
        }

        $drift = [];
        $names = array_unique([...array_keys($args), ...array_keys($published)]);
        sort($names);

        foreach ($names as $name) {
            $spec      = $args[$name] ?? null;
            $parameter = $published[$name] ?? null;

            if ($parameter === null) {
                $drift[] = "{$route}?{$name}: registered with WordPress but not published in the "
                    . 'EndpointDescriptor';
                continue;
            }

            if ($spec === null) {
                $drift[] = "{$route}?{$name}: published in the EndpointDescriptor but not "
                    . 'registered with WordPress';
                continue;
            }

            $registeredRequired = ($spec['required'] ?? false) === true;
            if ($registeredRequired !== $parameter->required) {
                $drift[] = sprintf(
                    '%s?%s: required differs — registered %s, published %s',
                    $route,
                    $name,
                    $registeredRequired ? 'true' : 'false',
                    $parameter->required ? 'true' : 'false'
                );
            }

            $registeredType = (string) ($spec['type'] ?? '');
            if ($registeredType !== $parameter->type) {
                $drift[] = sprintf(
                    '%s?%s: type differs — registered %s, published %s',
                    $route,
                    $name,
                    $registeredType === '' ? '(none)' : $registeredType,
                    $parameter->type
                );
            }

            $registeredConstraints = array_intersect_key($spec, array_flip(self::CONSTRAINT_KEYS));
            $publishedConstraints  = $parameter->schemaConstraints();

            foreach (self::CONSTRAINT_KEYS as $key) {
                $left  = $registeredConstraints[$key] ?? null;
                $right = $publishedConstraints[$key] ?? null;

                // Loose comparison on purpose: a bound declared as 1 and one declared as '1' are
                // the same published contract, and WordPress compares numerically anyway.
                if ($left != $right) {
                    $drift[] = sprintf(
                        '%s?%s: %s differs — registered %s, published %s',
                        $route,
                        $name,
                        $key,
                        json_encode($left),
                        json_encode($right)
                    );
                }
            }

            $inRoute  = str_contains($rawRoute, "(?P<{$name}>") || str_contains($rawRoute, '{' . $name . '}');
            $expected = $inRoute ? EndpointParameter::IN_PATH : EndpointParameter::IN_QUERY;

            if ($parameter->in !== $expected) {
                $drift[] = sprintf(
                    '%s?%s: location differs — the route %s a capture group for it, but the '
                    . 'parameter is published as `in: %s`',
                    $route,
                    $name,
                    $inRoute ? 'HAS' : 'has NO',
                    $parameter->in
                );
            }
        }

        return $drift;
    }

    /**
     * Does this arg declare something a request could actually violate?
     *
     * `type: string` alone does not: every query value arrives as a string, so there is nothing
     * to reject. A numeric or boolean type, or any constraint keyword, does.
     *
     * @param array<string,mixed> $spec
     */
    private function declaresEnforceableConstraint(array $spec): bool
    {
        if (array_intersect_key($spec, array_flip(self::CONSTRAINT_KEYS)) !== []) {
            return true;
        }

        return in_array($spec['type'] ?? null, ['integer', 'number', 'boolean'], true);
    }

    /**
     * Is this parameter's published `pattern` exactly the route's own capture group?
     *
     * If it is, WordPress route matching already enforces it and no callback is owed — a URL
     * outside the class never reaches the operation. Verified against the route rather than
     * assumed from the parameter being a path parameter, so widening a route regex without
     * widening the published pattern (or the reverse) fails here.
     *
     * @param array<string,mixed> $spec
     */
    private function patternIsEnforcedByRoute(string $name, array $spec, string $rawRoute): bool
    {
        if (! isset($spec['pattern'])) {
            return false;
        }

        if (preg_match('/\(\?P<' . preg_quote($name, '/') . '>([^)]*)\)/', $rawRoute, $matches) !== 1) {
            return false;
        }

        return '^' . $matches[1] . '$' === (string) $spec['pattern'];
    }

    /** @return array<string,EndpointDescriptor> */
    private function indexDescriptorsByRoute(): array
    {
        $indexed = [];
        foreach ($this->registryDescriptors() as $descriptor) {
            $indexed[trim($descriptor->namespace, '/') . $descriptor->route] = $descriptor;
        }

        return $indexed;
    }
}
