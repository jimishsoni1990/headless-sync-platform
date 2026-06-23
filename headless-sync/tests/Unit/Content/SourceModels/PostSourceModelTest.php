<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\SourceModels;

use HSP\Modules\Content\SourceModels\PostSourceModel;
use PHPUnit\Framework\TestCase;

final class PostSourceModelTest extends TestCase
{
    private function makeModel(array $overrides = []): PostSourceModel
    {
        $defaults = [
            'postId'      => 42,
            'title'       => 'Hello World',
            'content'     => '<p>Content</p>',
            'excerpt'     => 'Summary',
            'slug'        => 'hello-world',
            'status'      => 'publish',
            'author'      => 'admin',
            'publishedAt' => new \DateTimeImmutable('2024-01-15 10:00:00', new \DateTimeZone('UTC')),
            'modifiedAt'  => new \DateTimeImmutable('2024-01-16 12:00:00', new \DateTimeZone('UTC')),
            'categoryIds' => [1, 2],
            'meta'        => ['_key' => 'value'],
        ];
        $p = array_merge($defaults, $overrides);
        return new PostSourceModel(
            postId:      $p['postId'],
            title:       $p['title'],
            content:     $p['content'],
            excerpt:     $p['excerpt'],
            slug:        $p['slug'],
            status:      $p['status'],
            author:      $p['author'],
            publishedAt: $p['publishedAt'],
            modifiedAt:  $p['modifiedAt'],
            categoryIds: $p['categoryIds'],
            meta:        $p['meta'],
        );
    }

    public function test_all_fields_accessible(): void
    {
        $pub = new \DateTimeImmutable('2024-01-15 10:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-01-16 12:00:00', new \DateTimeZone('UTC'));

        $model = new PostSourceModel(
            postId:      42,
            title:       'Hello World',
            content:     '<p>Content</p>',
            excerpt:     'Summary',
            slug:        'hello-world',
            status:      'publish',
            author:      'admin',
            publishedAt: $pub,
            modifiedAt:  $mod,
            categoryIds: [1, 2],
            meta:        ['key' => 'val'],
        );

        $this->assertSame(42, $model->postId);
        $this->assertSame('Hello World', $model->title);
        $this->assertSame('<p>Content</p>', $model->content);
        $this->assertSame('Summary', $model->excerpt);
        $this->assertSame('hello-world', $model->slug);
        $this->assertSame('publish', $model->status);
        $this->assertSame('admin', $model->author);
        $this->assertSame($pub, $model->publishedAt);
        $this->assertSame($mod, $model->modifiedAt);
        $this->assertSame([1, 2], $model->categoryIds);
        $this->assertSame(['key' => 'val'], $model->meta);
    }

    public function test_is_immutable(): void
    {
        $model = $this->makeModel();

        // Readonly properties cannot be assigned — verify via reflection that no setters exist.
        $ref = new \ReflectionClass($model);
        $this->assertEmpty(
            array_filter(
                $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
                fn($m) => str_starts_with($m->getName(), 'set'),
            ),
            'PostSourceModel must have no public setter methods.'
        );

        // Verify readonly flag on every property.
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue(
                $prop->isReadOnly(),
                "Property {$prop->getName()} must be readonly."
            );
        }
    }

    public function test_empty_category_ids_and_meta_accepted(): void
    {
        $model = $this->makeModel(['categoryIds' => [], 'meta' => []]);
        $this->assertSame([], $model->categoryIds);
        $this->assertSame([], $model->meta);
    }

    public function test_multiple_category_ids(): void
    {
        $model = $this->makeModel(['categoryIds' => [5, 10, 15]]);
        $this->assertSame([5, 10, 15], $model->categoryIds);
    }
}
