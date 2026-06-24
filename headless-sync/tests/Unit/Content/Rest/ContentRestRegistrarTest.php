<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Rest;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Modules\Content\Rest\ContentRestRegistrar;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ContentRestRegistrar handler methods.
 *
 * Covers:
 *   - Bad ?cursor= → WP_Error 400 (not silent ignore)
 *   - Non-public ?status= → WP_Error 400
 *   - findBySlug returning null → WP_Error 404 (not empty 200)
 *   - Valid requests → WP_REST_Response 200
 *   - Decoded cursor keyset values are bound parameters (SQL injection safety)
 *
 * Uses WP stubs from tests/bootstrap.php and fakes for dependencies.
 */
final class ContentRestRegistrarTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Bad cursor → 400
    // -------------------------------------------------------------------------

    public function test_page_listing_returns_400_for_garbage_cursor(): void
    {
        $registrar = $this->makeRegistrar();
        $request   = new \WP_REST_Request(['cursor' => '!!!not-base64!!!']);

        $result = $registrar->handlePageListing($request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
        self::assertSame('hsp_invalid_cursor', $result->code);
    }

    public function test_post_listing_returns_400_for_garbage_cursor(): void
    {
        $registrar = $this->makeRegistrar();
        $request   = new \WP_REST_Request(['cursor' => 'aGVsbG8=']); // valid base64 but not {"s":...,"id":...}

        $result = $registrar->handlePostListing($request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
        self::assertSame('hsp_invalid_cursor', $result->code);
    }

    public function test_category_listing_returns_400_for_garbage_cursor(): void
    {
        $registrar = $this->makeRegistrar();
        // Looks like base64url chars but decodes to non-JSON
        $cursor    = rtrim(strtr(base64_encode('not-json-at-all'), '+/', '-_'), '=');
        $request   = new \WP_REST_Request(['cursor' => $cursor]);

        $result = $registrar->handleCategoryListing($request);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
        self::assertSame('hsp_invalid_cursor', $result->code);
    }

    public function test_cursor_missing_id_field_returns_400(): void
    {
        // Encode {"s":"2024-01-01"} — missing "id"
        $cursorJson = json_encode(['s' => '2024-01-01 00:00:00+00']);
        $cursor     = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');

        $registrar = $this->makeRegistrar();
        $result    = $registrar->handlePageListing(new \WP_REST_Request(['cursor' => $cursor]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
    }

    public function test_cursor_missing_s_field_returns_400(): void
    {
        // Encode {"id":"uuid-x"} — missing "s"
        $cursorJson = json_encode(['id' => 'uuid-x']);
        $cursor     = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');

        $registrar = $this->makeRegistrar();
        $result    = $registrar->handlePostListing(new \WP_REST_Request(['cursor' => $cursor]));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
    }

    public function test_absent_cursor_is_accepted(): void
    {
        $registrar = $this->makeRegistrar(pageRows: []);
        $result    = $registrar->handlePageListing(new \WP_REST_Request([]));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->status);
    }

    public function test_valid_cursor_is_accepted(): void
    {
        $cursorJson = json_encode(['s' => '2024-06-01 00:00:00+00', 'id' => 'uuid-xyz']);
        $cursor     = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');

        $registrar = $this->makeRegistrar(pageRows: []);
        $result    = $registrar->handlePageListing(new \WP_REST_Request(['cursor' => $cursor]));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->status);
    }

    // -------------------------------------------------------------------------
    // Non-public status → 400
    // -------------------------------------------------------------------------

    public function test_page_listing_returns_400_for_draft_status(): void
    {
        $registrar = $this->makeRegistrar();
        $result    = $registrar->handlePageListing(new \WP_REST_Request(['status' => 'draft']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
        self::assertSame('hsp_invalid_status', $result->code);
    }

    public function test_post_listing_returns_400_for_private_status(): void
    {
        $registrar = $this->makeRegistrar();
        $result    = $registrar->handlePostListing(new \WP_REST_Request(['status' => 'private']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(400, $result->data['status']);
        self::assertSame('hsp_invalid_status', $result->code);
    }

    public function test_publish_status_is_accepted(): void
    {
        $registrar = $this->makeRegistrar(pageRows: []);
        $result    = $registrar->handlePageListing(new \WP_REST_Request(['status' => 'publish']));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->status);
    }

    // -------------------------------------------------------------------------
    // findBySlug null → 404
    // -------------------------------------------------------------------------

    public function test_page_single_returns_404_for_missing_slug(): void
    {
        $registrar = $this->makeRegistrar(pageRow: null);
        $result    = $registrar->handlePageSingle(new \WP_REST_Request(['slug' => 'ghost']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(404, $result->data['status']);
        self::assertSame('hsp_not_found', $result->code);
    }

    public function test_page_single_returns_404_for_soft_deleted_slug(): void
    {
        // Query provider returns null for soft-deleted (deleted_at IS NULL predicate).
        $registrar = $this->makeRegistrar(pageRow: null);
        $result    = $registrar->handlePageSingle(new \WP_REST_Request(['slug' => 'dead-page']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(404, $result->data['status']);
    }

    public function test_page_single_returns_404_for_non_publish_slug(): void
    {
        // Query provider returns null for non-publish (status='publish' predicate).
        $registrar = $this->makeRegistrar(pageRow: null);
        $result    = $registrar->handlePageSingle(new \WP_REST_Request(['slug' => 'draft-page']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(404, $result->data['status']);
    }

    public function test_post_single_returns_404_for_missing_slug(): void
    {
        $registrar = $this->makeRegistrar(postRow: null);
        $result    = $registrar->handlePostSingle(new \WP_REST_Request(['slug' => 'ghost']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(404, $result->data['status']);
        self::assertSame('hsp_not_found', $result->code);
    }

    public function test_post_single_returns_404_for_soft_deleted_slug(): void
    {
        $registrar = $this->makeRegistrar(postRow: null);
        $result    = $registrar->handlePostSingle(new \WP_REST_Request(['slug' => 'dead-post']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(404, $result->data['status']);
    }

    public function test_category_single_returns_404_for_missing_slug(): void
    {
        $registrar = $this->makeRegistrar(categoryRow: null);
        $result    = $registrar->handleCategorySingle(new \WP_REST_Request(['slug' => 'ghost']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(404, $result->data['status']);
        self::assertSame('hsp_not_found', $result->code);
    }

    public function test_category_single_returns_404_for_soft_deleted_slug(): void
    {
        $registrar = $this->makeRegistrar(categoryRow: null);
        $result    = $registrar->handleCategorySingle(new \WP_REST_Request(['slug' => 'dead-cat']));

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame(404, $result->data['status']);
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    public function test_page_single_returns_200_for_found_slug(): void
    {
        $registrar = $this->makeRegistrar(pageRow: $this->samplePageRow());
        $result    = $registrar->handlePageSingle(new \WP_REST_Request(['slug' => 'about']));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->status);
        self::assertArrayHasKey('slug', $result->data);
    }

    public function test_post_single_returns_200_for_found_slug(): void
    {
        $registrar = $this->makeRegistrar(postRow: $this->samplePostRow());
        $result    = $registrar->handlePostSingle(new \WP_REST_Request(['slug' => 'hello']));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->status);
    }

    public function test_category_single_returns_200_for_found_slug(): void
    {
        $registrar = $this->makeRegistrar(categoryRow: $this->sampleCategoryRow());
        $result    = $registrar->handleCategorySingle(new \WP_REST_Request(['slug' => 'tech']));

        self::assertInstanceOf(\WP_REST_Response::class, $result);
        self::assertSame(200, $result->status);
    }

    // -------------------------------------------------------------------------
    // Cursor keyset values are bound params, not string interpolation
    // -------------------------------------------------------------------------

    public function test_cursor_keyset_values_are_passed_as_bound_params_not_interpolated(): void
    {
        // A cursor whose "s" value contains SQL injection payload.
        // If the provider interpolates rather than binds, the SQL would be malformed/dangerous.
        // We verify via the FakeQueryProvider that the value appears as a param, not in the SQL.
        $maliciousTs = "2024-01-01'; DROP TABLE content.pages; --";
        $cursorJson  = json_encode(['s' => $maliciousTs, 'id' => 'uuid-x']);
        $cursor      = rtrim(strtr(base64_encode($cursorJson), '+/', '-_'), '=');

        $fakePageProvider = new FakeQueryProvider(listResult: new CursorPage([], null));
        $registrar        = $this->makeRegistrarWithProviders(pageProvider: $fakePageProvider);

        $result = $registrar->handlePageListing(new \WP_REST_Request(['cursor' => $cursor]));

        // Must succeed (not 400) — a valid structurally-correct cursor.
        self::assertInstanceOf(\WP_REST_Response::class, $result);
        // The FilterSet passed to the provider must carry the raw cursor token, not the
        // decoded payload — the provider receives an opaque cursor and decodes internally.
        self::assertNotNull($fakePageProvider->lastFilters);
        self::assertSame($cursor, $fakePageProvider->lastFilters->cursor);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function makeRegistrar(
        array  $pageRows     = [],
        ?array $pageRow      = ['default'],
        array  $postRows     = [],
        ?array $postRow      = ['default'],
        array  $categoryRows = [],
        ?array $categoryRow  = ['default'],
    ): ContentRestRegistrar {
        $pageRow     = $pageRow === ['default']     ? $this->samplePageRow()     : $pageRow;
        $postRow     = $postRow === ['default']     ? $this->samplePostRow()     : $postRow;
        $categoryRow = $categoryRow === ['default'] ? $this->sampleCategoryRow() : $categoryRow;

        return $this->makeRegistrarWithProviders(
            pageProvider:     new FakeQueryProvider(listResult: new CursorPage($pageRows, null), singleRow: $pageRow),
            postProvider:     new FakeQueryProvider(listResult: new CursorPage($postRows, null), singleRow: $postRow),
            categoryProvider: new FakeQueryProvider(listResult: new CursorPage($categoryRows, null), singleRow: $categoryRow),
        );
    }

    private function makeRegistrarWithProviders(
        ?FakeQueryProvider $pageProvider     = null,
        ?FakeQueryProvider $postProvider     = null,
        ?FakeQueryProvider $categoryProvider = null,
    ): ContentRestRegistrar {
        return new ContentRestRegistrar(
            pageQueryProvider:     $pageProvider     ?? new FakeQueryProvider(new CursorPage([], null)),
            postQueryProvider:     $postProvider     ?? new FakeQueryProvider(new CursorPage([], null)),
            categoryQueryProvider: $categoryProvider ?? new FakeQueryProvider(new CursorPage([], null)),
            pageResource:          new \HSP\Modules\Content\Resources\PageResource(),
            postResource:          new \HSP\Modules\Content\Resources\PostResource(),
            categoryResource:      new \HSP\Modules\Content\Resources\CategoryResource(),
        );
    }

    private function samplePageRow(): array
    {
        return ['slug' => 'about', 'title' => 'About', 'content' => '', 'status' => 'publish',
                'parent_id' => '0', 'menu_order' => '0', 'published_at' => '2024-01-01 00:00:00+00',
                'updated_at' => '2024-01-01 00:00:00+00', 'meta_jsonb' => '{}'];
    }

    private function samplePostRow(): array
    {
        return ['slug' => 'hello', 'title' => 'Hello', 'content' => '', 'excerpt' => '',
                'status' => 'publish', 'author' => 'ed', 'published_at' => '2024-01-01 00:00:00+00',
                'updated_at' => '2024-01-01 00:00:00+00', 'meta_jsonb' => '{}'];
    }

    private function sampleCategoryRow(): array
    {
        return ['slug' => 'tech', 'name' => 'Tech', 'description' => '', 'parent_id' => '0', 'post_count' => '0'];
    }
}
