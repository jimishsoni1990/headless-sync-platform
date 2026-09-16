<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Rest;

use HSP\Modules\Content\Rest\ContentRestRegistrar;
use HSP\Tests\Support\LiveHspRouteIndex;
use HSP\Tests\Support\WpRestArgDispatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Content request constraints, as REQUESTS rather than as metadata (FLAG-RESTARGDRIFT-1 §24).
 *
 * The declaration was never the problem: `per_page` carried `minimum: 1, maximum: 100` from the
 * day the endpoint shipped, and `?per_page=500` still answered 200. A test asserting "the arg
 * declares maximum 100" would have passed throughout the defect, which is why this file asks what
 * a REQUEST does instead.
 *
 * Two layers, deliberately:
 *   - the args come from the LIVE route index (the real registrars, via LiveHspRouteIndex), and
 *     are evaluated by WpRestArgDispatch, which implements WordPress 7.1's own
 *     has_valid_params() → sanitize_params() gate including the implicit validating default; and
 *   - the module-owned validators are exercised through the REAL handlers, so the CCF-003 codes
 *     (`hsp_invalid_status`, `hsp_invalid_cursor`) are proven to survive the generic fix rather
 *     than be replaced by it.
 *
 * Every rule asserted here is also verified against the real WordPress on the live site after
 * deploy; these run in CI on every commit.
 */
final class ContentRestArgEnforcementTest extends TestCase
{
    use LiveHspRouteIndex;

    /** @var array<string,array<string,array<string,mixed>>> */
    private array $registrations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWordPressPreconditions();
        $this->registrations = $this->captureLiveHspV1Registrations();
    }

    protected function tearDown(): void
    {
        $this->restoreWordPressPreconditions();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // per_page — the known defect
    // -------------------------------------------------------------------------

    /** @return iterable<string,array{0:mixed,1:bool,2:string}> */
    public static function perPageProvider(): iterable
    {
        // Query values arrive as strings, so the valid cases are asserted in string form too.
        yield 'lower bound 1'          => ['1', true, ''];
        yield 'upper bound 100'        => ['100', true, ''];
        yield 'mid range'              => ['20', true, ''];
        yield 'integer type'           => [10, true, ''];

        yield 'below minimum: 0'       => ['0', false, 'rest_out_of_bounds'];
        yield 'below minimum: -1'      => ['-1', false, 'rest_out_of_bounds'];
        yield 'above maximum: 101'     => ['101', false, 'rest_out_of_bounds'];
        yield 'above maximum: 500'     => ['500', false, 'rest_out_of_bounds'];
        yield 'not a number: abc'      => ['abc', false, 'rest_invalid_type'];
        yield 'not an integer: 1.5'    => ['1.5', false, 'rest_invalid_type'];
        yield 'empty value'            => ['', false, 'rest_invalid_type'];
    }

    /**
     * Asserted on EVERY Content listing, not just `/posts`: the bound is declared by one shared
     * helper, so a regression would hit all five at once and a single-route test would still pass
     * on the other four.
     */
    #[DataProvider('perPageProvider')]
    public function test_per_page_is_enforced_on_every_content_listing(
        mixed $value,
        bool $accepted,
        string $expectedCode
    ): void {
        foreach (['posts', 'pages', 'categories', 'media', 'tags'] as $listing) {
            $this->assertRequest("hsp/v1/{$listing}", ['per_page' => $value], $accepted, $expectedCode);
        }
    }

    /**
     * The one compatibility correction that previously returned real data.
     *
     * `/categories?per_page=150` and `/tags?per_page=150` used to answer 200 with up to 150 rows,
     * because CategoryQueryProvider and TagQueryProvider clamp internally at 200 while the
     * published contract — the registered arg AND the descriptor — says 1..100. The provider
     * ceiling is an implementation limit, not a consumer contract, so the request is out of
     * contract and is now rejected.
     */
    public function test_taxonomy_listings_enforce_the_published_100_not_the_internal_200_clamp(): void
    {
        foreach (['hsp/v1/categories', 'hsp/v1/tags'] as $route) {
            $this->assertRequest($route, ['per_page' => '100'], true, '');
            $this->assertRequest($route, ['per_page' => '150'], false, 'rest_out_of_bounds');
            $this->assertRequest($route, ['per_page' => '200'], false, 'rest_out_of_bounds');
        }
    }

    // -------------------------------------------------------------------------
    // published_after — WordPress's grammar plus the offset it leaves optional
    // -------------------------------------------------------------------------

    /** @return iterable<string,array{0:string,1:bool,2:string}> */
    public static function publishedAfterProvider(): iterable
    {
        yield 'RFC3339 UTC'              => ['2026-01-01T00:00:00Z', true, ''];
        yield 'RFC3339 positive offset'  => ['2026-01-01T05:30:00+05:30', true, ''];
        yield 'RFC3339 negative offset'  => ['2026-01-01T00:00:00-08:00', true, ''];
        yield 'fractional seconds'       => ['2026-01-01T00:00:00.123Z', true, ''];

        // Rejected by WordPress's own `format: date-time` grammar.
        yield 'date only'                => ['2026-01-01', false, 'rest_invalid_date'];
        yield 'garbage'                  => ['garbage', false, 'rest_invalid_date'];
        yield 'natural language'         => ['next tuesday', false, 'rest_invalid_date'];
        yield 'empty value'              => ['', false, 'rest_invalid_date'];

        // Accepted by WordPress, rejected by the module: an instant compared against TIMESTAMPTZ
        // must carry its timezone, and WP's regex makes the offset optional.
        yield 'no timezone'              => ['2026-01-01T00:00:00', false, 'hsp_invalid_filter'];
        yield 'space separator, no zone' => ['2026-01-01 00:00:00', false, 'hsp_invalid_filter'];
    }

    #[DataProvider('publishedAfterProvider')]
    public function test_published_after_requires_an_absolute_instant(
        string $value,
        bool $accepted,
        string $expectedCode
    ): void {
        foreach (['hsp/v1/posts', 'hsp/v1/pages', 'hsp/v1/media'] as $route) {
            $this->assertRequest($route, ['published_after' => $value], $accepted, $expectedCode);
        }
    }

    /**
     * The defect this replaced, stated as the thing that must not come back: an unparseable bound
     * used to be dropped, so the response was an UNFILTERED listing that looked like a successful
     * filter. Rejecting it is the point; silently ignoring it was the bug.
     */
    public function test_an_unusable_published_after_is_rejected_rather_than_dropped(): void
    {
        [$accepted] = WpRestArgDispatch::evaluate(
            $this->registrations['hsp/v1/posts'],
            ['published_after' => 'garbage']
        );

        self::assertFalse($accepted, 'A bound the endpoint cannot apply must never be ignored.');
    }

    // -------------------------------------------------------------------------
    // status — the module validator, and the CCF-003 code it owns
    // -------------------------------------------------------------------------

    public function test_status_outside_the_public_set_keeps_its_ccf_003_code(): void
    {
        $registrar = $this->registrar();

        $result = $registrar->handlePostListing(new \WP_REST_Request(['status' => 'draft']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_invalid_status', $result->code, 'CCF-003 stable code must not change.');
        self::assertSame(400, $result->data['status']);
    }

    /**
     * ABSENT and SUPPLIED-EMPTY are different requests (FLAG-RESTARGDRIFT-1 parity correction).
     *
     * Omitting `status` means "no status filter". Supplying `?status=` is the consumer handing over
     * a value that is not in the published `{publish}` enum, so it is a 400 exactly like
     * `?status=draft` — it used to be coerced into omission and answered 200, which made the
     * runtime accept a value the contract calls invalid. The contract did not widen to admit an
     * empty string; the runtime aligned to the contract.
     *
     * Asserted on BOTH status-bearing routes, since one shared helper registers the parameter and
     * one shared validator enforces it.
     */
    public function test_absent_status_is_the_default_listing_but_supplied_empty_is_a_400(): void
    {
        foreach (['handlePostListing', 'handlePageListing'] as $handler) {
            $registrar = $this->registrar();

            // Absent → the normal public listing.
            self::assertInstanceOf(
                \WP_REST_Response::class,
                $registrar->{$handler}(new \WP_REST_Request([])),
                "{$handler}: omitting status must return the default public listing.",
            );

            // publish → valid.
            self::assertInstanceOf(
                \WP_REST_Response::class,
                $registrar->{$handler}(new \WP_REST_Request(['status' => 'publish'])),
                "{$handler}: status=publish is the public set and must be accepted.",
            );

            // Supplied empty → 400, with the CCF-003 code, NOT silently treated as absent.
            $empty = $registrar->{$handler}(new \WP_REST_Request(['status' => '']));
            self::assertInstanceOf(
                \WP_Error::class,
                $empty,
                "{$handler}: a supplied empty status is a value outside {publish}, not an omission.",
            );
            self::assertSame('hsp_invalid_status', $empty->code);
            self::assertSame(400, $empty->data['status']);

            // draft → 400, same code, same shape.
            $draft = $registrar->{$handler}(new \WP_REST_Request(['status' => 'draft']));
            self::assertInstanceOf(\WP_Error::class, $draft);
            self::assertSame('hsp_invalid_status', $draft->code);
            self::assertSame(400, $draft->data['status']);
        }
    }

    /**
     * The empty string is NOT a second enum value.
     *
     * The correction had two possible shapes and only one is right: align the runtime to the
     * published set, or widen the published set to `['publish', '']` to match the accidental
     * runtime behaviour. This pins the first.
     */
    public function test_the_published_enum_does_not_contain_an_empty_string(): void
    {
        foreach (['hsp/v1/posts', 'hsp/v1/pages'] as $route) {
            self::assertSame(['publish'], $this->registrations[$route]['status']['enum']);
            self::assertNotContains('', $this->registrations[$route]['status']['enum']);
        }
    }

    /**
     * The declared enum is not merely declared: the value set the registrar rejects against is
     * the one the descriptor publishes, so `publish` is accepted and everything else is not.
     */
    public function test_the_enum_the_descriptor_publishes_is_the_set_the_runtime_accepts(): void
    {
        self::assertSame(
            \HSP\Modules\Content\PublicStatus::SET,
            $this->registrations['hsp/v1/posts']['status']['enum'],
        );

        foreach (['draft', 'private', 'pending', 'future', 'trash', 'inherit', 'PUBLISH'] as $rejected) {
            $result = $this->registrar()->handlePostListing(new \WP_REST_Request(['status' => $rejected]));
            self::assertInstanceOf(\WP_Error::class, $result, "status={$rejected} must be rejected.");
            self::assertSame('hsp_invalid_status', $result->code);
        }
    }

    // -------------------------------------------------------------------------
    // CCF-003 validation is not bypassed or replaced by the generic fix
    // -------------------------------------------------------------------------

    public function test_cursor_validation_still_owns_its_own_rejection(): void
    {
        $registrar = $this->registrar();

        $result = $registrar->handlePostListing(new \WP_REST_Request(['cursor' => 'aGVsbG8=']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_invalid_cursor', $result->code);
        self::assertSame(400, $result->data['status']);
    }

    public function test_a_malformed_page_path_still_returns_its_own_400(): void
    {
        $result = $this->registrar()->handlePageSingle(new \WP_REST_Request(['path' => 'about//team']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_invalid_path', $result->code);
        self::assertSame(400, $result->data['status']);
    }

    /**
     * The cursor stays opaque: no pattern, format or enum is declared for it, so nothing generic
     * can start rejecting or rewriting a token the encoder minted (FLAG-RESTARGDRIFT-1 D-3).
     */
    public function test_the_cursor_parameter_declares_no_generic_constraint(): void
    {
        foreach (['hsp/v1/posts', 'hsp/v1/pages', 'hsp/v1/categories', 'hsp/v1/media', 'hsp/v1/tags'] as $route) {
            $cursor = $this->registrations[$route]['cursor'];

            self::assertSame('string', $cursor['type']);
            foreach (['pattern', 'format', 'enum', 'minimum', 'maximum'] as $keyword) {
                self::assertArrayNotHasKey(
                    $keyword,
                    $cursor,
                    "The cursor must stay opaque: {$route} declares a {$keyword} for it.",
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Hierarchical page path — published grammar vs runtime acceptance
    // -------------------------------------------------------------------------

    /** @return iterable<string,array{0:string,1:bool}> */
    public static function pagePathProvider(): iterable
    {
        yield 'one segment'      => ['about', true];
        yield 'two segments'     => ['about/team', true];
        yield 'three segments'   => ['about/team/history', true];
        yield 'underscores and digits' => ['a_b/c-d/e9', true];

        // MEASURED, not assumed: an empty INTERNAL segment is malformed, but SURROUNDING
        // separators are trimmed before the split, so `about//` normalises to `about` and is
        // covered by the tolerated-normalisation test below rather than here.
        yield 'empty internal segment'     => ['about//team', false];
        yield 'empty segment mid-path'     => ['a//b/c', false];
        yield 'nothing but separators'     => ['//', false];
    }

    /**
     * The published pattern and the runtime agree about page-path STRUCTURE.
     *
     * The descriptor used to publish the route's own capture class, `^[a-z0-9_/-]+$`, which
     * describes `about//team` as a valid page path while the handler has always refused it — the
     * schema said one thing and the operation did another (FLAG-RESTARGDRIFT-1 parity correction).
     *
     * Both halves are asserted from the same fixture: the value is checked against the pattern the
     * DESCRIPTOR publishes, and against what the REAL handler does with it.
     */
    #[DataProvider('pagePathProvider')]
    public function test_page_path_structure_agrees_between_schema_and_runtime(
        string $path,
        bool $wellFormed
    ): void {
        $pattern = $this->publishedPagePathPattern();

        self::assertSame(
            $wellFormed ? 1 : 0,
            preg_match('#' . $pattern . '#u', $path),
            "'{$path}' must be schema-" . ($wellFormed ? 'VALID' : 'INVALID') . " under {$pattern}.",
        );

        $result = $this->registrar()->handlePageSingle(new \WP_REST_Request(['path' => $path]));

        if ($wellFormed) {
            // Structurally valid: it reaches the lookup. The fake provider has no such page, so a
            // 404 is the CORRECT outcome here — what matters is that it is not a 400.
            self::assertNotInstanceOf(
                \WP_Error::class,
                $result instanceof \WP_Error && $result->code === 'hsp_invalid_path' ? $result : null,
                "'{$path}' is a well-formed page path and must not be rejected as malformed.",
            );

            return;
        }

        self::assertInstanceOf(\WP_Error::class, $result, "'{$path}' must be rejected.");
        self::assertSame('hsp_invalid_path', $result->code);
        self::assertSame(400, $result->data['status']);
    }

    /**
     * The normalisation the runtime performs and the contract documents, pinned so a future
     * tightening of the published pattern cannot silently break addresses that work today.
     *
     * MEASURED against the deployed site, not inferred: WordPress matches routes with the `/i`
     * flag (`@^…$@i`), so mixed case reaches `{path}` and each segment is lower-cased by
     * `sanitize_title()`; and surrounding slashes reach `{path}` too, where `sanitizePath()` trims
     * them. `/pages/` with nothing after it is served by the LISTING route, so an empty path never
     * arrives here at all.
     */
    public function test_surrounding_slashes_and_mixed_case_remain_addressable(): void
    {
        foreach (['about/', '/about', '/about/', 'about//', 'About/Team', 'ABOUT'] as $tolerated) {
            $result = $this->registrar()->handlePageSingle(new \WP_REST_Request(['path' => $tolerated]));

            $isMalformed = $result instanceof \WP_Error && $result->code === 'hsp_invalid_path';
            self::assertFalse(
                $isMalformed,
                "'{$tolerated}' is normalised by the runtime today and must keep resolving; the "
                . 'published pattern describes the canonical form, it does not forbid these.',
            );
        }
    }

    /**
     * The runtime rule is stronger than the published regex, and stays that way.
     *
     * Only the STRUCTURAL half is asserted here. The other half — a segment that survives the
     * character class but sanitises away to nothing, e.g. `-` — depends on real
     * `sanitize_title()`, which the unit bootstrap's stub deliberately does not reproduce; it is
     * verified live instead (`/pages/-` → 400 `hsp_invalid_path`). That gap is exactly why the
     * published pattern is not asked to imitate `sanitize_title()`.
     */
    public function test_segment_sanitization_remains_authoritative(): void
    {
        foreach (['/', '//', 'a//b', 'about//team'] as $malformed) {
            $result = $this->registrar()->handlePageSingle(new \WP_REST_Request(['path' => $malformed]));

            self::assertInstanceOf(\WP_Error::class, $result, "'{$malformed}' must be rejected.");
            self::assertSame('hsp_invalid_path', $result->code);
            self::assertSame(400, $result->data['status']);
        }
    }

    /**
     * The page `path` arg must not declare the pattern, measured consequence and all.
     *
     * It is the one path arg with no `sanitize_callback`, so WordPress installs its validating
     * default and a `pattern` there is natively ENFORCED: declaring one turned `/pages/My-Account`
     * into a 400 and would answer `rest_invalid_param` where the module answers the stable
     * `hsp_invalid_path`.
     */
    public function test_the_page_path_arg_declares_no_natively_enforced_pattern(): void
    {
        $spec = $this->registrations['hsp/v1/pages/{path}']['path'];

        self::assertArrayNotHasKey('pattern', $spec);
        self::assertArrayNotHasKey('sanitize_callback', $spec);
        self::assertTrue($spec['required']);
        self::assertSame('string', $spec['type']);
    }
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     */
    private function assertRequest(
        string $route,
        array $params,
        bool $accepted,
        string $expectedCode
    ): void {
        self::assertArrayHasKey($route, $this->registrations, "No live registration for {$route}.");

        [$actuallyAccepted, $topCode, $detailCode] = WpRestArgDispatch::evaluate(
            $this->registrations[$route],
            $params
        );

        $described = $route . '?' . http_build_query($params);

        if ($accepted) {
            self::assertTrue(
                $actuallyAccepted,
                "{$described} is valid under the published contract and must be accepted "
                . "(rejected as {$topCode}/{$detailCode}).",
            );

            return;
        }

        self::assertFalse($actuallyAccepted, "{$described} is outside the published contract and must be rejected.");
        self::assertSame(
            'rest_invalid_param',
            $topCode,
            "{$described} must be refused through WordPress's own request-validation envelope.",
        );
        self::assertSame($expectedCode, $detailCode, "{$described} was rejected for the wrong reason.");
    }

    /**
     * The `pattern` the DESCRIPTOR publishes for the page path — read from the registry, so
     * this test cannot drift from the document consumers actually read.
     */
    private function publishedPagePathPattern(): string
    {
        foreach ($this->registryDescriptors() as $descriptor) {
            if ($descriptor->route !== '/pages/{path}') {
                continue;
            }
            foreach ($descriptor->parameters as $parameter) {
                if ($parameter->name === 'path' && $parameter->pattern !== null) {
                    return $parameter->pattern;
                }
            }
        }

        self::fail('The page path parameter publishes no pattern.');
    }

    private function registrar(): ContentRestRegistrar
    {
        /** @var ContentRestRegistrar $registrar */
        $registrar = $this->bootContainer()->get(ContentRestRegistrar::class);

        return $registrar;
    }
}
