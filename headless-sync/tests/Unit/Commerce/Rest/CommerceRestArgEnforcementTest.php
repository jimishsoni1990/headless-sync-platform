<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce\Rest;

use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrar;
use HSP\Tests\Support\LiveHspRouteIndex;
use HSP\Tests\Support\WpRestArgDispatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Commerce request constraints, as REQUESTS rather than as metadata (FLAG-RESTARGDRIFT-1 §24).
 *
 * Commerce's page size is audited SEPARATELY from Content's and does not inherit its numbers: the
 * product listing publishes 1..100 and the taxonomy, attribute and variation listings publish
 * 1..200, each matching its own query provider's ceiling. A single platform-wide maximum would
 * misdescribe four endpoints to fix one.
 *
 * Args come from the LIVE route index (the real registrar, via LiveHspRouteIndex) and are
 * evaluated by WpRestArgDispatch, which implements WordPress 7.1's own
 * has_valid_params() → sanitize_params() gate. The CCF-003 module validators — `CursorToken` and
 * `Money` — are exercised through the REAL handlers, proving the generic fix neither replaced nor
 * bypassed them.
 */
final class CommerceRestArgEnforcementTest extends TestCase
{
    use LiveHspRouteIndex;

    /** The four listings whose published page size is 1..200. */
    private const TERM_LISTINGS = [
        'hsp/v1/product-categories',
        'hsp/v1/product-attributes',
        'hsp/v1/product-attributes/{taxonomy}/terms',
        'hsp/v1/products/{slug}/variations',
    ];

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
    // limit — per-route maxima, one shared minimum
    // -------------------------------------------------------------------------

    /** @return iterable<string,array{0:mixed,1:bool,2:string}> */
    public static function productLimitProvider(): iterable
    {
        yield 'lower bound 1'       => ['1', true, ''];
        yield 'upper bound 100'     => ['100', true, ''];
        yield 'mid range'           => ['20', true, ''];

        yield 'zero is not a page'  => ['0', false, 'rest_out_of_bounds'];
        yield 'negative'            => ['-5', false, 'rest_out_of_bounds'];
        yield 'above maximum 101'   => ['101', false, 'rest_out_of_bounds'];
        yield 'above maximum 500'   => ['500', false, 'rest_out_of_bounds'];
        // 200 is the OTHER listings' maximum, so this pins that the two are not confused.
        yield 'the term maximum'    => ['200', false, 'rest_out_of_bounds'];
        yield 'not a number'        => ['abc', false, 'rest_invalid_type'];
        yield 'not an integer'      => ['2.5', false, 'rest_invalid_type'];
        yield 'empty value'         => ['', false, 'rest_invalid_type'];
    }

    #[DataProvider('productLimitProvider')]
    public function test_product_limit_is_enforced_as_1_to_100(
        mixed $value,
        bool $accepted,
        string $expectedCode
    ): void {
        $this->assertRequest('hsp/v1/products', ['limit' => $value], $accepted, $expectedCode);
    }

    /** @return iterable<string,array{0:mixed,1:bool,2:string}> */
    public static function termLimitProvider(): iterable
    {
        yield 'lower bound 1'      => ['1', true, ''];
        yield 'upper bound 200'    => ['200', true, ''];
        // Valid here and INVALID on /products — the per-route maxima are real, not decorative.
        yield 'above the product maximum but within this one' => ['150', true, ''];

        yield 'zero is not a page' => ['0', false, 'rest_out_of_bounds'];
        yield 'negative'           => ['-5', false, 'rest_out_of_bounds'];
        yield 'above maximum 201'  => ['201', false, 'rest_out_of_bounds'];
        yield 'not a number'       => ['abc', false, 'rest_invalid_type'];
        yield 'empty value'        => ['', false, 'rest_invalid_type'];
    }

    #[DataProvider('termLimitProvider')]
    public function test_taxonomy_attribute_and_variation_limits_are_enforced_as_1_to_200(
        mixed $value,
        bool $accepted,
        string $expectedCode
    ): void {
        foreach (self::TERM_LISTINGS as $route) {
            $this->assertRequest($route, $this->withPathParams($route, ['limit' => $value]), $accepted, $expectedCode);
        }
    }

    /**
     * `absint` used to make two different invalid requests LOOK like successful ones:
     * `?limit=0` produced `LIMIT 0` and answered 200 with an empty page, and `?limit=-5` was
     * turned into 5 — a page size the consumer never asked for. Both are rejected now, and the
     * point is that they are rejected rather than reinterpreted.
     */
    public function test_absint_can_no_longer_reinterpret_an_invalid_page_size(): void
    {
        self::assertSame(0, absint('0'));
        self::assertSame(5, absint('-5'), 'absint is still what sanitises a VALID value.');

        foreach (['0', '-5', 'abc'] as $invalid) {
            [$accepted] = WpRestArgDispatch::evaluate(
                $this->registrations['hsp/v1/products'],
                ['limit' => $invalid]
            );
            self::assertFalse($accepted, "limit={$invalid} must be rejected before absint runs.");
        }
    }

    // -------------------------------------------------------------------------
    // parent — 0 is a real value, so the floor is 0
    // -------------------------------------------------------------------------

    /** @return iterable<string,array{0:mixed,1:bool,2:string}> */
    public static function parentProvider(): iterable
    {
        yield 'top level'     => ['0', true, ''];
        yield 'a term id'     => ['42', true, ''];

        yield 'negative'      => ['-1', false, 'rest_out_of_bounds'];
        yield 'negative many' => ['-5', false, 'rest_out_of_bounds'];
        yield 'not a number'  => ['abc', false, 'rest_invalid_type'];
        yield 'not an integer' => ['1.5', false, 'rest_invalid_type'];
        yield 'empty value'   => ['', false, 'rest_invalid_type'];
    }

    #[DataProvider('parentProvider')]
    public function test_parent_is_enforced_as_a_non_negative_integer(
        mixed $value,
        bool $accepted,
        string $expectedCode
    ): void {
        $this->assertRequest('hsp/v1/product-categories', ['parent' => $value], $accepted, $expectedCode);
    }

    /**
     * The specific silent coercion this closed: `?parent=abc` became `parent=0` through `absint`,
     * so a request naming a category returned the TOP-LEVEL listing and looked like it worked.
     */
    public function test_a_non_numeric_parent_no_longer_becomes_the_top_level_listing(): void
    {
        self::assertSame(0, absint('abc'), 'The coercion itself is unchanged; it just cannot run first.');

        [$accepted, $topCode] = WpRestArgDispatch::evaluate(
            $this->registrations['hsp/v1/product-categories'],
            ['parent' => 'abc']
        );

        self::assertFalse($accepted);
        self::assertSame('rest_invalid_param', $topCode);
    }

    // -------------------------------------------------------------------------
    // type — the AG-13 supported set, enforced
    // -------------------------------------------------------------------------

    /** @return iterable<string,array{0:string,1:bool,2:string}> */
    public static function productTypeProvider(): iterable
    {
        yield 'simple'        => ['simple', true, ''];
        yield 'variable'      => ['variable', true, ''];

        yield 'grouped'       => ['grouped', false, 'rest_not_in_enum'];
        yield 'external'      => ['external', false, 'rest_not_in_enum'];
        yield 'unknown'       => ['bogus', false, 'rest_not_in_enum'];
        yield 'empty value'   => ['', false, 'rest_not_in_enum'];
    }

    #[DataProvider('productTypeProvider')]
    public function test_product_type_is_enforced_against_the_supported_set(
        string $value,
        bool $accepted,
        string $expectedCode
    ): void {
        $this->assertRequest('hsp/v1/products', ['type' => $value], $accepted, $expectedCode);
    }

    /**
     * The enum is the module's own constant, not a copy of it.
     *
     * A second literal list would be free to drift from AG-13's supported set the moment either
     * is edited — the same duplication that produced this flag one level up.
     */
    public function test_the_type_enum_is_the_module_owned_supported_set(): void
    {
        self::assertSame(
            ProductScope::SUPPORTED_TYPES,
            $this->registrations['hsp/v1/products']['type']['enum'],
        );
    }

    /**
     * An unsupported product type is a REQUEST error, and must not be confused with AG-13's rule
     * that an unsupported product in the SOURCE is normal out-of-scope data rather than a
     * failure. Rejecting `?type=grouped` says the filter cannot be served; it does not make a
     * grouped product in WooCommerce into an error anywhere in the pipeline.
     */
    public function test_rejecting_an_unsupported_type_filter_is_a_request_error_only(): void
    {
        self::assertFalse(ProductScope::isSupportedType('grouped'));
        self::assertTrue(ProductScope::isSupportedType('simple'));

        $this->assertRequest('hsp/v1/products', ['type' => 'grouped'], false, 'rest_not_in_enum');
    }

    // -------------------------------------------------------------------------
    // booleans — WordPress's closed lexical set
    // -------------------------------------------------------------------------

    /** @return iterable<string,array{0:mixed,1:bool,2:string}> */
    public static function booleanProvider(): iterable
    {
        yield 'true'        => ['true', true, ''];
        yield 'false'       => ['false', true, ''];
        yield 'one'         => ['1', true, ''];
        yield 'zero'        => ['0', true, ''];
        yield 'TRUE upper'  => ['TRUE', true, ''];

        yield 'yes'         => ['yes', false, 'rest_invalid_type'];
        yield 'on'          => ['on', false, 'rest_invalid_type'];
        yield 'abc'         => ['abc', false, 'rest_invalid_type'];
        yield 'empty value' => ['', false, 'rest_invalid_type'];
    }

    /**
     * These two args were the only ones in the platform already being validated — they are the
     * only ones registered without a `sanitize_callback`, so WordPress installed its validating
     * default for them. Their behaviour is UNCHANGED; the callback is now named explicitly so a
     * sanitizer added later cannot silently switch it off again.
     */
    #[DataProvider('booleanProvider')]
    public function test_boolean_filters_accept_only_the_wordpress_boolean_set(
        mixed $value,
        bool $accepted,
        string $expectedCode
    ): void {
        foreach (['featured', 'in_stock'] as $name) {
            $this->assertRequest('hsp/v1/products', [$name => $value], $accepted, $expectedCode);
        }
    }

    // -------------------------------------------------------------------------
    // CCF-003 validators are untouched by the generic fix
    // -------------------------------------------------------------------------

    public function test_invalid_cursor_still_returns_its_own_400(): void
    {
        $result = $this->registrar()->handleProductListing(new \WP_REST_Request(['cursor' => 'zzzz']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_invalid_cursor', $result->code);
        self::assertSame(400, $result->data['status']);
    }

    public function test_invalid_price_still_returns_its_own_400(): void
    {
        foreach (['min_price', 'max_price'] as $filter) {
            $result = $this->registrar()->handleProductListing(new \WP_REST_Request([$filter => 'abc']));

            self::assertInstanceOf(\WP_Error::class, $result, "{$filter}=abc must be rejected.");
            self::assertSame('hsp_invalid_filter', $result->code);
            self::assertSame(400, $result->data['status']);
        }
    }

    /**
     * Prices keep the exact-decimal contract and gain NO generic constraint: a `pattern` here
     * would either misdescribe Money's grammar or pre-empt it with `rest_invalid_param`, and the
     * accepted-value set must not move (FLAG-RESTARGDRIFT-1 D-2).
     */
    public function test_price_filters_declare_no_generic_constraint(): void
    {
        foreach (['min_price', 'max_price'] as $filter) {
            $spec = $this->registrations['hsp/v1/products'][$filter];

            self::assertSame('string', $spec['type']);
            foreach (['pattern', 'format', 'enum', 'minimum', 'maximum'] as $keyword) {
                self::assertArrayNotHasKey($keyword, $spec, "{$filter} must keep Money as its only validator.");
            }
        }

        // And an exact decimal still passes through unchanged.
        $result = $this->registrar()->handleProductListing(new \WP_REST_Request(['min_price' => '10.50']));
        self::assertInstanceOf(\WP_REST_Response::class, $result);
    }

    /** Cursors stay opaque — nothing generic may reject or rewrite a token the encoder minted. */
    public function test_cursor_declares_no_generic_constraint_on_any_commerce_listing(): void
    {
        foreach (['hsp/v1/products', ...self::TERM_LISTINGS] as $route) {
            $spec = $this->registrations[$route]['cursor'];

            self::assertSame('string', $spec['type']);
            foreach (['pattern', 'format', 'enum', 'minimum', 'maximum'] as $keyword) {
                self::assertArrayNotHasKey($keyword, $spec, "{$route} must keep its cursor opaque.");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Path parameters a nested route structurally requires, so the required-parameter check is
     * satisfied and the assertion is about the query parameter under test.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function withPathParams(string $route, array $params): array
    {
        if (str_contains($route, '{taxonomy}')) {
            $params['taxonomy'] = 'pa_colour';
        }
        if (str_contains($route, '{slug}')) {
            $params['slug'] = 'a-product';
        }

        return $params;
    }

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

    private function registrar(): CommerceRestRegistrar
    {
        /** @var CommerceRestRegistrar $registrar */
        $registrar = $this->bootContainer()->get(CommerceRestRegistrar::class);

        return $registrar;
    }
}
