<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Queries;

use HSP\Core\Contracts\FilterSet;
use HSP\Modules\Content\Queries\PostQueryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PostQueryProvider.
 *
 * Tests are independent of Resources (Doc 9 §28).
 * No database; FakeQueryConnection records calls and returns seeded rows.
 */
final class PostQueryProviderTest extends TestCase
{
    private FakeQueryConnection $db;
    private PostQueryProvider   $provider;

    protected function setUp(): void
    {
        $this->db       = new FakeQueryConnection();
        $this->provider = new PostQueryProvider($this->db);
    }

    // -------------------------------------------------------------------------
    // list() — default filters
    // -------------------------------------------------------------------------

    public function test_list_returns_cursor_page(): void
    {
        $this->db->queueResults([$this->makeRow(1)]);

        $page = $this->provider->list(new FilterSet());

        self::assertCount(1, $page->rows);
        self::assertNull($page->nextCursor);
    }

    public function test_list_default_sql_filters_deleted_and_status_publish(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        $sql    = $this->db->sqlAt(0);
        $params = $this->db->paramsAt(0);
        self::assertStringContainsString('p.deleted_at IS NULL', $sql);
        self::assertStringContainsString('p.status = $', $sql);
        self::assertContains('publish', $params);
    }

    // -------------------------------------------------------------------------
    // category filter — projection-side join
    // -------------------------------------------------------------------------

    public function test_category_slug_filter_adds_exists_subquery(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(categorySlug: 'news'));

        $sql    = $this->db->sqlAt(0);
        $params = $this->db->paramsAt(0);

        self::assertStringContainsString('EXISTS', $sql);
        self::assertStringContainsString('content.entity_taxonomies', $sql);
        self::assertStringContainsString('content.taxonomies', $sql);
        self::assertStringContainsString('t.slug =', $sql);
        self::assertContains('news', $params);
    }

    public function test_category_filter_never_uses_term_id(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(categorySlug: 'news'));

        $sql = $this->db->sqlAt(0);
        self::assertStringNotContainsString('term_id', $sql);
        self::assertStringNotContainsString('wp_', $sql);
    }

    public function test_null_category_filter_omits_join(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        $sql = $this->db->sqlAt(0);
        self::assertStringNotContainsString('entity_taxonomies', $sql);
    }

    // -------------------------------------------------------------------------
    // list() — cursor pagination
    // -------------------------------------------------------------------------

    public function test_next_cursor_produced_when_extra_row_returned(): void
    {
        $this->db->queueResults($this->makeRows(21));

        $page = $this->provider->list(new FilterSet());

        self::assertCount(20, $page->rows);
        self::assertNotNull($page->nextCursor);
    }

    public function test_no_next_cursor_on_last_page(): void
    {
        $this->db->queueResults($this->makeRows(5));

        $page = $this->provider->list(new FilterSet());

        self::assertNull($page->nextCursor);
    }

    public function test_cursor_passed_to_list_adds_seek_predicate(): void
    {
        $cursorJson = json_encode(['s' => '2024-03-10 10:00:00+00', 'id' => 'uuid-5']);
        $cursor     = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');

        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(cursor: $cursor));

        $sql    = $this->db->sqlAt(0);
        $params = $this->db->paramsAt(0);
        self::assertStringContainsString('p.published_at <', $sql);
        self::assertContains('2024-03-10 10:00:00+00', $params);
        self::assertContains('uuid-5', $params);
    }

    public function test_shared_published_at_tiebreaker_uses_id(): void
    {
        // Three rows; same published_at on rows 1 and 2.
        $rows = [
            $this->makeRowWith('uuid-z', '2024-01-01 10:00:00+00', 'post-z'),
            $this->makeRowWith('uuid-a', '2024-01-01 10:00:00+00', 'post-a'),
            $this->makeRowWith('uuid-0', '2024-01-01 09:00:00+00', 'post-0'), // extra row
        ];
        $this->db->queueResults($rows);

        $page = $this->provider->list(new FilterSet(limit: 2));

        self::assertCount(2, $page->rows);
        $cursor = $page->nextCursor;
        self::assertNotNull($cursor);

        $padded  = strtr($cursor, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = json_decode(base64_decode($padded), associative: true);

        self::assertSame('uuid-a', $decoded['id']);
        self::assertSame('2024-01-01 10:00:00+00', $decoded['s']);
    }

    // -------------------------------------------------------------------------
    // list() — per_page limit capping
    // -------------------------------------------------------------------------

    public function test_list_default_limit_is_20(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet());

        // default limit + 1 = 21 passed to LIMIT param
        self::assertContains(21, $this->db->paramsAt(0));
        self::assertStringContainsString('LIMIT', $this->db->sqlAt(0));
    }

    public function test_list_limit_is_capped_at_100(): void
    {
        $this->db->queueResults([]);

        $this->provider->list(new FilterSet(limit: 500));

        // capped limit + 1 = 101
        self::assertContains(101, $this->db->paramsAt(0));
    }

    // -------------------------------------------------------------------------
    // findBySlug()
    // -------------------------------------------------------------------------

    public function test_find_by_slug_returns_row(): void
    {
        $this->db->queueResults([$this->makeRow(1)]);

        $row = $this->provider->findBySlug('post-1');

        self::assertNotNull($row);
        self::assertSame('post-1', $row['slug']);
    }

    public function test_find_by_slug_returns_null_when_missing(): void
    {
        $this->db->queueResults([]);

        self::assertNull($this->provider->findBySlug('missing'));
    }

    public function test_find_by_slug_sql_excludes_deleted_and_non_publish(): void
    {
        $this->db->queueResults([]);

        $this->provider->findBySlug('my-post');

        $sql = $this->db->sqlAt(0);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
        self::assertStringContainsString("status = 'publish'", $sql);
        self::assertStringContainsString('slug = $1', $sql);
    }

    public function test_find_by_slug_returns_null_for_non_publish_row(): void
    {
        // Provider returns empty set — simulating a draft row excluded by status='publish' predicate.
        $this->db->queueResults([]);

        self::assertNull($this->provider->findBySlug('draft-post'));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRow(int $n): array
    {
        return $this->makeRowWith('uuid-' . $n, '2024-01-' . sprintf('%02d', $n) . ' 10:00:00+00', 'post-' . $n);
    }

    private function makeRowWith(string $id, string $publishedAt, string $slug): array
    {
        return [
            'id'           => $id,
            'slug'         => $slug,
            'title'        => 'Title ' . $slug,
            'content'      => 'Body',
            'excerpt'      => 'Excerpt',
            'status'       => 'publish',
            'author'       => 'editor',
            'published_at' => $publishedAt,
            'updated_at'   => $publishedAt,
            'meta_jsonb'   => '{}',
        ];
    }

    private function makeRows(int $count): array
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = $this->makeRow($i);
        }
        return $rows;
    }
}
