<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Core\Contracts\FilterSet;
use HSP\Modules\Content\Queries\CategoryQueryProvider;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Tests\Unit\Content\Queries\FakeQueryConnection;
use PHPUnit\Framework\TestCase;

/**
 * Tags (P1B-S3) — the `post_tag` taxonomy riding the existing taxonomy projection.
 *
 * The design constraint under test: tags add NO table. They are rows in content.taxonomies with
 * taxonomy_type='post_tag', joined to entities through the existing content.entity_taxonomies.
 * Everything here guards a way that could go wrong:
 *   - a slug collision between a tag and a category resolving to the wrong one;
 *   - the tag filter or listing forgetting the taxonomy_type predicate;
 *   - returning a post's tags costing one query per post (N+1).
 */
final class TagsTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Shared projection, told apart by taxonomy_type
    // -------------------------------------------------------------------------

    public function test_a_tag_and_a_category_are_distinguished_by_taxonomy_type_alone(): void
    {
        $extractor   = new CategoryExtractor(new CategoryValidator());
        $transformer = new CategoryTransformer();

        $raw = ['term_id' => 9, 'name' => 'News', 'slug' => 'news', 'description' => '', 'parent' => 0, 'count' => 2];

        $category = $transformer->transform($extractor->extract($raw + ['taxonomy' => 'category']));
        $tag      = $transformer->transform($extractor->extract($raw + ['taxonomy' => 'post_tag']));

        self::assertSame('category', $category->taxonomyType);
        self::assertSame('post_tag', $tag->taxonomyType);

        // Same term data, different taxonomy → DIFFERENT checksum, or the shared projection
        // could not tell the two rows apart and one would suppress the other's write.
        self::assertNotSame($category->getChecksum(), $tag->getChecksum());
    }

    // -------------------------------------------------------------------------
    // Listings are scoped by taxonomy — a slug collision must not leak
    // -------------------------------------------------------------------------

    public function test_the_tag_listing_binds_post_tag_not_category(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new CategoryQueryProvider($db, 'post_tag'))->list(new FilterSet());

        self::assertStringContainsString('taxonomy_type = $', $db->sqlAt(0));
        self::assertContains('post_tag', $db->paramsAt(0));
        self::assertNotContains('category', $db->paramsAt(0));
    }

    public function test_the_tag_single_lookup_is_scoped_to_post_tag(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new CategoryQueryProvider($db, 'post_tag'))->findBySlug('news');

        // WordPress only guarantees slug uniqueness WITHIN a taxonomy, so a tag "news" and a
        // category "news" coexist: /tags/news must never resolve to the category.
        self::assertSame(['news', 'post_tag'], $db->paramsAt(0));
    }

    public function test_the_category_listing_is_unchanged_and_still_scoped_to_category(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new CategoryQueryProvider($db))->list(new FilterSet());

        self::assertContains('category', $db->paramsAt(0));
    }

    // -------------------------------------------------------------------------
    // Filtering posts by tag
    // -------------------------------------------------------------------------

    public function test_the_tag_filter_constrains_both_slug_and_taxonomy_type(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new PostQueryProvider($db))->list(new FilterSet(tagSlug: 'news'));

        $sql = $db->sqlAt(0);
        self::assertStringContainsString('EXISTS (', $sql);
        self::assertStringContainsString("t.taxonomy_type = 'post_tag'", $sql);
        self::assertContains('news', $db->paramsAt(0));
    }

    public function test_the_category_filter_now_also_constrains_taxonomy_type(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new PostQueryProvider($db))->list(new FilterSet(categorySlug: 'news'));

        // Before tags existed this predicate was unnecessary; with both taxonomies in one table
        // its absence would let ?category=news match a TAG named news.
        self::assertStringContainsString("t.taxonomy_type = 'category'", $db->sqlAt(0));
    }

    public function test_category_and_tag_filters_compose(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new PostQueryProvider($db))->list(new FilterSet(categorySlug: 'guides', tagSlug: 'news'));

        $sql = $db->sqlAt(0);
        self::assertSame(2, substr_count($sql, 'EXISTS ('), 'both filters applied, ANDed');
        self::assertContains('guides', $db->paramsAt(0));
        self::assertContains('news', $db->paramsAt(0));
    }

    public function test_an_unfiltered_listing_applies_no_taxonomy_predicate(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);

        (new PostQueryProvider($db))->list(new FilterSet());

        self::assertStringNotContainsString('EXISTS (', $db->sqlAt(0));
    }

    // -------------------------------------------------------------------------
    // Performance DoD — returning a post's tags must not be N+1
    // -------------------------------------------------------------------------

    public function test_returning_tags_costs_one_query_at_any_page_size(): void
    {
        $oneRow = new FakeQueryConnection();
        $oneRow->queueResults([$this->row(1)]);
        (new PostQueryProvider($oneRow))->list(new FilterSet(limit: 20));

        $fullPage = new FakeQueryConnection();
        $fullPage->queueResults(array_map($this->row(...), range(1, 20)));
        (new PostQueryProvider($fullPage))->list(new FilterSet(limit: 20));

        self::assertCount(1, $oneRow->queries);
        self::assertCount(
            count($oneRow->queries),
            $fullPage->queries,
            'Tags are aggregated in SQL precisely so a listing stays at one round-trip; fetching '
            . "each post's tags separately would make a 20-row page cost 21 queries.",
        );
    }

    public function test_the_tags_aggregate_is_scoped_and_ordered(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);
        (new PostQueryProvider($db))->list(new FilterSet());

        $sql = $db->sqlAt(0);
        self::assertStringContainsString('json_agg', $sql);
        self::assertStringContainsString("t.taxonomy_type = 'post_tag'", $sql);
        self::assertStringContainsString('t.deleted_at IS NULL', $sql, 'a deleted tag must drop out');
        self::assertStringContainsString('ORDER BY t.slug', $sql, 'deterministic published order');
    }

    // -------------------------------------------------------------------------
    // Published contract
    // -------------------------------------------------------------------------

    public function test_a_post_publishes_its_tags(): void
    {
        $row = $this->row(1);
        $row['tags_json'] = '[{"slug":"news","name":"News"},{"slug":"php","name":"PHP"}]';

        $body = (new PostResource())->toArray($row);

        self::assertCount(2, $body['tags']);
        self::assertSame('news', $body['tags'][0]['slug']);
        self::assertSame('News', $body['tags'][0]['name']);
    }

    public function test_an_untagged_post_publishes_an_empty_array_not_null(): void
    {
        // Always a list: consumers iterate without a null check.
        self::assertSame([], (new PostResource())->toArray($this->row(1))['tags']);
    }

    public function test_malformed_tag_json_degrades_to_an_empty_array(): void
    {
        $row = $this->row(1);
        $row['tags_json'] = 'not json';

        self::assertSame([], (new PostResource())->toArray($row)['tags']);
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
        ];
    }
}
