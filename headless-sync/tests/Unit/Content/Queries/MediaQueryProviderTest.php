<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Queries;

use HSP\Core\Contracts\FilterSet;
use HSP\Modules\Content\Queries\MediaQueryProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaQueryProvider (P1B-S1).
 *
 * Beyond correctness these cover the P1B-S1 PERFORMANCE DoD:
 *   - a listing issues ONE query regardless of page size (no N+1);
 *   - the SQL keeps the (published_at DESC, id DESC) sort and the deleted_at IS NULL
 *     predicate that the partial index idx_content_media_live_published_at is built for;
 *   - cursor values are bound parameters, never interpolated.
 */
final class MediaQueryProviderTest extends TestCase
{
    private FakeQueryConnection $db;
    private MediaQueryProvider  $provider;

    protected function setUp(): void
    {
        $this->db       = new FakeQueryConnection();
        $this->provider = new MediaQueryProvider($this->db);
    }

    // -------------------------------------------------------------------------
    // Performance: no N+1
    // -------------------------------------------------------------------------

    public function test_a_listing_issues_one_query_regardless_of_page_size(): void
    {
        $this->db->queueResults([$this->row('a')]);
        $this->provider->list(new FilterSet());
        $singleRowPageQueries = count($this->db->queries);

        $this->db       = new FakeQueryConnection();
        $this->provider = new MediaQueryProvider($this->db);
        $this->db->queueResults(array_map($this->row(...), range(1, 20)));
        $this->provider->list(new FilterSet(limit: 20));
        $fullPageQueries = count($this->db->queries);

        self::assertSame(1, $singleRowPageQueries);
        self::assertSame(
            $singleRowPageQueries,
            $fullPageQueries,
            'A media listing must cost the same number of queries at any page size — an N+1 here '
            . 'would multiply every request by the page size.',
        );
    }

    public function test_listing_sql_matches_the_partial_index_predicate_and_sort(): void
    {
        $this->db->queueResults([]);
        $this->provider->list(new FilterSet());

        $sql = $this->db->sqlAt(0);

        // These three fragments are what idx_content_media_live_published_at is built for;
        // changing them without changing the index turns the listing into a seq scan.
        self::assertStringContainsString('FROM content.media', $sql);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
        self::assertStringContainsString('ORDER BY published_at DESC, id DESC', $sql);
        self::assertStringNotContainsString('OFFSET', $sql, 'Doc 9 §13 — cursor pagination, never offset');
    }

    public function test_no_status_predicate_is_applied(): void
    {
        $this->db->queueResults([]);
        $this->provider->list(new FilterSet(status: 'publish'));

        // Attachments carry post_status='inherit'; filtering on the {publish} public set
        // would return an empty listing for every site.
        self::assertStringNotContainsString('status =', $this->db->sqlAt(0));
    }

    // -------------------------------------------------------------------------
    // Cursor pagination
    // -------------------------------------------------------------------------

    public function test_next_cursor_is_null_on_the_last_page(): void
    {
        $this->db->queueResults([$this->row(1)]);

        $page = $this->provider->list(new FilterSet(limit: 5));

        self::assertNull($page->nextCursor);
        self::assertTrue($page->isLastPage());
        self::assertCount(1, $page->rows);
    }

    public function test_an_over_full_page_yields_a_cursor_and_trims_the_extra_row(): void
    {
        // The provider fetches limit+1 to detect a further page.
        $this->db->queueResults(array_map($this->row(...), range(1, 3)));

        $page = $this->provider->list(new FilterSet(limit: 2));

        self::assertCount(2, $page->rows, 'the probe row is trimmed');
        self::assertNotNull($page->nextCursor);
    }

    public function test_cursor_round_trips_through_the_seek_predicate_as_bound_params(): void
    {
        $this->db->queueResults(array_map($this->row(...), range(1, 3)));
        $cursor = $this->provider->list(new FilterSet(limit: 2))->nextCursor;
        self::assertNotNull($cursor);

        $this->db       = new FakeQueryConnection();
        $this->provider = new MediaQueryProvider($this->db);
        $this->db->queueResults([]);
        $this->provider->list(new FilterSet(cursor: $cursor, limit: 2));

        $sql    = $this->db->sqlAt(0);
        $params = $this->db->paramsAt(0);

        // The (published_at, id) tiebreaker is what proves no skipped or duplicated rows
        // when several media items share a published_at.
        self::assertStringContainsString('published_at <', $sql);
        self::assertStringContainsString('id::text <', $sql);
        self::assertContains('2024-01-02 03:04:05+00', $params, 'cursor sort value is bound, not interpolated');
    }

    public function test_a_garbage_cursor_is_ignored_rather_than_injected(): void
    {
        $this->db->queueResults([]);
        $this->provider->list(new FilterSet(cursor: 'not-base64!!'));

        self::assertStringNotContainsString('not-base64', $this->db->sqlAt(0));
    }

    // -------------------------------------------------------------------------
    // findBySlug
    // -------------------------------------------------------------------------

    public function test_find_by_slug_binds_the_slug_and_excludes_soft_deleted_rows(): void
    {
        $this->db->queueResults([$this->row(1)]);

        $row = $this->provider->findBySlug('sunset');

        self::assertNotNull($row);
        self::assertStringContainsString('deleted_at IS NULL', $this->db->sqlAt(0));
        self::assertSame(['sunset'], $this->db->paramsAt(0));
    }

    public function test_find_by_slug_returns_null_when_nothing_matches(): void
    {
        $this->db->queueResults([]);

        self::assertNull($this->provider->findBySlug('missing'));
    }

    /** @return array<string,mixed> */
    private function row(mixed $seed): array
    {
        return [
            'id'             => sprintf('01900000-0000-7000-8000-%012d', (int) $seed),
            'slug'           => 'sunset-' . $seed,
            'title'          => 'Sunset',
            'mime_type'      => 'image/jpeg',
            'url'            => 'https://example.test/uploads/sunset.jpg',
            'alt_text'       => '',
            'caption'        => '',
            'description'    => '',
            'width'          => '1600',
            'height'         => '900',
            'sizes_jsonb'    => '{}',
            'attached_to_id' => '0',
            'published_at'   => '2024-01-02 03:04:05+00',
            'updated_at'     => '2024-01-02 03:04:05+00',
            'meta_jsonb'     => '{}',
        ];
    }
}
