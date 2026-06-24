<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Queries;

use HSP\Core\Contracts\FilterSet;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CategoryQueryProvider.
 *
 * Tests are independent of Resources (Doc 9 §28).
 * Sort order: (name ASC, id ASC) with id tiebreaker for shared names.
 */
final class CategoryQueryProviderTest extends TestCase
{
    private FakeQueryConnection    $db;
    private CategoryQueryProvider  $provider;

    protected function setUp(): void
    {
        $this->db       = new FakeQueryConnection();
        $this->provider = new CategoryQueryProvider($this->db);
    }

    // -------------------------------------------------------------------------
    // list() — basic
    // -------------------------------------------------------------------------

    public function test_list_returns_cursor_page(): void
    {
        $this->db->queueResults([$this->makeRow('alpha')]);

        $page = $this->provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertNull($page->nextCursor);
    }

    public function test_list_sql_filters_deleted_at_and_taxonomy_type(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        $sql = $this->db->sqlAt(0);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
        self::assertStringContainsString("taxonomy_type = 'category'", $sql);
    }

    public function test_list_sort_order_is_name_asc_id_asc(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        $sql = $this->db->sqlAt(0);
        self::assertStringContainsString('ORDER BY name ASC, id ASC', $sql);
    }

    // -------------------------------------------------------------------------
    // list() — cursor pagination
    // -------------------------------------------------------------------------

    public function test_next_cursor_produced_when_extra_row_returned(): void
    {
        $this->db->queueResults($this->makeRows(51));

        $page = $this->provider->list(new FilterSet());

        self::assertCount(50, $page->rows);
        self::assertNotNull($page->nextCursor);
    }

    public function test_no_next_cursor_when_page_is_last(): void
    {
        $this->db->queueResults($this->makeRows(3));

        $page = $this->provider->list(new FilterSet());

        self::assertNull($page->nextCursor);
    }

    public function test_cursor_encodes_name_and_id_of_last_row(): void
    {
        $this->db->queueResults($this->makeRows(51));

        $page   = $this->provider->list(new FilterSet());
        $cursor = $page->nextCursor;
        self::assertNotNull($cursor);

        $padded  = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = json_decode(base64_decode($padded), associative: true);

        self::assertArrayHasKey('s', $decoded);
        self::assertArrayHasKey('id', $decoded);
        // 50th row has name 'cat-50' and id 'uuid-50'
        self::assertSame('cat-50', $decoded['s']);
        self::assertSame('uuid-50', $decoded['id']);
    }

    public function test_shared_name_tiebreaker_uses_id(): void
    {
        $rows = [
            ['id' => 'uuid-a', 'slug' => 'tech-a', 'name' => 'Technology',
             'description' => '', 'parent_id' => '0', 'post_count' => '5'],
            ['id' => 'uuid-b', 'slug' => 'tech-b', 'name' => 'Technology',
             'description' => '', 'parent_id' => '0', 'post_count' => '3'],
            ['id' => 'uuid-c', 'slug' => 'travel', 'name' => 'Travel',
             'description' => '', 'parent_id' => '0', 'post_count' => '1'],
        ];
        $this->db->queueResults($rows);

        $page = $this->provider->list(new FilterSet(limit: 2));

        self::assertCount(2, $page->rows);
        $cursor = $page->nextCursor;
        self::assertNotNull($cursor);

        $padded  = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = json_decode(base64_decode($padded), associative: true);

        // Last kept row is uuid-b
        self::assertSame('uuid-b', $decoded['id']);
        self::assertSame('Technology', $decoded['s']);
    }

    public function test_cursor_passed_adds_seek_predicate(): void
    {
        $cursorJson = json_encode(['s' => 'Sports', 'id' => 'uuid-sp']);
        $cursor     = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');

        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(cursor: $cursor));

        $sql    = $this->db->sqlAt(0);
        $params = $this->db->paramsAt(0);

        // Seek predicate: name > cursor_name OR (name = cursor_name AND id > cursor_id)
        self::assertStringContainsString('name >', $sql);
        self::assertContains('Sports', $params);
        self::assertContains('uuid-sp', $params);
    }

    public function test_invalid_cursor_is_silently_ignored(): void
    {
        $this->db->queueResults([]);

        $page = $this->provider->list(new FilterSet(cursor: '!!!invalid!!!'));

        self::assertNull($page->nextCursor);
    }

    // -------------------------------------------------------------------------
    // findBySlug()
    // -------------------------------------------------------------------------

    public function test_find_by_slug_returns_row_when_found(): void
    {
        $this->db->queueResults([$this->makeRow('technology')]);

        $row = $this->provider->findBySlug('technology');

        self::assertNotNull($row);
        self::assertSame('technology', $row['slug']);
    }

    public function test_find_by_slug_returns_null_when_not_found(): void
    {
        $this->db->queueResults([]);

        self::assertNull($this->provider->findBySlug('nonexistent'));
    }

    public function test_find_by_slug_sql_excludes_deleted_and_filters_taxonomy_type(): void
    {
        $this->db->queueResults([]);

        $this->provider->findBySlug('news');

        $sql = $this->db->sqlAt(0);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
        self::assertStringContainsString("taxonomy_type = 'category'", $sql);
        self::assertStringContainsString('slug = $1', $sql);
        self::assertSame(['news'], $this->db->paramsAt(0));
    }

    // -------------------------------------------------------------------------
    // limit capping
    // -------------------------------------------------------------------------

    public function test_list_limit_is_capped_at_200(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(limit: 999));

        // limit+1 = 201 must be the LIMIT param
        self::assertContains(201, $this->db->paramsAt(0));
    }

    public function test_list_default_limit_is_50(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        // default limit+1 = 51
        self::assertContains(51, $this->db->paramsAt(0));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRow(string $slug): array
    {
        return [
            'id'          => 'uuid-' . $slug,
            'slug'        => $slug,
            'name'        => ucfirst($slug),
            'description' => '',
            'parent_id'   => '0',
            'post_count'  => '0',
        ];
    }

    private function makeRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id'          => 'uuid-' . $i,
                'slug'        => 'cat-' . $i,
                'name'        => 'cat-' . $i,
                'description' => '',
                'parent_id'   => '0',
                'post_count'  => '0',
            ];
        }
        return $rows;
    }
}
