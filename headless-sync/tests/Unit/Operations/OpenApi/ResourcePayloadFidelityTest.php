<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\OpenApi;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Contracts\ResourceInterface;
use HSP\Modules\Commerce\Operations\CommerceEndpointProvider;
use HSP\Modules\Commerce\Resources\ProductResource;
use HSP\Modules\Commerce\Resources\TermResource;
use HSP\Modules\Commerce\Resources\VariationResource;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Resources\MediaResource;
use HSP\Modules\Content\Resources\PageResource;
use HSP\Modules\Content\Resources\PostResource;
use PHPUnit\Framework\TestCase;

/**
 * Runtime ⇄ generated-contract fidelity, asserted on the JSON that actually goes over the wire.
 *
 * The three live-site failures this file pins were invisible to every existing test because they
 * only appear after json_encode, or only on rows where an optional column is null:
 *
 *   - `"meta": []` where the schema says object (PHP cannot distinguish an empty list from an
 *     empty map);
 *   - `"sku": null` where the schema said `string`;
 *   - `"parent": null` where the schema said `integer`.
 *
 * Each case is checked by decoding the Resource's own output as JSON and comparing it against the
 * published descriptor — no reflection, no runtime schema inference (ADR-055 (a)).
 */
final class ResourcePayloadFidelityTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Empty maps publish {} — across every resource that exposes one
    // -------------------------------------------------------------------------

    /**
     * Every map-typed public field publishes `{}` when empty. Driven from an EMPTY row, which is
     * exactly the shape that produced `"meta": []` on the live site.
     */
    public function test_every_map_field_publishes_an_object_when_empty(): void
    {
        $cases = [
            'post.meta'         => [new PostResource(),      [], 'meta'],
            'post.fm.sizes'     => [new PostResource(),      ['fm_url' => 'https://x/a.jpg'], 'featured_media.sizes'],
            'page.meta'         => [new PageResource(),      [], 'meta'],
            'page.fm.sizes'     => [new PageResource(),      ['fm_url' => 'https://x/a.jpg'], 'featured_media.sizes'],
            'media.meta'        => [new MediaResource(),     [], 'meta'],
            'media.sizes'       => [new MediaResource(),     [], 'sizes'],
            'product.meta'      => [new ProductResource(),   [], 'meta'],
            'variation.attrs'   => [new VariationResource(), [], 'attributes'],
        ];

        foreach ($cases as $label => [$resource, $row, $path]) {
            $published = json_decode(
                json_encode($this->publish($resource, $row), JSON_THROW_ON_ERROR),
                associative: false,
                flags: JSON_THROW_ON_ERROR,
            );

            $value = $published;
            foreach (explode('.', $path) as $segment) {
                $value = $value->{$segment};
            }

            self::assertIsObject($value, "{$label} must publish a JSON object when empty, not []");
        }
    }

    /** Populated maps keep their keys — the fix must not flatten real data. */
    public function test_a_populated_map_still_publishes_its_entries(): void
    {
        $json = json_encode(
            (new PostResource())->toArray(['slug' => 's', 'title' => 't', 'content' => 'c',
                'status' => 'publish', 'meta_jsonb' => '{"seo_title":"Hello"}']),
            JSON_THROW_ON_ERROR,
        );

        self::assertStringContainsString('"meta":{"seo_title":"Hello"}', $json);
    }

    /** LIST fields must stay `[]` — the distinction PHP's array type erases. */
    public function test_list_fields_still_publish_arrays_when_empty(): void
    {
        $post = json_encode((new PostResource())->toArray(
            ['slug' => 's', 'title' => 't', 'content' => 'c', 'status' => 'publish'],
        ), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"tags":[]', $post);

        $product = json_encode((new ProductResource())->toArray([]), JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"gallery_ids":[]', $product);
    }

    /** The cursor envelope's `data` is a list and must never become an object. */
    public function test_an_empty_collection_publishes_an_empty_data_array(): void
    {
        $json = json_encode((new ProductResource())->toCollection([], null), JSON_THROW_ON_ERROR);

        self::assertSame('{"data":[],"next_cursor":null}', $json);
    }

    // -------------------------------------------------------------------------
    // Nullable scalars — runtime null must be permitted by the published schema
    // -------------------------------------------------------------------------

    /** A product with no SKU and no publication date: both live-observed nulls. */
    public function test_product_null_sku_and_published_at_match_the_published_schema(): void
    {
        $published = (new ProductResource())->toArray(['slug' => 'p', 'source_product_id' => 1]);
        $props     = $this->itemProperties($this->commerceByRoute()['/products/{slug}']);

        self::assertNull($published['sku']);
        self::assertSame(['string', 'null'], $props['sku']['type']);

        self::assertNull($published['published_at']);
        self::assertSame(['string', 'null'], $props['published_at']['type']);

        // updated_at is NOT NULL DEFAULT NOW() in the projection, so it stays a plain string.
        self::assertSame('string', $props['updated_at']['type']);
    }

    /** A non-null SKU is still a plain string — nullability widened, not replaced. */
    public function test_a_present_sku_is_still_published_as_a_string(): void
    {
        $published = (new ProductResource())->toArray(['sku' => 'ABC-1']);

        self::assertSame('ABC-1', $published['sku']);
    }

    /** A top-level product category publishes `parent: null` by deliberate design. */
    public function test_term_null_parent_matches_the_published_schema(): void
    {
        $topLevel = (new TermResource())->toArray(['slug' => 't', 'parent_id' => 0]);
        $child    = (new TermResource())->toArray(['slug' => 'c', 'parent_id' => 42]);
        $props    = $this->itemProperties($this->commerceByRoute()['/product-categories/{slug}']);

        self::assertNull($topLevel['parent'], 'the 0 sentinel is published as null');
        self::assertSame(42, $child['parent']);
        self::assertSame(['integer', 'null'], $props['parent']['type']);
    }

    /** A variation with no SKU. */
    public function test_variation_null_sku_matches_the_published_schema(): void
    {
        $published = (new VariationResource())->toArray(['source_variation_id' => 9]);
        $props     = $this->itemProperties($this->commerceByRoute()['/products/{slug}/variations']);

        self::assertNull($published['sku']);
        self::assertSame(['string', 'null'], $props['sku']['type']);
    }

    /**
     * Content timestamps are NOT made nullable: `content.posts/pages/media.published_at` and
     * `updated_at` are all `TIMESTAMPTZ NOT NULL`, so the contract correctly promises a string.
     * The Resource's null-on-unparseable fallback is defensive, not a contract state — widening
     * the schema for it would weaken the contract without evidence.
     */
    public function test_content_timestamps_remain_non_null_in_the_contract(): void
    {
        $props = $this->itemProperties($this->contentByRoute()['/posts/{slug}']);

        self::assertSame('string', $props['published_at']['type']);
        self::assertSame('string', $props['updated_at']['type']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function publish(ResourceInterface $resource, array $row): array
    {
        return $resource->toArray($row + ['slug' => 's', 'title' => 't', 'content' => 'c',
            'status' => 'publish', 'mime_type' => 'image/jpeg', 'url' => 'https://x/a.jpg',
            'name' => 'n']);
    }

    /**
     * Published item properties, unwrapping the cursor envelope for listings.
     *
     * @return array<string,mixed>
     */
    private function itemProperties(EndpointDescriptor $descriptor): array
    {
        self::assertNotNull($descriptor->responseSchema);
        $schema = $descriptor->responseSchema->schema;

        /** @var array<string,mixed> $properties */
        $properties = $descriptor->paginated
            ? $schema['properties']['data']['items']['properties']
            : $schema['properties'];

        return $properties;
    }

    /** @return array<string,EndpointDescriptor> */
    private function commerceByRoute(): array
    {
        $out = [];
        foreach ((new CommerceEndpointProvider())->endpoints() as $endpoint) {
            $out[$endpoint->route] = $endpoint;
        }

        return $out;
    }

    /** @return array<string,EndpointDescriptor> */
    private function contentByRoute(): array
    {
        $out = [];
        foreach ((new ContentEndpointProvider())->endpoints() as $endpoint) {
            $out[$endpoint->route] = $endpoint;
        }

        return $out;
    }
}
