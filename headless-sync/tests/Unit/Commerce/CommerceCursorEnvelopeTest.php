<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Commerce;

use HSP\Core\Contracts\ResourceInterface;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Resources\AttributeResource;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Content\Resources\PostResource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The shared cursor-pagination envelope, asserted across every Commerce list Resource
 * (FLAG-COMMENVELOPE-1).
 *
 * All four Commerce Resources used to publish `{data, meta: {next_cursor}}`, a Commerce-only
 * envelope no contract ever defined. The approved shape — stated by `ResourceInterface`, encoded
 * by `SchemaObject::asCursorPage()`, generated into `openapi.json`, and already served by the four
 * Content Resources — is:
 *
 *     { "data": [...], "next_cursor": "opaque-or-null" }
 *
 * A generated consumer type reading `next_cursor` off a `/products` response got `undefined`:
 * the runtime and the published contract disagreed. These tests pin the agreement so the two
 * cannot drift apart again.
 */
final class CommerceCursorEnvelopeTest extends TestCase
{
    /**
     * Every Commerce list Resource, with the row fixture its toArray() needs.
     *
     * @return array<string,array{0:ResourceInterface,1:array<string,mixed>}>
     */
    public static function commerceListResources(): array
    {
        return [
            'products'           => [new ProductResource(),   ['slug' => 'hat', 'source_product_id' => 1]],
            'product-categories' => [new TermResource(),      ['slug' => 'hats', 'source_term_id' => 2]],
            'product-attributes' => [new AttributeResource(), ['slug' => 'pa_size']],
            'variations'         => [new VariationResource(), ['source_variation_id' => 3]],
        ];
    }

    /**
     * A NON-TERMINAL page carries the opaque cursor at the top level — and carries it through
     * UNCHANGED. The Resource is a serializer (Doc 9 §11): it must not recompute, re-encode or
     * otherwise touch a token whose structure is a private contract of the Query Provider.
     *
     * @param array<string,mixed> $row
     */
    #[DataProvider('commerceListResources')]
    public function test_non_terminal_page_publishes_the_opaque_cursor_at_the_top_level(
        ResourceInterface $resource,
        array $row
    ): void {
        // Deliberately awkward: base64url payload with padding and a '-' — a Resource that
        // re-encoded or sanitized the token would not return it byte-identical.
        $token = 'eyJzIjoiMjAyNi0wOS0xNCIsImlkIjoiYWJjIn0-';

        $envelope = $resource->toCollection([$row], $token);

        self::assertSame($token, $envelope['next_cursor'], 'the cursor must pass through unchanged');
        self::assertIsString($envelope['next_cursor']);
        self::assertArrayNotHasKey('meta', $envelope, 'no Commerce-only meta envelope');
        self::assertSame(['data', 'next_cursor'], array_keys($envelope));
    }

    /**
     * A TERMINAL page publishes `next_cursor: null` — the key is present and null, never absent
     * and never an empty string, so a consumer's loop terminates on one deterministic check.
     *
     * @param array<string,mixed> $row
     */
    #[DataProvider('commerceListResources')]
    public function test_terminal_page_publishes_a_null_cursor(
        ResourceInterface $resource,
        array $row
    ): void {
        $envelope = $resource->toCollection([$row], null);

        self::assertArrayHasKey('next_cursor', $envelope);
        self::assertNull($envelope['next_cursor']);
        self::assertArrayNotHasKey('meta', $envelope);
    }

    /**
     * `data` must serialize as a JSON ARRAY, which is what the schema promises (`type: array`).
     * A non-sequentially-keyed rows array would otherwise encode as a JSON object.
     *
     * @param array<string,mixed> $row
     */
    #[DataProvider('commerceListResources')]
    public function test_data_serializes_as_a_json_array_even_from_non_sequential_rows(
        ResourceInterface $resource,
        array $row
    ): void {
        // CursorPage::$rows is array<int,...>, not list<...> — the keys are not guaranteed dense.
        $envelope = $resource->toCollection([5 => $row, 9 => $row], null);

        self::assertSame([0, 1], array_keys($envelope['data']));
        $json = json_encode($envelope, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"data":[', $json, 'data must encode as a JSON array');
    }

    // -------------------------------------------------------------------------
    // Runtime ⇄ generated OpenAPI agreement
    // -------------------------------------------------------------------------

    /**
     * The published descriptor and the runtime envelope must describe the SAME contract — the
     * whole point of the fix. Asserted for every paginated Commerce endpoint.
     */
    public function test_every_paginated_commerce_endpoint_agrees_with_its_runtime_envelope(): void
    {
        $runtimeKeys = array_keys((new ProductResource())->toCollection([], null));

        $paginated = 0;
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            if (! $endpoint->paginated) {
                continue;
            }
            $paginated++;

            self::assertNotNull($endpoint->responseSchema, "{$endpoint->route} needs a schema.");
            $schema = $endpoint->responseSchema->schema;

            self::assertSame(
                $runtimeKeys,
                array_keys($schema['properties']),
                "{$endpoint->route}: published envelope keys must match the runtime envelope.",
            );
            self::assertSame(['data', 'next_cursor'], $schema['required'], $endpoint->route);
            self::assertSame('array', $schema['properties']['data']['type'], $endpoint->route);
            self::assertSame(
                ['string', 'null'],
                $schema['properties']['next_cursor']['type'],
                "{$endpoint->route}: the cursor is a nullable string — null on the last page.",
            );
        }

        self::assertSame(5, $paginated, 'All five Commerce list endpoints must be covered.');
    }

    /**
     * The Content envelope is the reference shape and must be untouched by this fix — Commerce
     * moved TO the existing contract, the contract did not move to meet Commerce.
     */
    public function test_content_pagination_is_unchanged_and_identical_to_commerce(): void
    {
        $content  = (new PostResource())->toCollection([], 'tok');
        $commerce = (new ProductResource())->toCollection([], 'tok');

        self::assertSame(['data', 'next_cursor'], array_keys($content));
        self::assertSame(array_keys($content), array_keys($commerce), 'one envelope, both modules');
        self::assertSame($content['next_cursor'], $commerce['next_cursor']);
    }

    /** Belt and braces: no Resource anywhere may reintroduce a nested cursor. */
    public function test_no_resource_publishes_a_nested_cursor(): void
    {
        $resources = [
            new ProductResource(), new TermResource(), new AttributeResource(),
            new VariationResource(), new PostResource(),
        ];

        foreach ($resources as $resource) {
            $envelope = $resource->toCollection([], 'tok');
            self::assertArrayNotHasKey('meta', $envelope, $resource::class);
            self::assertArrayHasKey('next_cursor', $envelope, $resource::class);
        }
    }

}
