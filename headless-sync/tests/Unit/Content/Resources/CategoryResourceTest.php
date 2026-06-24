<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Resources;

use HSP\Modules\Content\Resources\CategoryResource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CategoryResource.
 *
 * Tests are independent of Query Providers (Doc 9 §28).
 */
final class CategoryResourceTest extends TestCase
{
    private CategoryResource $resource;

    protected function setUp(): void
    {
        $this->resource = new CategoryResource();
    }

    public function test_to_array_includes_all_contract_fields(): void
    {
        $result = $this->resource->toArray($this->makeRow());

        foreach (['slug', 'name', 'description', 'parent_id', 'post_count'] as $field) {
            self::assertArrayHasKey($field, $result, "Missing field: $field");
        }
    }

    public function test_to_array_excludes_internal_columns(): void
    {
        $row = $this->makeRow();
        $row['id']             = 'uuid-internal';
        $row['source_term_id'] = 7;
        $row['taxonomy_type']  = 'category';
        $row['checksum']       = 'abc';
        $row['synced_at']      = '2024-01-01 00:00:00+00';
        $row['created_at']     = '2024-01-01 00:00:00+00';
        $row['updated_at']     = '2024-01-01 00:00:00+00';

        $result = $this->resource->toArray($row);

        foreach (['id', 'source_term_id', 'taxonomy_type', 'checksum', 'synced_at', 'created_at', 'updated_at'] as $col) {
            self::assertArrayNotHasKey($col, $result, "Internal column leaked: $col");
        }
    }

    public function test_to_array_maps_values(): void
    {
        $result = $this->resource->toArray($this->makeRow());

        self::assertSame('technology', $result['slug']);
        self::assertSame('Technology', $result['name']);
        self::assertSame('All things tech', $result['description']);
        self::assertSame(0, $result['parent_id']);
        self::assertSame(12, $result['post_count']);
    }

    public function test_parent_id_and_post_count_are_integers(): void
    {
        $result = $this->resource->toArray(
            $this->makeRow(['parent_id' => '3', 'post_count' => '7'])
        );

        self::assertIsInt($result['parent_id']);
        self::assertSame(3, $result['parent_id']);
        self::assertIsInt($result['post_count']);
        self::assertSame(7, $result['post_count']);
    }

    public function test_missing_description_defaults_to_empty_string(): void
    {
        $row = $this->makeRow();
        unset($row['description']);

        $result = $this->resource->toArray($row);

        self::assertSame('', $result['description']);
    }

    public function test_to_collection_structure(): void
    {
        $result = $this->resource->toCollection([$this->makeRow()], 'tok123');

        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('next_cursor', $result);
        self::assertCount(1, $result['data']);
        self::assertSame('tok123', $result['next_cursor']);
    }

    public function test_to_collection_null_cursor_and_empty_rows(): void
    {
        $result = $this->resource->toCollection([], null);

        self::assertSame([], $result['data']);
        self::assertNull($result['next_cursor']);
    }

    public function test_to_collection_items_serialized_via_to_array(): void
    {
        $result = $this->resource->toCollection([$this->makeRow()], null);

        self::assertArrayHasKey('slug', $result['data'][0]);
        self::assertArrayNotHasKey('id', $result['data'][0]);
    }

    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'slug'        => 'technology',
            'name'        => 'Technology',
            'description' => 'All things tech',
            'parent_id'   => '0',
            'post_count'  => '12',
        ], $overrides);
    }
}
