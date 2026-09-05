<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Core\Contracts\FilterSet;
use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\Extractors\ProtectedMeta;
use HSP\Modules\Content\Queries\PostQueryProvider;
use HSP\Modules\Content\Resources\PostResource;
use HSP\Modules\Content\Transformers\PageTransformer;
use HSP\Modules\Content\Transformers\PostTransformer;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Tests\Unit\Content\Queries\FakeQueryConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Featured images (P1B-S2) — the two traps the session map names as DoD-blocking.
 *
 * 1. THE WRITE-SUPPRESS TRAP. A featured-image change touches no other field. If the canonical
 *    checksum does not incorporate the reference, the recomputed projection checksum equals the
 *    stored one, DECISION 3 correctly suppresses the upsert, and the change is silently lost.
 *    Made worse by `_thumbnail_id` being `_`-prefixed: ProtectedMeta::publicOnly() strips it, so
 *    it does not even reach `meta` — it has to be extracted deliberately.
 *
 * 2. N+1 ON RESOLUTION. Resolving the featured image per row turns one listing into N+1
 *    round-trips. The query count for a one-row page must equal a full-size page.
 */
final class FeaturedImageTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Trap 1 — extraction and the checksum
    // -------------------------------------------------------------------------

    public function test_thumbnail_id_is_stripped_from_published_meta(): void
    {
        // The raw value is WordPress bookkeeping and must never be published (Rule 6).
        $meta = ProtectedMeta::publicOnly([ProtectedMeta::THUMBNAIL_ID => '42', 'colour' => 'blue']);

        self::assertSame(['colour' => 'blue'], $meta);
    }

    public function test_thumbnail_id_is_still_extracted_as_a_typed_reference(): void
    {
        self::assertSame(42, ProtectedMeta::featuredMediaId([ProtectedMeta::THUMBNAIL_ID => '42']));
    }

    /** @return array<string, array{mixed, int}> */
    public static function provideThumbnailValues(): array
    {
        return [
            'absent'          => [null, 0],
            'empty string'    => ['', 0],
            'zero'            => ['0', 0],
            'negative'        => ['-3', 0],
            'non-numeric'     => ['nonsense', 0],
            'numeric string'  => ['42', 42],
            'integer'         => [42, 42],
            'raw meta array'  => [['42'], 42],
        ];
    }

    #[DataProvider('provideThumbnailValues')]
    public function test_featured_media_id_normalises_every_shape_wordpress_can_hand_over(mixed $raw, int $expected): void
    {
        $meta = $raw === null ? [] : [ProtectedMeta::THUMBNAIL_ID => $raw];

        self::assertSame($expected, ProtectedMeta::featuredMediaId($meta));
    }

    public function test_setting_a_featured_image_moves_the_post_checksum(): void
    {
        $extractor   = new PostExtractor(new PostValidator());
        $transformer = new PostTransformer();

        $without = $transformer->transform($extractor->extract($this->rawPost(), []));
        $with    = $transformer->transform($extractor->extract($this->rawPost(), [ProtectedMeta::THUMBNAIL_ID => '42']));

        self::assertSame(0, $without->featuredMediaId);
        self::assertSame(42, $with->featuredMediaId);
        self::assertNotSame(
            $without->getChecksum(),
            $with->getChecksum(),
            'Setting a featured image must move the checksum, or DECISION 3 suppresses the write '
            . 'and the change never reaches the projection.',
        );
    }

    public function test_replacing_and_clearing_a_featured_image_moves_the_post_checksum(): void
    {
        $extractor   = new PostExtractor(new PostValidator());
        $transformer = new PostTransformer();

        $a = $transformer->transform($extractor->extract($this->rawPost(), [ProtectedMeta::THUMBNAIL_ID => '42']));
        $b = $transformer->transform($extractor->extract($this->rawPost(), [ProtectedMeta::THUMBNAIL_ID => '99']));
        $c = $transformer->transform($extractor->extract($this->rawPost(), []));

        self::assertNotSame($a->getChecksum(), $b->getChecksum(), 'replacing the image moves the checksum');
        self::assertNotSame($a->getChecksum(), $c->getChecksum(), 'clearing the image moves the checksum');
    }

    public function test_setting_a_featured_image_moves_the_page_checksum(): void
    {
        $extractor   = new PageExtractor(new PageValidator());
        $transformer = new PageTransformer();

        $without = $transformer->transform($extractor->extract($this->rawPage(), []));
        $with    = $transformer->transform($extractor->extract($this->rawPage(), [ProtectedMeta::THUMBNAIL_ID => '42']));

        self::assertSame(42, $with->featuredMediaId);
        self::assertNotSame($without->getChecksum(), $with->getChecksum());
    }

    // -------------------------------------------------------------------------
    // Trap 2 — no N+1 on resolution
    // -------------------------------------------------------------------------

    public function test_a_listing_costs_one_query_at_any_page_size(): void
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
            'Featured images must resolve in a bounded number of queries: a per-row lookup would '
            . 'make a 20-row listing cost 21 round-trips.',
        );
    }

    public function test_the_listing_resolves_the_image_with_a_left_join_not_a_second_query(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([]);
        (new PostQueryProvider($db))->list(new FilterSet());

        $sql = $db->sqlAt(0);

        self::assertStringContainsString('LEFT JOIN content.media', $sql);
        // LEFT, not INNER: a post with no image, or whose attachment is gone, must still be served.
        self::assertStringNotContainsString('INNER JOIN content.media', $sql);
        // The join must exclude soft-deleted media, or a deleted attachment keeps being served.
        self::assertStringContainsString('fm.deleted_at IS NULL', $sql);
    }

    public function test_the_single_item_lookup_resolves_the_image_too(): void
    {
        $db = new FakeQueryConnection();
        $db->queueResults([$this->row(1)]);
        (new PostQueryProvider($db))->findBySlug('a-post');

        self::assertCount(1, $db->queries, 'one query, join included');
        self::assertStringContainsString('LEFT JOIN content.media', $db->sqlAt(0));
    }

    // -------------------------------------------------------------------------
    // Contract shape — a soft reference may always dangle
    // -------------------------------------------------------------------------

    public function test_resource_shapes_the_resolved_image(): void
    {
        $body = (new PostResource())->toArray($this->row(1, withMedia: true));

        self::assertIsArray($body['featured_media']);
        self::assertSame('https://example.test/uploads/sunset.jpg', $body['featured_media']['url']);
        self::assertSame('A sunset', $body['featured_media']['alt_text']);
        self::assertSame(1600, $body['featured_media']['width']);
        self::assertSame(
            'https://example.test/uploads/sunset-150x150.jpg',
            $body['featured_media']['sizes']['thumbnail']['url'],
            'size variants come through already resolved — the read path assembles nothing',
        );
    }

    public function test_resource_emits_null_when_no_image_is_set(): void
    {
        self::assertNull((new PostResource())->toArray($this->row(1))['featured_media']);
    }

    public function test_resource_emits_null_when_the_reference_dangles(): void
    {
        // featured_media_id points at an attachment that was deleted or never projected, so the
        // LEFT JOIN produced NULLs. The contract answers null — never a dangling id, never a 500.
        $row = $this->row(1);
        $row['featured_media_id'] = '404';

        self::assertNull((new PostResource())->toArray($row)['featured_media']);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function rawPost(): array
    {
        return [
            'ID' => 7, 'post_title' => 'A Post', 'post_content' => '<p>x</p>', 'post_excerpt' => '',
            'post_name' => 'a-post', 'post_status' => 'publish', 'post_type' => 'post',
            'post_author' => '1', 'post_date_gmt' => '2024-01-01 00:00:00',
            'post_modified_gmt' => '2024-01-01 00:00:00',
        ];
    }

    /** @return array<string,mixed> */
    private function rawPage(): array
    {
        return [
            'ID' => 8, 'post_title' => 'A Page', 'post_content' => '<p>x</p>',
            'post_name' => 'a-page', 'post_status' => 'publish', 'post_type' => 'page',
            'post_parent' => '0', 'menu_order' => '0',
            'post_date_gmt' => '2024-01-01 00:00:00', 'post_modified_gmt' => '2024-01-01 00:00:00',
        ];
    }

    /** @return array<string,mixed> */
    private function row(mixed $seed, bool $withMedia = false): array
    {
        $row = [
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
        ];

        if ($withMedia) {
            $row['featured_media_id'] = '42';
            $row['fm_slug']           = 'sunset';
            $row['fm_url']            = 'https://example.test/uploads/sunset.jpg';
            $row['fm_alt_text']       = 'A sunset';
            $row['fm_mime_type']      = 'image/jpeg';
            $row['fm_width']          = '1600';
            $row['fm_height']         = '900';
            $row['fm_sizes_jsonb']    = '{"thumbnail":{"url":"https://example.test/uploads/sunset-150x150.jpg","width":150,"height":150,"mime_type":"image/jpeg"}}';
        }

        return $row;
    }
}
