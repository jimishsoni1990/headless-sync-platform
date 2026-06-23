<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Extractors;

use HSP\Modules\Content\Extractors\CategoryExtractor;
use HSP\Modules\Content\SourceModels\CategorySourceModel;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class CategoryExtractorTest extends TestCase
{
    private CategoryExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new CategoryExtractor(new CategoryValidator());
    }

    private function validRaw(array $overrides = []): array
    {
        return array_merge([
            'term_id'     => '3',
            'name'        => 'Technology',
            'slug'        => 'technology',
            'description' => 'Tech articles',
            'parent'      => '0',
            'count'       => '12',
            'taxonomy'    => 'category',
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_extract_returns_category_source_model(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertInstanceOf(CategorySourceModel::class, $model);
    }

    public function test_extract_maps_term_id_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(['term_id' => '99']));
        $this->assertSame(99, $model->termId);
    }

    public function test_extract_maps_name(): void
    {
        $model = $this->extractor->extract($this->validRaw(['name' => 'Science']));
        $this->assertSame('Science', $model->name);
    }

    public function test_extract_maps_slug(): void
    {
        $model = $this->extractor->extract($this->validRaw(['slug' => 'science']));
        $this->assertSame('science', $model->slug);
    }

    public function test_extract_maps_description(): void
    {
        $model = $this->extractor->extract($this->validRaw(['description' => 'Science news']));
        $this->assertSame('Science news', $model->description);
    }

    public function test_extract_maps_empty_description(): void
    {
        $model = $this->extractor->extract($this->validRaw(['description' => '']));
        $this->assertSame('', $model->description);
    }

    public function test_extract_maps_parent_id_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(['parent' => '5']));
        $this->assertSame(5, $model->parentId);
    }

    public function test_extract_defaults_parent_to_zero_when_absent(): void
    {
        $raw = $this->validRaw();
        unset($raw['parent']);
        $model = $this->extractor->extract($raw);
        $this->assertSame(0, $model->parentId);
    }

    public function test_extract_maps_count_to_int(): void
    {
        $model = $this->extractor->extract($this->validRaw(['count' => '7']));
        $this->assertSame(7, $model->count);
    }

    public function test_extract_defaults_count_to_zero_when_absent(): void
    {
        $raw = $this->validRaw();
        unset($raw['count']);
        $model = $this->extractor->extract($raw);
        $this->assertSame(0, $model->count);
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

    public function test_missing_term_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['term_id' => null]));
    }

    public function test_zero_term_id_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['term_id' => 0]));
    }

    public function test_missing_name_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['name' => '']));
    }

    public function test_missing_slug_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['slug' => '']));
    }

    public function test_wrong_taxonomy_throws(): void
    {
        $this->expectException(ValidationException::class);
        $this->extractor->extract($this->validRaw(['taxonomy' => 'post_tag']));
    }

    public function test_extracted_model_has_no_checksum_property(): void
    {
        $model = $this->extractor->extract($this->validRaw());
        $this->assertFalse(property_exists($model, 'checksum'));
    }
}
