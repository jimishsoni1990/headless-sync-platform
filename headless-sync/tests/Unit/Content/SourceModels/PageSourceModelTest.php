<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\SourceModels;

use HSP\Modules\Content\SourceModels\PageSourceModel;
use PHPUnit\Framework\TestCase;

final class PageSourceModelTest extends TestCase
{
    public function test_all_fields_accessible(): void
    {
        $pub = new \DateTimeImmutable('2024-03-01 08:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-03-02 09:00:00', new \DateTimeZone('UTC'));

        $model = new PageSourceModel(
            postId:      7,
            title:       'About Us',
            content:     '<p>About</p>',
            slug:        'about-us',
            status:      'publish',
            parentId:    0,
            menuOrder:   2,
            publishedAt: $pub,
            modifiedAt:  $mod,
            meta:        ['_template' => 'full-width.php'],
        );

        $this->assertSame(7, $model->postId);
        $this->assertSame('About Us', $model->title);
        $this->assertSame('<p>About</p>', $model->content);
        $this->assertSame('about-us', $model->slug);
        $this->assertSame('publish', $model->status);
        $this->assertSame(0, $model->parentId);
        $this->assertSame(2, $model->menuOrder);
        $this->assertSame($pub, $model->publishedAt);
        $this->assertSame($mod, $model->modifiedAt);
        $this->assertSame(['_template' => 'full-width.php'], $model->meta);
    }

    public function test_is_immutable(): void
    {
        $model = new PageSourceModel(
            postId:      1,
            title:       'T',
            content:     'C',
            slug:        's',
            status:      'publish',
            parentId:    0,
            menuOrder:   0,
            publishedAt: new \DateTimeImmutable('now'),
            modifiedAt:  new \DateTimeImmutable('now'),
            meta:        [],
        );

        $ref = new \ReflectionClass($model);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly.");
        }
    }

    public function test_child_page_carries_parent_id(): void
    {
        $model = new PageSourceModel(
            postId:      10,
            title:       'Child',
            content:     '',
            slug:        'child',
            status:      'publish',
            parentId:    5,
            menuOrder:   0,
            publishedAt: new \DateTimeImmutable('now'),
            modifiedAt:  new \DateTimeImmutable('now'),
            meta:        [],
        );

        $this->assertSame(5, $model->parentId);
    }

    public function test_empty_meta_accepted(): void
    {
        $model = new PageSourceModel(
            postId:      1,
            title:       'T',
            content:     '',
            slug:        's',
            status:      'publish',
            parentId:    0,
            menuOrder:   0,
            publishedAt: new \DateTimeImmutable('now'),
            modifiedAt:  new \DateTimeImmutable('now'),
            meta:        [],
        );

        $this->assertSame([], $model->meta);
    }
}
