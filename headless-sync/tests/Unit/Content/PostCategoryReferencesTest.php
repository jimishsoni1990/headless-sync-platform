<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Modules\Content\Queries\ContentFilterSet;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Tests\Unit\Content\Queries\FakeQueryConnection;
use PHPUnit\Framework\TestCase;

/**
 * Category references on posts (Finding 003).
 *
 * The capability gap: HSP published categories, and filtered posts BY category, but a consumer
 * holding a post could not tell which categories it belonged to — so a frontend had to invent the
 * relationship or guess. The relationship data already existed; only the read side was missing.
 *
 * The shape reuses the approved taxonomy-reference contract tags established (P1B-S3): `{slug,
 * name}` pairs ordered by slug, always a list. What each guard here protects:
 *   - a LIST, never a singular "primary category" — WordPress has no such concept and picking one
 *     (first row, lowest id, alphabetically first) would be a presentation policy dressed up as
 *     source truth;
 *   - no identifier of any kind published, so the consumer never needs internal identity;
 *   - the taxonomy_type discriminator, without which a tag sharing a category's slug leaks in;
 *   - one query whatever the page size, and a shape that cannot multiply post rows.
 */
final class PostCategoryReferencesTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Published contract
    // -------------------------------------------------------------------------

    public function test_a_post_publishes_the_categories_it_belongs_to(): void
    {
        $row = $this->row(1);
        $row['categories_json'] = '[{"slug":"etf","name":"ETF"}]';

        $body = (new PostResource())->toArray($row);

        self::assertCount(1, $body['categories']);
        self::assertSame('etf', $body['categories'][0]['slug']);
        self::assertSame('ETF', $body['categories'][0]['name']);
    }

    public function test_a_post_in_several_categories_publishes_all_of_them(): void
    {
        $row = $this->row(1);
        $row['categories_json'] = '[{"slug":"etf","name":"ETF"},{"slug":"index","name":"Index"},'
            . '{"slug":"sip","name":"SIP"}]';

        $body = (new PostResource())->toArray($row);

        self::assertSame(['etf', 'index', 'sip'], array_column($body['categories'], 'slug'));
    }

    public function test_a_post_with_no_categories_publishes_an_empty_array_not_null(): void
    {
        // A list-valued relationship stays a list when empty: consumers iterate without a null
        // check, and json_encode must render [] — the JsonMap distinction applies to MAPS, not
        // to this, which is genuinely an array.
        $body = (new PostResource())->toArray($this->row(1));

        self::assertSame([], $body['categories']);
        self::assertStringContainsString(
            '"categories":[]',
            (string) json_encode($body),
            'an uncategorised post publishes a JSON array, never null and never {}',
        );
    }

    public function test_malformed_category_json_degrades_to_an_empty_array(): void
    {
        $row = $this->row(1);
        $row['categories_json'] = 'not json';

        self::assertSame([], (new PostResource())->toArray($row)['categories']);
    }

    // -------------------------------------------------------------------------
    // No primary category — the scope rule, asserted rather than promised
    // -------------------------------------------------------------------------

    public function test_no_primary_category_concept_is_published(): void
    {
        $row = $this->row(1);
        $row['categories_json'] = '[{"slug":"etf","name":"ETF"},{"slug":"index","name":"Index"}]';

        $body = (new PostResource())->toArray($row);

        foreach (['primary_category', 'primary_category_id', 'primary', 'is_primary', 'category'] as $forbidden) {
            self::assertArrayNotHasKey(
                $forbidden,
                $body,
                "WordPress defines no universal primary category; picking one would be a "
                . 'presentation policy, and that choice belongs to the consumer.',
            );
        }

        foreach ($body['categories'] as $reference) {
            self::assertSame(
                ['slug', 'name'],
                array_keys($reference),
                'a category reference carries no rank, order or primacy marker',
            );
        }
    }

    public function test_a_category_reference_publishes_no_identifier(): void
    {
        // The consumer renders a label and routes on the slug. Internal identity — the projection
        // UUID, source_term_id, the WordPress term id — stays internal (ADR-040, DECISION AJ AJ-2).
        $row = $this->row(1);
        $row['categories_json'] = '[{"slug":"etf","name":"ETF"}]';

        $reference = (new PostResource())->toArray($row)['categories'][0];

        foreach (['id', 'category_id', 'term_id', 'source_term_id', 'taxonomy_id', 'url', 'permalink'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $reference);
        }
    }

    // -------------------------------------------------------------------------
    // Discrimination — a category and a tag may legally share a slug
    // -------------------------------------------------------------------------

    public function test_the_categories_aggregate_is_scoped_ordered_and_drops_deleted_terms(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);
        (new PostQueryProvider($db))->list(new ContentFilterSet());

        $sql = $db->sqlAt(0);

        self::assertStringContainsString("AS categories_json", $sql);
        self::assertStringContainsString("t.taxonomy_type = 'category'", $sql);
        self::assertStringContainsString('t.deleted_at IS NULL', $sql, 'a deleted category drops out');
        self::assertStringContainsString('ORDER BY t.slug', $sql, 'deterministic published order');
    }

    public function test_categories_and_tags_are_aggregated_as_two_separately_scoped_lists(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);
        (new PostQueryProvider($db))->list(new ContentFilterSet());

        $sql = $db->sqlAt(0);

        self::assertStringContainsString('AS tags_json', $sql);
        self::assertStringContainsString('AS categories_json', $sql);
        self::assertSame(2, substr_count($sql, 'json_agg'), 'one aggregate per taxonomy');
        self::assertSame(1, substr_count($sql, "t.taxonomy_type = 'category'"));
        self::assertSame(1, substr_count($sql, "t.taxonomy_type = 'post_tag'"));
    }

    public function test_the_two_lists_do_not_cross_contaminate_in_the_resource(): void
    {
        // Same slug, different taxonomy — legal in WordPress, and the case a missing
        // discriminator turns into a wrong answer nobody notices until a site names both alike.
        $row = $this->row(1);
        $row['categories_json'] = '[{"slug":"shared","name":"Shared Category"}]';
        $row['tags_json']       = '[{"slug":"shared","name":"Shared Tag"}]';

        $body = (new PostResource())->toArray($row);

        self::assertSame('Shared Category', $body['categories'][0]['name']);
        self::assertSame('Shared Tag', $body['tags'][0]['name']);
    }

    // -------------------------------------------------------------------------
    // Query shape — no N+1, no row multiplication, list and detail agree
    // -------------------------------------------------------------------------

    public function test_publishing_categories_costs_one_query_at_any_page_size(): void
    {
        $oneRow = new FakeQueryConnection();
        $oneRow->queueResults([$this->row(1)]);
        (new PostQueryProvider($oneRow))->list(new ContentFilterSet(limit: 20));

        $fullPage = new FakeQueryConnection();
        $fullPage->queueResults(array_map($this->row(...), range(1, 20)));
        (new PostQueryProvider($fullPage))->list(new ContentFilterSet(limit: 20));

        self::assertCount(1, $oneRow->queries);
        self::assertCount(
            1,
            $fullPage->queries,
            'Categories are aggregated in SQL precisely so a listing stays at one round-trip; '
            . "resolving each post's categories separately would make a 20-row page cost 21 queries.",
        );
    }

    public function test_the_category_aggregate_cannot_multiply_post_rows(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);
        (new PostQueryProvider($db))->list(new ContentFilterSet());

        $sql = $db->sqlAt(0);

        // A scalar subquery in the select list answers once per post row. A JOIN onto the outer
        // query would emit one row per category and break both the page size and the cursor —
        // and DISTINCT, the usual patch, would break the cursor too.
        self::assertSame(
            1,
            substr_count($sql, 'FROM content.posts p'),
            'the listing still selects from exactly one post source',
        );
        self::assertStringNotContainsString('DISTINCT', $sql);
        self::assertStringContainsString('LIMIT $', $sql, 'the page size still bounds POSTS, not links');
    }

    public function test_list_and_detail_publish_the_same_category_contract(): void
    {
        $list = new FakeQueryConnection();
        $list->queueResults([]);
        (new PostQueryProvider($list))->list(new ContentFilterSet());

        $detail = new FakeQueryConnection();
        $detail->queueResults([]);
        (new PostQueryProvider($detail))->findBySlug('hello');

        // Both read the same column set, which is what keeps /posts and /posts/{slug} on one
        // contract rather than two that drift.
        foreach (['AS categories_json', "t.taxonomy_type = 'category'", 'AS tags_json'] as $fragment) {
            self::assertStringContainsString($fragment, $list->sqlAt(0), "listing: {$fragment}");
            self::assertStringContainsString($fragment, $detail->sqlAt(0), "detail: {$fragment}");
        }
    }

    // -------------------------------------------------------------------------
    // Runtime ⇄ OpenAPI agreement
    // -------------------------------------------------------------------------

    public function test_the_descriptor_describes_the_nested_category_reference_shape(): void
    {
        foreach (['/posts', '/posts/{slug}'] as $route) {
            $schema = $this->postProperties($route)['categories'];

            self::assertSame('array', $schema['type'], "{$route} categories is a list");
            self::assertSame('object', $schema['items']['type'], "{$route} items are described");
            self::assertSame(['slug', 'name'], array_keys($schema['items']['properties']));
            self::assertSame(['slug', 'name'], $schema['items']['required']);
            self::assertSame('string', $schema['items']['properties']['slug']['type']);
            self::assertSame('string', $schema['items']['properties']['name']['type']);
        }
    }

    public function test_the_published_category_keys_match_the_descriptor_exactly(): void
    {
        $published = (new PostResource())->toArray([
            'slug'            => 'hello',
            'title'           => 'Hello',
            'content'         => '',
            'status'          => 'publish',
            'categories_json' => '[{"slug":"etf","name":"ETF"}]',
        ]);

        self::assertSame(
            array_keys($this->postProperties('/posts/{slug}')['categories']['items']['properties']),
            array_keys($published['categories'][0]),
            'A published category reference must match the descriptor item shape exactly.',
        );
    }

    public function test_the_descriptor_publishes_no_primary_category_property(): void
    {
        $properties = $this->postProperties('/posts/{slug}');

        foreach (['primary_category', 'primary_category_id', 'primary', 'is_primary', 'category'] as $forbidden) {
            self::assertArrayNotHasKey($forbidden, $properties);
        }
        self::assertArrayNotHasKey('id', $properties['categories']['items']['properties']);
    }

    public function test_categories_are_additive_and_no_existing_post_field_changed(): void
    {
        // The contract change is ADDITIVE. Freezing the key list here is what makes an
        // accidental rename or removal of an existing field fail rather than ship.
        $published = (new PostResource())->toArray($this->row(1));

        self::assertSame(
            ['slug', 'title', 'content', 'excerpt', 'status', 'author', 'published_at',
                'updated_at', 'meta', 'featured_media', 'tags', 'categories'],
            array_keys($published),
        );
    }

    /** @return array<string,mixed> */
    private function postProperties(string $route): array
    {
        foreach ((new ContentEndpointProvider())->endpoints() as $descriptor) {
            if ($descriptor->route !== $route) {
                continue;
            }
            self::assertNotNull($descriptor->responseSchema);
            $schema = $descriptor->responseSchema->schema;

            // A listing publishes the cursor envelope; the item shape sits under data.items.
            return $schema['properties']['data']['items']['properties']
                ?? $schema['properties'];
        }

        self::fail("no descriptor for {$route}");
    }

    /** @return array<string,mixed> */
    private function row(mixed $seed): array
    {
        return [
            'id'                => sprintf('01900000-0000-7000-8000-%012d', (int) $seed),
            'slug'              => 'post-' . $seed,
            'title'             => 'Post',
            'content'           => '',
            'excerpt'           => '',
            'status'            => 'publish',
            'author'            => 'editor',
            'published_at'      => '2024-01-02 03:04:05+00',
            'updated_at'        => '2024-01-02 03:04:05+00',
            'meta_jsonb'        => '{}',
            'featured_media_id' => '0',
            'tags_json'         => '[]',
            'categories_json'   => '[]',
        ];
    }
}
