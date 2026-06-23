<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Extractors;

use HSP\Modules\Content\Extractors\PageExtractor;
use HSP\Modules\Content\SourceModels\PageSourceModel;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class PageExtractorTest extends TestCase
{
    private PageExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PageExtractor(new PageValidator());
    }

    private function validRaw(array $overrides = []): array
    {
        return array_merge([
            'ID'                => '7',
            'post_type'         => 'page',
            'post_name'         => 'about-us',
            'post_status'       => 'publish',
            'post_title'        => 'About Us',
            'post_content'      => '<p>About</p>',
            'post_date_gmt'     => '2024-03-01 08:00:00',
            'post_modified_gmt' => '2024-03-02 09:00:00',
            'post_parent'       => '0',
            'menu_order'        => '2',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_extract_returns_page_source_model(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertInstanceOf(PageSourceModel::class, $model);
    }

    public function test_extract_maps_id_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(['ID' => '100']));
        $this->assertSame(100, $model->postId);
    }

    public function test_extract_maps_title(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_title' => 'Contact']));
        $this->assertSame('Contact', $model->title);
    }

    public function test_extract_maps_content(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_content' => '<p>Body</p>']));
        $this->assertSame('<p>Body</p>', $model->content);
    }

    public function test_extract_maps_slug(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_name' => 'contact-us']));
        $this->assertSame('contact-us', $model->slug);
    }

    public function test_extract_maps_status(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_status' => 'draft']));
        $this->assertSame('draft', $model->status);
    }

    public function test_extract_maps_parent_id_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(['post_parent' => '5']));
        $this->assertSame(5, $model->parentId);
    }

    public function test_extract_defaults_parent_id_to_zero_when_absent(): void
    {
        $raw = $this->validRaw();
        unset($raw['post_parent']);
        $model = $this->extractor->extract($raw);
        $this->assertSame(0, $model->parentId);
    }

    public function test_extract_maps_menu_order_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(['menu_order' => '3']));
        $this->assertSame(3, $model->menuOrder);
    }

    public function test_extract_defaults_menu_order_to_zero_when_absent(): void
    {
        $raw = $this->validRaw();
        unset($raw['menu_order']);
        $model = $this->extractor->extract($raw);
        $this->assertSame(0, $model->menuOrder);
    }

    public function test_extract_parses_dates_as_utc(): void
    {
        $model = $this->extractor->extract($this->validRaw([
            'post_date_gmt'     => '2024-05-10 06:00:00',
            'post_modified_gmt' => '2024-05-11 07:00:00',
        ]));
        $this->assertSame('UTC', $model->publishedAt->getTimezone()->getName());
        $this->assertSame('2024-05-10 06:00:00', $model->publishedAt->format('Y-m-d H:i:s'));
        $this->assertSame('2024-05-11 07:00:00', $model->modifiedAt->format('Y-m-d H:i:s'));
    }

    public function test_extract_normalizes_meta_values_to_string(): void
    {
        $model = $this->extractor->extract($this->validRaw(), ['_template' => 'full-width.php', '_order' => 3]);
        $this->assertSame('full-width.php', $model->meta['_template']);
        $this->assertSame('3', $model->meta['_order']);
    }

    public function test_extract_with_empty_meta(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertSame([], $model->meta);
    }

    public function test_page_model_has_no_excerpt_or_category_ids(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertFalse(property_exists($model, 'excerpt'));
        $this->assertFalse(property_exists($model, 'categoryIds'));
    }

    public function test_extracted_model_is_immutable(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $ref   = new \ReflectionClass($model);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly.");
        }
    }

    // -------------------------------------------------------------------------
    // Fail-fast paths
    // -------------------------------------------------------------------------

    public function test_missing_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['ID' => 0]));
    }

    public function test_missing_slug_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['post_name' => '']));
    }

    public function test_wrong_post_type_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['post_type' => 'post']));
    }

    public function test_invalid_date_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['post_date_gmt' => 'garbage']));
    }

    public function test_extracted_model_has_no_checksum_property(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertFalse(property_exists($model, 'checksum'));
    }
}
