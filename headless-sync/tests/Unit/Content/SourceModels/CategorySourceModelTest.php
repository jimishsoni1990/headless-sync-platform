<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\SourceModels;

use HSP\Modules\Content\SourceModels\CategorySourceModel;
use PHPUnit\Framework\TestCase;

final class CategorySourceModelTest extends TestCase
{
    public function test_all_fields_accessible(): void
    {
        $model = new CategorySourceModel(
            termId:      3,
            name:        'Technology',
            slug:        'technology',
            description: 'Tech articles',
            parentId:    0,
            count:       12,
        );

        $this->assertSame(3, $model->termId);
        $this->assertSame('Technology', $model->name);
        $this->assertSame('technology', $model->slug);
        $this->assertSame('Tech articles', $model->description);
        $this->assertSame(0, $model->parentId);
        $this->assertSame(12, $model->count);
    }

    public function test_is_immutable(): void
    {
        $model = new CategorySourceModel(
            termId:      1,
            name:        'N',
            slug:        's',
            description: '',
            parentId:    0,
            count:       0,
        );

        $ref = new \ReflectionClass($model);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly.");
        }
    }

    public function test_child_category_carries_parent_id(): void
    {
        $model = new CategorySourceModel(
            termId:      9,
            name:        'PHP',
            slug:        'php',
            description: '',
            parentId:    3,
            count:       5,
        );

        $this->assertSame(3, $model->parentId);
    }

    public function test_empty_description_accepted(): void
    {
        $model = new CategorySourceModel(
            termId:      2,
            name:        'Uncategorized',
            slug:        'uncategorized',
            description: '',
            parentId:    0,
            count:       0,
        );

        $this->assertSame('', $model->description);
    }
}
