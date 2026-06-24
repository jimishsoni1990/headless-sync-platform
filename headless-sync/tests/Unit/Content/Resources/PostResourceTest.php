<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Resources;

use HSP\Modules\Content\Resources\PostResource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PostResource.
 *
 * Tests are independent of Query Providers (Doc 9 §28).
 */
final class PostResourceTest extends TestCase
{
    private PostResource $resource;

    protected function setUp(): void
    {
        $this->resource = new PostResource();
    }

    public function test_to_array_includes_all_contract_fields(): void
    {
        $result = $this->resource->toArray($this->makeRow());

        foreach (['slug', 'title', 'content', 'excerpt', 'status', 'author',
                  'published_at', 'updated_at', 'meta'] as $field) {
            self::assertArrayHasKey($field, $result, "Missing field: $field");
        }
    }

    public function test_to_array_excludes_internal_columns(): void
    {
        $row = $this->makeRow();
        $row['id']             = 'uuid-internal';
        $row['source_post_id'] = 99;
        $row['checksum']       = 'deadbeef';
        $row['synced_at']      = '2024-01-01 00:00:00+00';
        $row['created_at']     = '2024-01-01 00:00:00+00';

        $result = $this->resource->toArray($row);

        foreach (['id', 'source_post_id', 'checksum', 'synced_at', 'created_at'] as $col) {
            self::assertArrayNotHasKey($col, $result, "Internal column leaked: $col");
        }
    }

    public function test_to_array_maps_values(): void
    {
        $result = $this->resource->toArray($this->makeRow());

        self::assertSame('hello-world', $result['slug']);
        self::assertSame('Hello World', $result['title']);
        self::assertSame('A short excerpt', $result['excerpt']);
        self::assertSame('editor', $result['author']);
        self::assertSame('publish', $result['status']);
    }

    public function test_timestamps_are_iso8601(): void
    {
        $result = $this->resource->toArray(
            $this->makeRow(['published_at' => '2024-06-20 14:00:00+00'])
        );

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $result['published_at']
        );
    }

    public function test_meta_jsonb_decoded(): void
    {
        $result = $this->resource->toArray(
            $this->makeRow(['meta_jsonb' => '{"_yoast_wpseo_title":"SEO Title"}'])
        );

        self::assertSame('SEO Title', $result['meta']['_yoast_wpseo_title']);
    }

    public function test_empty_meta_returns_empty_array(): void
    {
        $result = $this->resource->toArray($this->makeRow(['meta_jsonb' => '{}']));

        self::assertSame([], $result['meta']);
    }

    public function test_to_collection_structure(): void
    {
        $result = $this->resource->toCollection([$this->makeRow()], 'cursor-abc');

        self::assertArrayHasKey('data', $result);
        self::assertArrayHasKey('next_cursor', $result);
        self::assertCount(1, $result['data']);
        self::assertSame('cursor-abc', $result['next_cursor']);
    }

    public function test_to_collection_null_cursor(): void
    {
        $result = $this->resource->toCollection([], null);

        self::assertNull($result['next_cursor']);
        self::assertSame([], $result['data']);
    }

    private function makeRow(array $overrides = []): array
    {
        return array_merge([
            'slug'         => 'hello-world',
            'title'        => 'Hello World',
            'content'      => '<p>Body</p>',
            'excerpt'      => 'A short excerpt',
            'status'       => 'publish',
            'author'       => 'editor',
            'published_at' => '2024-01-10 08:00:00+00',
            'updated_at'   => '2024-01-10 08:00:00+00',
            'meta_jsonb'   => '{}',
        ], $overrides);
    }
}
