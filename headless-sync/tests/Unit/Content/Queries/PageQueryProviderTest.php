<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Queries;

use HSP\Core\Contracts\FilterSet;
use HSP\Modules\Content\Queries\PageQueryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PageQueryProvider.
 *
 * Tests are independent of Resources (Doc 9 §28).
 * No database; FakeQueryConnection records calls and returns seeded rows.
 */
final class PageQueryProviderTest extends TestCase
{
    private FakeQueryConnection $db;
    private PageQueryProvider   $provider;

    protected function setUp(): void
    {
        $this->db       = new FakeQueryConnection();
        $this->provider = new PageQueryProvider($this->db);
    }

    // -------------------------------------------------------------------------
    // list() — default filters
    // -------------------------------------------------------------------------

    public function test_list_returns_cursor_page_with_rows(): void
    {
        $this->db->queueResults([
            ['id' => 'uuid-1', 'slug' => 'about', 'title' => 'About', 'content' => 'Hello',
             'status' => 'publish', 'parent_id' => '0', 'menu_order' => '0',
             'published_at' => '2024-01-15 10:00:00+00', 'updated_at' => '2024-01-15 10:00:00+00',
             'meta_jsonb' => '{}'],
        ]);

        $page = $this->provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertNull($page->nextCursor);
        self::assertTrue($page->isLastPage());
    }

    public function test_list_sql_filters_deleted_at_and_status_publish_by_default(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        $sql = $this->db->sqlAt(0);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
        self::assertStringContainsString('status = $', $sql);
        // Default status value should be 'publish'
        self::assertContains('publish', $this->db->paramsAt(0));
    }

    public function test_list_explicit_status_filter_is_forwarded(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(status: 'publish'));

        self::assertContains('publish', $this->db->paramsAt(0));
    }

    // -------------------------------------------------------------------------
    // list() — published_after filter
    // -------------------------------------------------------------------------

    public function test_list_published_after_filter_adds_where_clause(): void
    {
        $this->db->queueResults([]);
        $after = new \DateTimeImmutable('2024-06-01T00:00:00Z');

        $this->provider->list(new FilterSet(publishedAfter: $after));

        $sql    = $this->db->sqlAt(0);
        $params = $this->db->paramsAt(0);
        self::assertStringContainsString('published_at >', $sql);
        self::assertStringContainsString('timestamptz', $sql);
        // The formatted UTC string should appear in params
        $found = false;
        foreach ($params as $p) {
            if (is_string($p) && str_contains($p, '2024-06-01')) {
                $found = true;
                break;
            }
        }
        self::assertTrue($found, 'published_after date not found in query params');
    }

    // -------------------------------------------------------------------------
    // list() — cursor pagination
    // -------------------------------------------------------------------------

    public function test_list_next_cursor_is_null_when_rows_lte_limit(): void
    {
        // Return exactly limit rows (default 20); no extra row → last page.
        $rows = $this->makeRows(20);
        $this->db->queueResults($rows);

        $page = $this->provider->list(new FilterSet());

        self::assertNull($page->nextCursor);
    }

    public function test_list_next_cursor_is_set_when_more_rows_exist(): void
    {
        // Return limit+1 rows; provider detects extra and produces cursor.
        $rows = $this->makeRows(21);
        $this->db->queueResults($rows);

        $page = $this->provider->list(new FilterSet());

        self::assertCount(20, $page->rows, 'Extra row must be stripped from returned rows');
        self::assertNotNull($page->nextCursor);
        self::assertFalse($page->isLastPage());
    }

    public function test_cursor_is_opaque_base64url_string(): void
    {
        $rows = $this->makeRows(21);
        $this->db->queueResults($rows);

        $page = $this->provider->list(new FilterSet());

        $cursor = $page->nextCursor;
        self::assertNotNull($cursor);
        // base64url chars only (no +, /, =)
        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', $cursor);
    }

    public function test_cursor_encodes_published_at_and_id_of_last_row(): void
    {
        $rows = $this->makeRows(21);
        $this->db->queueResults($rows);

        $page   = $this->provider->list(new FilterSet());
        $cursor = $page->nextCursor;
        self::assertNotNull($cursor);

        // Decode and verify structure
        $padded  = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = json_decode(base64_decode($padded), associative: true);

        self::assertArrayHasKey('s', $decoded);
        self::assertArrayHasKey('id', $decoded);
        // The 20th row (index 19) is the last kept row.
        self::assertSame('2024-01-' . sprintf('%02d', 21 - 19) . ' 10:00:00+00', $decoded['s']);
        self::assertSame('uuid-20', $decoded['id']);
    }

    public function test_cursor_passed_in_filters_adds_seek_clause(): void
    {
        // Build a valid cursor for published_at='2024-01-10 10:00:00+00', id='uuid-10'
        $cursorJson   = json_encode(['s' => '2024-01-10 10:00:00+00', 'id' => 'uuid-10']);
        $cursor       = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');

        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(cursor: $cursor));

        $sql    = $this->db->sqlAt(0);
        $params = $this->db->paramsAt(0);

        // Seek predicate must include published_at comparison
        self::assertStringContainsString('published_at <', $sql);
        self::assertContains('2024-01-10 10:00:00+00', $params);
        self::assertContains('uuid-10', $params);
    }

    public function test_shared_published_at_tiebreaker_uses_id(): void
    {
        // Two rows with identical published_at but different ids.
        // Both are returned in page 1; page 2 cursor must use id tiebreaker.
        $rows = [
            ['id' => 'uuid-b', 'slug' => 'b', 'title' => 'B', 'content' => '',
             'status' => 'publish', 'parent_id' => '0', 'menu_order' => '0',
             'published_at' => '2024-01-01 10:00:00+00', 'updated_at' => '2024-01-01 10:00:00+00',
             'meta_jsonb' => '{}'],
            ['id' => 'uuid-a', 'slug' => 'a', 'title' => 'A', 'content' => '',
             'status' => 'publish', 'parent_id' => '0', 'menu_order' => '0',
             'published_at' => '2024-01-01 10:00:00+00', 'updated_at' => '2024-01-01 10:00:00+00',
             'meta_jsonb' => '{}'],
            // Extra row to trigger next cursor
            ['id' => 'uuid-c', 'slug' => 'c', 'title' => 'C', 'content' => '',
             'status' => 'publish', 'parent_id' => '0', 'menu_order' => '0',
             'published_at' => '2024-01-01 09:00:00+00', 'updated_at' => '2024-01-01 09:00:00+00',
             'meta_jsonb' => '{}'],
        ];
        $this->db->queueResults($rows);

        $page = $this->provider->list(new FilterSet(limit: 2));

        self::assertCount(2, $page->rows);
        $cursor = $page->nextCursor;
        self::assertNotNull($cursor);

        $padded  = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = json_decode(base64_decode($padded), associative: true);

        // Cursor must be built from last kept row (uuid-a, the 2nd row).
        self::assertSame('uuid-a', $decoded['id']);
        self::assertSame('2024-01-01 10:00:00+00', $decoded['s']);
    }

    public function test_invalid_cursor_is_silently_ignored(): void
    {
        $this->db->queueResults([]);

        // Should not throw; invalid cursor is treated as absent.
        $page = $this->provider->list(new FilterSet(cursor: 'not-valid-base64!!!'));

        self::assertNull($page->nextCursor);
    }

    // -------------------------------------------------------------------------
    // list() — per_page limit capping
    // -------------------------------------------------------------------------

    public function test_list_limit_is_capped_at_100(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(limit: 500));

        // limit+1 = 101 must be the LIMIT param
        self::assertContains(101, $this->db->paramsAt(0));
        self::assertStringContainsString('LIMIT', $this->db->sqlAt(0));
    }

    public function test_list_default_limit_is_20(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        // default limit+1 = 21
        self::assertContains(21, $this->db->paramsAt(0));
    }

    // -------------------------------------------------------------------------
    // findBySlug()
    // -------------------------------------------------------------------------

    public function test_find_by_slug_returns_row_when_found(): void
    {
        $this->db->queueResults([
            ['id' => 'uuid-1', 'slug' => 'about', 'title' => 'About', 'content' => 'Hello',
             'status' => 'publish', 'parent_id' => '0', 'menu_order' => '0',
             'published_at' => '2024-01-15 10:00:00+00', 'updated_at' => '2024-01-15 10:00:00+00',
             'meta_jsonb' => '{}'],
        ]);

        $row = $this->provider->findBySlug('about');

        self::assertNotNull($row);
        self::assertSame('about', $row['slug']);
    }

    public function test_find_by_slug_returns_null_when_not_found(): void
    {
        $this->db->queueResults([]);

        $row = $this->provider->findBySlug('nonexistent');

        self::assertNull($row);
    }

    public function test_find_by_slug_sql_filters_deleted_at_and_status_publish(): void
    {
        $this->db->queueResults([]);

        $this->provider->findBySlug('about');

        $sql = $this->db->sqlAt(0);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
        self::assertStringContainsString("status = 'publish'", $sql);
        self::assertStringContainsString('slug = $1', $sql);
        self::assertSame(['about'], $this->db->paramsAt(0));
    }

    public function test_find_by_slug_returns_null_for_non_publish_row(): void
    {
        // Provider returns empty set — simulating a draft row excluded by status='publish' predicate.
        $this->db->queueResults([]);

        $row = $this->provider->findBySlug('draft-page');

        self::assertNull($row);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<int,array<string,mixed>> */
    private function makeRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            // Dates go from Jan 21 down to Jan 1 so sort is DESC
            $day    = sprintf('%02d', max(1, 22 - $i));
            $rows[] = [
                'id'           => 'uuid-' . $i,
                'slug'         => 'page-' . $i,
                'title'        => 'Page ' . $i,
                'content'      => '',
                'status'       => 'publish',
                'parent_id'    => '0',
                'menu_order'   => '0',
                'published_at' => '2024-01-' . $day . ' 10:00:00+00',
                'updated_at'   => '2024-01-' . $day . ' 10:00:00+00',
                'meta_jsonb'   => '{}',
            ];
        }
        return $rows;
    }
}
