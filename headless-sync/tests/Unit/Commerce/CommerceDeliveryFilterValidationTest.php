<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\QueryFilterInterface;
use HSP\Core\Contracts\QueryProviderInterface;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Rest\DeliveryErrorBoundary;
use HSP\Modules\Commerce\Queries\ProductFilterSet;
use HSP\Modules\Commerce\Resources\AttributeResource;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Commerce\Rest\CommerceRestRegistrar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Commerce request validation at the REST trust boundary (CCF-003).
 *
 * Two live defects are pinned here, both of which ended as an unauthenticated HTML 500 carrying a
 * stack trace, the SQL and absolute filesystem paths:
 *
 *   `?min_price=abc`        — the raw string was bound to a NUMERIC parameter;
 *   `?cursor=<tampered>`    — a decoded-but-meaningless payload was bound to TIMESTAMPTZ.
 *
 * And one behavioural defect that was not a crash at all: `?cursor=<garbage>` returned 200 with
 * page 1, so a consumer paging forward silently received rows it had already seen.
 */
final class CommerceDeliveryFilterValidationTest extends TestCase
{
    private SpyProductQueryProvider $products;

    protected function setUp(): void
    {
        $this->products = new SpyProductQueryProvider();
    }

    private function registrar(): CommerceRestRegistrar
    {
        $empty = new SpyProductQueryProvider();

        return new CommerceRestRegistrar(
            $this->products,
            new ProductResource(),
            $empty,
            new TermResource(),
            $empty,
            new AttributeResource(),
            static fn (string $taxonomy): QueryProviderInterface => $empty,
            $empty,
            new VariationResource(),
            new DeliveryErrorBoundary(new StructuredLogger(static function (string $line): void {
            })),
        );
    }

    // -------------------------------------------------------------------------
    // Price bounds
    // -------------------------------------------------------------------------

    /** @return array<string,array{0:string,1:string}> */
    public static function invalidPrices(): array
    {
        return [
            'min_price letters'   => ['min_price', 'abc'],
            'max_price letters'   => ['max_price', 'abc'],
            'min_price NaN'       => ['min_price', 'NaN'],
            'min_price INF'       => ['min_price', 'INF'],
            'max_price -INF'      => ['max_price', '-INF'],
            // Scientific notation is a real PHP float literal and a real PostgreSQL numeric
            // literal, but it is NOT a form the Commerce money contract represents exactly, so it
            // is refused rather than quietly reinterpreted.
            'scientific notation' => ['min_price', '1e5'],
            'currency symbol'     => ['min_price', '£10.00'],
            'thousands separator' => ['max_price', '1,000.00'],
            'SQL payload'         => ['min_price', "10; DROP TABLE commerce.products"],
            'lone decimal point'  => ['min_price', '.'],
            'lone sign'           => ['max_price', '-'],
        ];
    }

    #[DataProvider('invalidPrices')]
    public function test_an_invalid_price_bound_is_refused_with_400(string $param, string $value): void
    {
        $result = $this->registrar()->handleProductListing(new \WP_REST_Request([$param => $value]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_invalid_filter', $result->code);
        self::assertSame(400, $result->data['status']);
        self::assertNull(
            $this->products->lastFilters,
            'The query must never run — that is the whole point: nothing reaches the NUMERIC cast.',
        );
    }

    /** @return array<string,array{0:string,1:string}> raw value → exact decimal handed to the query */
    public static function validPrices(): array
    {
        return [
            'integer'             => ['10', '10'],
            'two decimals'        => ['19.99', '19.99'],
            'trailing zeros'      => ['10.00', '10'],
            'leading zero'        => ['0.50', '0.5'],
            'no leading zero'     => ['.5', '0.5'],
            'negative'            => ['-5.25', '-5.25'],
            'signed zero'         => ['-0.00', '0'],
            // A value with more precision than a binary float can hold. If this path ever went
            // through (float) the digits below would change, which is exactly what Requirement C
            // forbids for money.
            'beyond float precision' => ['12345678901234567.89', '12345678901234567.89'],
        ];
    }

    #[DataProvider('validPrices')]
    public function test_a_valid_price_bound_reaches_the_query_as_an_exact_decimal_string(
        string $raw,
        string $expected
    ): void {
        $result = $this->registrar()->handleProductListing(new \WP_REST_Request(['min_price' => $raw]));

        self::assertNotInstanceOf(\WP_Error::class, $result);
        self::assertInstanceOf(ProductFilterSet::class, $this->products->lastFilters);
        self::assertSame($expected, $this->products->lastFilters->minPrice);
        self::assertIsString(
            $this->products->lastFilters->minPrice,
            'Money bounds stay strings end to end — never PHP floats (DECISION AG Requirement C).',
        );
    }

    /**
     * Validation rejects unrepresentable input; it does not add filter rules. An inverted range is
     * a legitimate query that simply matches nothing, and the approved Commerce filter contract
     * contains no min ≤ max constraint.
     */
    public function test_an_inverted_price_range_is_not_a_validation_error(): void
    {
        $result = $this->registrar()->handleProductListing(
            new \WP_REST_Request(['min_price' => '100', 'max_price' => '1'])
        );

        self::assertNotInstanceOf(\WP_Error::class, $result);
        self::assertSame('100', $this->products->lastFilters?->minPrice);
        self::assertSame('1', $this->products->lastFilters?->maxPrice);
    }

    // -------------------------------------------------------------------------
    // Cursors
    // -------------------------------------------------------------------------

    /** @return array<string,array{0:string}> */
    public static function invalidCursors(): array
    {
        $encode = static fn (array $payload): string => rtrim(
            strtr(base64_encode((string) json_encode($payload)), '+/', '-_'),
            '='
        );
        $uuid = '01a09ec2-5104-7357-9806-ba7d8d8f9c3c';

        return [
            'not base64url'       => ['zzz@@'],
            'valid base64url, not JSON' => [rtrim(strtr(base64_encode('nonsense'), '+/', '-_'), '=')],
            'missing id'          => [$encode(['s' => '2026-09-08 09:21:28+00'])],
            'missing s'           => [$encode(['id' => $uuid])],
            'id is not a UUID'    => [$encode(['s' => '2026-09-08 09:21:28+00', 'id' => 'x'])],
            // The exact token that produced the HTML 500 on the live site.
            'tampered timestamp'  => [$encode(['s' => 'notadate', 'id' => $uuid])],
            // A variations cursor replayed against the products listing.
            'wrong resource cursor' => [$encode(['o' => 1, 'id' => $uuid])],
        ];
    }

    #[DataProvider('invalidCursors')]
    public function test_an_invalid_cursor_is_refused_with_400_not_page_one(string $cursor): void
    {
        $result = $this->registrar()->handleProductListing(new \WP_REST_Request(['cursor' => $cursor]));

        self::assertInstanceOf(
            \WP_Error::class,
            $result,
            'An unusable cursor must never be downgraded to "start from the beginning".',
        );
        self::assertSame('hsp_invalid_cursor', $result->code);
        self::assertSame(400, $result->data['status']);
        self::assertNull($this->products->lastFilters, 'Nothing reaches the TIMESTAMPTZ cast.');
    }

    public function test_a_well_formed_cursor_is_still_accepted_and_passed_through_opaquely(): void
    {
        $cursor = rtrim(strtr(base64_encode((string) json_encode([
            's'  => '2026-09-08 09:21:28+00',
            'id' => '01a09ec2-5104-7357-9806-ba7d8d8f9c3c',
        ])), '+/', '-_'), '=');

        $result = $this->registrar()->handleProductListing(new \WP_REST_Request(['cursor' => $cursor]));

        self::assertNotInstanceOf(\WP_Error::class, $result);
        self::assertSame(
            $cursor,
            $this->products->lastFilters?->cursor,
            'The provider still receives the opaque token, not a decoded payload.',
        );
    }

    /** Variations sort on menu_order, so their cursor carries `o` — and is validated as such. */
    public function test_the_variation_listing_validates_its_own_cursor_shape(): void
    {
        $registrar = $this->registrar();

        $bad = rtrim(strtr(base64_encode((string) json_encode(['o' => 'abc', 'id' => 'x'])), '+/', '-_'), '=');

        $result = $registrar->handleVariationListing(
            new \WP_REST_Request(['slug' => 'some-product', 'cursor' => $bad])
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_invalid_cursor', $result->code);
        self::assertSame(400, $result->data['status']);
    }

    /**
     * Order matters: the cursor is judged before the parent lookup, so a bad cursor on a missing
     * product is a 400 about the cursor rather than a 404 that hides it.
     */
    public function test_a_bad_cursor_is_reported_before_the_parent_lookup(): void
    {
        $result = $this->registrar()->handleVariationListing(
            new \WP_REST_Request(['slug' => 'no-such-product', 'cursor' => 'zzz@@'])
        );

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('hsp_invalid_cursor', $result->code);
    }
}

/** Captures the filter set a handler builds, and returns nothing. */
final class SpyProductQueryProvider implements QueryProviderInterface
{
    public ?QueryFilterInterface $lastFilters = null;

    public function list(QueryFilterInterface $filters): CursorPage
    {
        $this->lastFilters = $filters;

        return new CursorPage([], null);
    }

    public function findBySlug(string $slug): ?array
    {
        return null;
    }
}
