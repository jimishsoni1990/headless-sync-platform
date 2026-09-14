<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Modules\Content\Queries\PageQueryProvider;
use HSP\Modules\Content\Resources\PageResource;
use HSP\Tests\Unit\Content\Queries\FakeQueryConnection;
use PHPUnit\Framework\TestCase;

/**
 * The canonical page path on the Page resource (Finding 005).
 *
 * The capability gap: the listing returned a nested page as `{slug: jimish-soni, parent_id: 83}`,
 * while the only way to address a page is `GET /pages/{full ancestor path}` (DECISION AD). A
 * consumer therefore held a page it could not route to — and every route open to it was forbidden:
 * guessing the parent slug, using the WordPress id, querying WordPress, or rebuilding the tree from
 * repeated API calls. The identity already existed; only its publication was missing.
 *
 * What these guards protect:
 *   - `path` is the ONE public hierarchical identity — no `uri`/`full_path`/`permalink` alias and
 *     no site URL, because this is HSP's relative page path, not permalink generation;
 *   - hierarchy stays in the Query Provider — the Resource serializes what it is handed and derives
 *     nothing, so there is never a second ancestor walk free to disagree with the first;
 *   - one bounded query per listing page, anchored on the requested cursor window rather than the
 *     whole table;
 *   - the ancestor walk keeps its depth/cycle guard on both surfaces.
 */
final class PageCanonicalPathTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Published contract
    // -------------------------------------------------------------------------

    public function test_a_nested_page_publishes_its_full_ancestor_path(): void
    {
        $body = (new PageResource())->toArray(
            $this->row('jimish-soni', parentId: 83) + ['path' => 'our-team/jimish-soni']
        );

        self::assertSame('jimish-soni', $body['slug']);
        self::assertSame('our-team/jimish-soni', $body['path']);
    }

    /** A top-level page's path IS its slug — not `/about`, not `about/`, not `pages/about`. */
    public function test_a_top_level_page_publishes_its_bare_slug_as_the_path(): void
    {
        $body = (new PageResource())->toArray($this->row('about') + ['path' => 'about']);

        self::assertSame('about', $body['path']);
    }

    public function test_a_deep_page_publishes_every_ancestor_segment(): void
    {
        $body = (new PageResource())->toArray(
            $this->row('leadership', parentId: 9) + ['path' => 'about/team/leadership']
        );

        self::assertSame('about/team/leadership', $body['path']);
    }

    /**
     * The path is whatever the Query Provider resolved. The Resource must not reconstruct it from
     * `parent_id` — that would be a second hierarchy implementation, in the layer that owns
     * contract shaping and no business logic (Doc 9 §11).
     */
    public function test_the_resource_never_derives_a_path_of_its_own(): void
    {
        $body = (new PageResource())->toArray($this->row('team', parentId: 3));

        self::assertNull($body['path'], 'no resolved path means no path — never a guess from parent_id');
    }

    /**
     * Null, not the leaf slug, when the projection cannot reconstruct a path (DECISION AE: an
     * ancestor that was never published contributes no slug). The leaf would be an address that
     * 404s — precisely the fallback DECISION AF removed, smuggled back in through the listing.
     */
    public function test_an_unreconstructable_path_publishes_null_not_the_leaf_slug(): void
    {
        $body = (new PageResource())->toArray($this->row('team', parentId: 3) + ['path' => null]);

        self::assertNull($body['path']);
        self::assertSame('team', $body['slug'], 'the page itself is still published');
    }

    /** ONE canonical identity. An alias would leave consumers guessing which to route on. */
    public function test_no_second_identity_or_site_url_is_published(): void
    {
        $body = (new PageResource())->toArray($this->row('team', parentId: 3) + ['path' => 'about/team']);

        foreach (['uri', 'full_path', 'ancestor_path', 'permalink', 'url', 'frontend_url', 'link'] as $alias) {
            self::assertArrayNotHasKey($alias, $body, "'{$alias}' would be a second page identity");
        }
    }

    /** `parent_id` stays: removing a published field is a compatibility decision of its own. */
    public function test_the_existing_published_fields_are_untouched(): void
    {
        $body = (new PageResource())->toArray($this->row('team', parentId: 3) + ['path' => 'about/team']);

        self::assertSame(
            ['slug', 'path', 'title', 'content', 'status', 'parent_id', 'menu_order',
             'published_at', 'updated_at', 'meta', 'featured_media'],
            array_keys($body),
        );
        self::assertSame(3, $body['parent_id']);
    }

    // -------------------------------------------------------------------------
    // Query strategy
    // -------------------------------------------------------------------------

    /** The listing resolves paths in the SAME round-trip — never one lookup per row. */
    public function test_a_listing_resolves_paths_in_one_query(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([
            $this->row('about') + ['id' => 'uuid-1', 'path' => 'about'],
            $this->row('team', parentId: 3) + ['id' => 'uuid-2', 'path' => 'about/team'],
        ]);

        $page = (new PageQueryProvider($db))->list(new ContentFilterSet());

        self::assertCount(2, $page->rows);
        self::assertCount(1, $db->queries, 'one query for the whole page, whatever its size');
        self::assertSame(
            ['about', 'about/team'],
            array_column(array_map((new PageResource())->toArray(...), $page->rows), 'path'),
        );
    }

    /**
     * The recursion anchors on the CURSOR WINDOW, not on content.pages: only the rows actually
     * being returned are walked up to the root, so the cost is page size × depth rather than the
     * whole site's page tree.
     */
    public function test_the_listing_walks_ancestors_of_the_requested_page_only(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new PageQueryProvider($db))->list(new ContentFilterSet());

        $sql = $db->sqlAt(0);
        self::assertStringContainsString('WITH RECURSIVE paged AS', $sql);
        self::assertStringContainsString('FROM   paged anchor', $sql, 'the walk anchors on the page window');
        self::assertStringContainsString('AS path', $sql);
    }

    /** Path resolution must not disturb eligibility, ordering or the LIMIT the cursor depends on. */
    public function test_path_resolution_leaves_the_pagination_query_intact(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new PageQueryProvider($db))->list(new ContentFilterSet(limit: 5));

        $sql = $db->sqlAt(0);
        self::assertStringContainsString('deleted_at IS NULL', $sql);
        self::assertStringContainsString('status = $', $sql);
        self::assertStringContainsString('ORDER BY p.published_at DESC, p.id DESC', $sql);
        self::assertStringContainsString('ORDER BY paged.published_at DESC, paged.id DESC', $sql);
        self::assertContains(6, $db->paramsAt(0), 'still limit+1 for next-page detection');
    }

    /** The path is a SCALAR sub-select — a join could multiply a page row per ancestor. */
    public function test_the_path_cannot_multiply_page_rows(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new PageQueryProvider($db))->list(new ContentFilterSet());

        $sql = $db->sqlAt(0);
        self::assertStringContainsString('SELECT paged.*,', $sql);
        self::assertStringContainsString('(SELECT a.path', $sql);
        self::assertStringNotContainsString('JOIN   ancestry', $sql, 'ancestry must not join the page rows');
        self::assertStringNotContainsString('DISTINCT', $sql, 'nothing multiplies, so nothing needs de-duplicating');
    }

    /** Both surfaces run the SAME ancestor walk, so they cannot disagree about an identity. */
    public function test_listing_and_lookup_share_one_ancestor_walk(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([], []);

        $provider = new PageQueryProvider($db);
        $provider->list(new ContentFilterSet());
        $provider->findByPath('about/team');

        foreach ([$db->sqlAt(0), $db->sqlAt(1)] as $sql) {
            self::assertStringContainsString('WITH RECURSIVE', $sql);
            self::assertStringContainsString("anc.slug || '/' || a.path", $sql, 'the same walk');
            self::assertStringContainsString('anc.source_post_id = a.next_parent', $sql);
            // The corruption/cycle bound survives on both surfaces (DECISION AD ruling 4).
            self::assertStringContainsString('a.depth < 50', $sql);
        }
    }

    /** The lookup returns the path it RECONSTRUCTED, so detail and listing publish one value. */
    public function test_the_lookup_publishes_the_reconstructed_path(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([$this->row('team', parentId: 3) + ['path' => 'about/team']]);

        $row = (new PageQueryProvider($db))->findByPath('about/team');

        self::assertNotNull($row);
        self::assertStringContainsString('matched.path', $db->sqlAt(0), 'selected from the CTE, not echoed back');
        self::assertSame('about/team', (new PageResource())->toArray($row)['path']);
    }

    // -------------------------------------------------------------------------
    // Published contract (OpenAPI — ADR-055)
    // -------------------------------------------------------------------------

    /** Documented on BOTH page surfaces: an identity is only useful where the resource appears. */
    public function test_both_page_endpoints_document_the_path_field(): void
    {
        foreach (['/pages', '/pages/{path}'] as $route) {
            $props = $this->pageProperties($route);

            self::assertArrayHasKey('path', $props, "{$route} must document the page path");
            self::assertSame(['string', 'null'], $props['path']['type'], "{$route} path nullability");
            self::assertStringContainsString('about/team', $props['path']['description']);
            self::assertStringContainsString('GET /pages/{path}', $props['path']['description']);
        }
    }

    public function test_the_list_and_detail_page_schemas_are_identical(): void
    {
        self::assertSame($this->pageProperties('/pages'), $this->pageProperties('/pages/{path}'));
    }

    public function test_the_schema_publishes_no_second_page_identity(): void
    {
        $props = $this->pageProperties('/pages/{path}');

        foreach (['uri', 'full_path', 'ancestor_path', 'permalink', 'url', 'link'] as $alias) {
            self::assertArrayNotHasKey($alias, $props, "'{$alias}' would be a second page identity");
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function pageProperties(string $route): array
    {
        foreach ((new ContentEndpointProvider())->endpoints() as $endpoint) {
            if ($endpoint->route !== $route) {
                continue;
            }

            self::assertNotNull($endpoint->responseSchema);
            $schema = $endpoint->responseSchema->schema;

            /** @var array<string,mixed> $properties */
            $properties = $endpoint->paginated
                ? $schema['properties']['data']['items']['properties']
                : $schema['properties'];

            return $properties;
        }

        self::fail("no descriptor for {$route}");
    }

    /** @return array<string,mixed> */
    private function row(string $slug, int $parentId = 0): array
    {
        return [
            'slug'         => $slug,
            'title'        => 'Page',
            'content'      => '',
            'status'       => 'publish',
            'parent_id'    => (string) $parentId,
            'menu_order'   => '0',
            'published_at' => '2026-09-07 09:35:19+00',
            'updated_at'   => '2026-09-07 09:35:19+00',
            'meta_jsonb'   => '{}',
        ];
    }
}
