<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Resources;

use HSP\Modules\Content\Resources\PageResource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageResource.
 *
 * Tests are independent of Query Providers (Doc 9 §28).
 * Verifies contract shape, internal column exclusion, and timestamp normalisation.
 */
final class PageResourceTest extends TestCase
{
    private PageResource $resource;

    protected function setUp(): void
    {
        $this->resource = new PageResource();
    }

    // -------------------------------------------------------------------------
    // toArray() — contract fields
    // -------------------------------------------------------------------------

    public function test_to_array_includes_all_contract_fields(): void
    {
        $row = $this->makeRow();

        $result = $this->resource->toArray($row);

        self::assertArrayHasKey('slug', $result);
        self::assertArrayHasKey('title', $result);
        self::assertArrayHasKey('content', $result);
        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('parent_id', $result);
        self::assertArrayHasKey('menu_order', $result);
        self::assertArrayHasKey('published_at', $result);
        self::assertArrayHasKey('updated_at', $result);
        self::assertArrayHasKey('meta', $result);
    }

    public function test_to_array_excludes_internal_columns(): void
    {
        $row = $this->makeRow();
        // Add internal columns that must NOT be leaked (ADR-040)
        $row['id']             = 'some-uuid';
        $row['source_post_id'] = 42;
        $row['checksum']       = 'abc123';
        $row['synced_at']      = '2024-01-01 00:00:00+00';
        $row['created_at']     = '2024-01-01 00:00:00+00';

        $result = $this->resource->toArray($row);

        self::assertArrayNotHasKey('id', $result);
        self::assertArrayNotHasKey('source_post_id', $result);
        self::assertArrayNotHasKey('checksum', $result);
        self::assertArrayNotHasKey('synced_at', $result);
        self::assertArrayNotHasKey('created_at', $result);
    }

    public function test_to_array_maps_values_correctly(): void
    {
        $row    = $this->makeRow();
        $result = $this->resource->toArray($row);

        self::assertSame('about', $result['slug']);
        self::assertSame('About Us', $result['title']);
        self::assertSame('<p>Content</p>', $result['content']);
        self::assertSame('publish', $result['status']);
        self::assertSame(0, $result['parent_id']);
        self::assertSame(5, $result['menu_order']);
    }

    // -------------------------------------------------------------------------
    // toArray() — timestamp normalisation
    // -------------------------------------------------------------------------

    public function test_timestamps_normalised_to_iso8601_utc(): void
    {
        $row    = $this->makeRow(['published_at' => '2024-03-15 09:30:00+00']);
        $result = $this->resource->toArray($row);

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $result['published_at']
        );
        self::assertStringContainsString('2024-03-15', $result['published_at']);
    }

    public function test_null_timestamp_is_returned_as_null(): void
    {
        $row    = $this->makeRow(['published_at' => null]);
        $result = $this->resource->toArray($row);

        self::assertNull($result['published_at']);
    }

    // -------------------------------------------------------------------------
    // toArray() — meta decoding
    // -------------------------------------------------------------------------

    public function test_meta_jsonb_is_decoded_to_array(): void
    {
        $row    = $this->makeRow(['meta_jsonb' => '{"seo_title":"My Page"}']);
        $result = $this->resource->toArray($row);

        self::assertIsArray($result['meta']);
        self::assertSame('My Page', $result['meta']['seo_title']);
    }

    public function test_empty_meta_jsonb_returns_empty_array(): void
    {
        $row    = $this->makeRow(['meta_jsonb' => '{}']);
        $result = $this->resource->toArray($row);

        self::assertSame([], $result['meta']);
    }

    public function test_null_meta_jsonb_returns_empty_array(): void
    {
        $row    = $this->makeRow(['meta_jsonb' => null]);
        $result = $this->resource->toArray($row);

        self::assertSame([], $result['meta']);
    }

    // -------------------------------------------------------------------------
    // toCollection()
    // -------------------------------------------------------------------------

    public function test_to_collection_wraps_items_under_data_key(): void
    {
        $rows   = [$this->makeRow(), $this->makeRow(['slug' => 'contact'])];
        $result = $this->resource->toCollection($rows, null);

        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('next_cursor', $result);
        self::assertCount(2, $result['data']);
        self::assertNull($result['next_cursor']);
    }

    public function test_to_collection_passes_next_cursor(): void
    {
        $result = $this->resource->toCollection([], 'some-cursor-token');

        self::assertSame('some-cursor-token', $result['next_cursor']);
    }

    public function test_to_collection_items_are_serialized_via_to_array(): void
    {
        $rows   = [$this->makeRow()];
        $result = $this->resource->toCollection($rows, null);

        self::assertArrayHasKey('slug', $result['data'][0]);
        self::assertArrayNotHasKey('id', $result['data'][0]);
    }

    public function test_to_collection_empty_rows_returns_empty_data(): void
    {
        $result = $this->resource->toCollection([], null);

        self::assertSame([], $result['data']);
        self::assertNull($result['next_cursor']);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'slug'         => 'about',
            'title'        => 'About Us',
            'content'      => '<p>Content</p>',
            'status'       => 'publish',
            'parent_id'    => '0',
            'menu_order'   => '5',
            'published_at' => '2024-01-15 10:00:00+00',
            'updated_at'   => '2024-01-15 10:00:00+00',
            'meta_jsonb'   => '{}',
        ], $overrides);
    }
}
