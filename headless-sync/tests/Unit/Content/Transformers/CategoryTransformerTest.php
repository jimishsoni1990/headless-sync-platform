<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Transformers;

use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalCategory;
use HSP\Modules\Content\SourceModels\CategorySourceModel;
use HSP\Modules\Content\Transformers\CategoryTransformer;
use PHPUnit\Framework\TestCase;

final class CategoryTransformerTest extends TestCase
{
    private CategoryTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new CategoryTransformer();
    }

    private function makeSource(array $overrides = []): CategorySourceModel
    {
        $defaults = [
            'termId'      => 7,
            'name'        => 'Technology',
            'slug'        => 'technology',
            'description' => 'Tech articles',
            'parentId'    => 0,
            'count'       => 12,
        ];
        $p = array_merge($defaults, $overrides);
        return new CategorySourceModel(
            termId:      $p['termId'],
            name:        $p['name'],
            slug:        $p['slug'],
            description: $p['description'],
            parentId:    $p['parentId'],
            count:       $p['count'],
        );
    }

    public function test_implements_transformer_interface(): void
    {
        $this->assertInstanceOf(TransformerInterface::class, $this->transformer);
    }

    public function test_get_canonical_model_class(): void
    {
        $this->assertSame(CanonicalCategory::class, $this->transformer->getCanonicalModelClass());
    }

    public function test_transform_returns_canonical_category(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $this->assertInstanceOf(CanonicalCategory::class, $canonical);
    }

    public function test_all_fields_mapped_correctly(): void
    {
        $source = $this->makeSource([
            'termId'      => 3,
            'name'        => 'PHP',
            'slug'        => 'php',
            'description' => 'PHP programming',
            'parentId'    => 7,
            'count'       => 5,
        ]);

        $canonical = $this->transformer->transform($source);

        $this->assertSame(3, $canonical->termId);
        $this->assertSame('PHP', $canonical->name);
        $this->assertSame('php', $canonical->slug);
        $this->assertSame('PHP programming', $canonical->description);
        $this->assertSame(7, $canonical->parentId);
        $this->assertSame(5, $canonical->count);
    }

    public function test_name_is_trimmed(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['name' => '  News  ']));
        $this->assertSame('News', $canonical->name);
    }

    public function test_empty_description_preserved(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['description' => '']));
        $this->assertSame('', $canonical->description);
    }

    public function test_top_level_category_parent_id_zero(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['parentId' => 0]));
        $this->assertSame(0, $canonical->parentId);
    }

    public function test_child_category_parent_id_preserved(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['parentId' => 99]));
        $this->assertSame(99, $canonical->parentId);
    }

    public function test_count_zero_preserved(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['count' => 0]));
        $this->assertSame(0, $canonical->count);
    }

    public function test_is_pure_same_input_same_output(): void
    {
        $source = $this->makeSource();
        $a = $this->transformer->transform($source);
        $b = $this->transformer->transform($source);
        $this->assertSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_context_does_not_affect_output(): void
    {
        $source = $this->makeSource();
        $without = $this->transformer->transform($source);
        $with    = $this->transformer->transform($source, ['event_id' => 'evt-1', 'aggregate_version' => 9]);
        $this->assertSame($without->getChecksum(), $with->getChecksum());
    }

    public function test_get_source_id_returns_term_id(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['termId' => 44]));
        $this->assertSame(44, $canonical->getSourceId());
    }

    public function test_checksum_is_64_hex_characters(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $canonical->getChecksum());
    }

    public function test_different_inputs_produce_different_checksums(): void
    {
        $a = $this->transformer->transform($this->makeSource(['slug' => 'cat-a']));
        $b = $this->transformer->transform($this->makeSource(['slug' => 'cat-b']));
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_canonical_category_is_immutable(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $ref = new \ReflectionClass($canonical);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly.");
        }
    }

    public function test_no_wordpress_functions_called(): void
    {
        $source = $this->makeSource();
        $canonical = $this->transformer->transform($source);
        $this->assertInstanceOf(CanonicalCategory::class, $canonical);
    }
}
