<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Extractors;

use HSP\Modules\Content\Extractors\PostExtractor;
use HSP\Modules\Content\SourceModels\PostSourceModel;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Modules\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class PostExtractorTest extends TestCase
{
    private PostExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PostExtractor(new PostValidator());
    }

    private function validRaw(array $overrides = []): array
    {
        return array_merge([
            'ID'                => '42',
            'post_type'         => 'post',
            'post_name'         => 'hello-world',
            'post_status'       => 'publish',
            'post_title'        => 'Hello World',
            'post_content'      => '<p>Content</p>',
            'post_excerpt'      => 'Summary',
            'post_date_gmt'     => '2024-01-15 10:00:00',
            'post_modified_gmt' => '2024-01-16 12:00:00',
            'post_author'       => '1',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_extract_returns_post_source_model(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertInstanceOf(PostSourceModel::class, $model);
    }

    public function test_extract_maps_id_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(['ID' => '99']));
        $this->assertSame(99, $model->postId);
    }

    public function test_extract_maps_title(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_title' => 'My Title']));
        $this->assertSame('My Title', $model->title);
    }

    public function test_extract_maps_content(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_content' => '<p>Hello</p>']));
        $this->assertSame('<p>Hello</p>', $model->content);
    }

    public function test_extract_maps_excerpt(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_excerpt' => 'Teaser']));
        $this->assertSame('Teaser', $model->excerpt);
    }

    public function test_extract_maps_slug(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_name' => 'my-slug']));
        $this->assertSame('my-slug', $model->slug);
    }

    public function test_extract_maps_status(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_status' => 'draft']));
        $this->assertSame('draft', $model->status);
    }

    public function test_extract_maps_author_from_post_author_login(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_author_login' => 'jimish']));
        $this->assertSame('jimish', $model->author);
    }

    public function test_extract_falls_back_to_post_author_when_login_absent(): void
    {
        $raw = $this->validRaw();
        unset($raw['post_author_login']);
        $raw['post_author'] = '7';
        $model = $this->extractor->extract($raw);
        $this->assertSame('7', $model->author);
    }

    public function test_extract_parses_published_at_as_utc(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_date_gmt' => '2024-06-01 14:30:00']));
        $this->assertSame('UTC', $model->publishedAt->getTimezone()->getName());
        $this->assertSame('2024-06-01 14:30:00', $model->publishedAt->format('Y-m-d H:i:s'));
    }

    public function test_extract_parses_modified_at_as_utc(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_modified_gmt' => '2024-07-10 09:00:00']));
        $this->assertSame('UTC', $model->modifiedAt->getTimezone()->getName());
        $this->assertSame('2024-07-10 09:00:00', $model->modifiedAt->format('Y-m-d H:i:s'));
    }

    public function test_zero_datetime_sentinel_produces_now_fallback(): void
    {
        $before = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $model  = $this->extractor->extract($this->validRaw(['post_date_gmt' => '0000-00-00 00:00:00']));
        $after  = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $model->publishedAt->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $model->publishedAt->getTimestamp());
    }

    public function test_extract_normalizes_category_ids_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(), [], ['3', '7', '11']);
        $this->assertSame([3, 7, 11], $model->categoryIds);
    }

    public function test_extract_normalizes_meta_values_to_string(): void
    {
        $model = $this->extractor->extract($this->validRaw(), ['_num' => 42, '_flag' => true]);
        $this->assertSame('42', $model->meta['_num']);
        $this->assertSame('1', $model->meta['_flag']);
    }

    public function test_extract_with_empty_meta_and_categories(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertSame([], $model->categoryIds);
        $this->assertSame([], $model->meta);
    }

    public function test_extract_returns_immutable_source_model(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $ref   = new \ReflectionClass($model);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly.");
        }
    }

    // -------------------------------------------------------------------------
    // Fail-fast paths (delegated to validator)
    // -------------------------------------------------------------------------

    public function test_missing_id_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['ID' => null]));
    }

    public function test_invalid_id_zero_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['ID' => 0]));
    }

    public function test_missing_slug_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['post_name' => '']));
    }

    public function test_missing_status_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['post_status' => '']));
    }

    public function test_wrong_post_type_throws_validation_exception(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['post_type' => 'page']));
    }

    public function test_invalid_datetime_string_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['post_date_gmt' => 'not-a-date']));
    }

    // -------------------------------------------------------------------------
    // No canonical model, checksum, or projection output
    // -------------------------------------------------------------------------

    public function test_extracted_model_has_no_checksum_property(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertFalse(
            property_exists($model, 'checksum'),
            'PostSourceModel must not carry a checksum (that is adapter/projection concern).'
        );
    }
}
