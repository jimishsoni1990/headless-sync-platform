<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Transformers;

use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use HSP\Modules\Content\SourceModels\PageSourceModel;
use HSP\Modules\Content\Transformers\PageTransformer;
use PHPUnit\Framework\TestCase;

final class PageTransformerTest extends TestCase
{
    private PageTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new PageTransformer();
    }

    private function makeSource(array $overrides = []): PageSourceModel
    {
        $defaults = [
            'postId'      => 10,
            'title'       => 'About Us',
            'content'     => '<p>About page</p>',
            'slug'        => 'about-us',
            'status'      => 'publish',
            'parentId'    => 0,
            'menuOrder'   => 0,
            'publishedAt' => new \DateTimeImmutable('2024-01-01 08:00:00', new \DateTimeZone('UTC')),
            'modifiedAt'  => new \DateTimeImmutable('2024-01-02 08:00:00', new \DateTimeZone('UTC')),
            'meta'        => [],
        ];
        $p = array_merge($defaults, $overrides);
        return new PageSourceModel(
            postId:      $p['postId'],
            title:       $p['title'],
            content:     $p['content'],
            slug:        $p['slug'],
            status:      $p['status'],
            parentId:    $p['parentId'],
            menuOrder:   $p['menuOrder'],
            publishedAt: $p['publishedAt'],
            modifiedAt:  $p['modifiedAt'],
            meta:        $p['meta'],
        );
    }

    public function test_implements_transformer_interface(): void
    {
        $this->assertInstanceOf(TransformerInterface::class, $this->transformer);
    }

    public function test_get_canonical_model_class(): void
    {
        $this->assertSame(CanonicalPage::class, $this->transformer->getCanonicalModelClass());
    }

    public function test_transform_returns_canonical_page(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $this->assertInstanceOf(CanonicalPage::class, $canonical);
    }

    public function test_all_fields_mapped_correctly(): void
    {
        $pub = new \DateTimeImmutable('2024-01-01 08:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-01-02 08:00:00', new \DateTimeZone('UTC'));
        $source = $this->makeSource([
            'postId'      => 5,
            'title'       => 'Contact',
            'content'     => '<p>Contact page</p>',
            'slug'        => 'contact',
            'status'      => 'publish',
            'parentId'    => 3,
            'menuOrder'   => 2,
            'publishedAt' => $pub,
            'modifiedAt'  => $mod,
            'meta'        => ['_seo' => 'contact'],
        ]);

        $canonical = $this->transformer->transform($source);

        $this->assertSame(5, $canonical->postId);
        $this->assertSame('Contact', $canonical->title);
        $this->assertSame('<p>Contact page</p>', $canonical->content);
        $this->assertSame('contact', $canonical->slug);
        $this->assertSame('publish', $canonical->status);
        $this->assertSame(3, $canonical->parentId);
        $this->assertSame(2, $canonical->menuOrder);
        $this->assertSame($pub, $canonical->publishedAt);
        $this->assertSame($mod, $canonical->modifiedAt);
        $this->assertSame(['_seo' => 'contact'], $canonical->meta);
    }

    public function test_title_is_trimmed(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['title' => '  Home  ']));
        $this->assertSame('Home', $canonical->title);
    }

    public function test_top_level_page_parent_id_zero(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['parentId' => 0]));
        $this->assertSame(0, $canonical->parentId);
    }

    public function test_child_page_parent_id_preserved(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['parentId' => 12]));
        $this->assertSame(12, $canonical->parentId);
    }

    public function test_menu_order_preserved(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['menuOrder' => 5]));
        $this->assertSame(5, $canonical->menuOrder);
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
        $with    = $this->transformer->transform($source, ['event_id' => 'xyz', 'aggregate_version' => 3]);
        $this->assertSame($without->getChecksum(), $with->getChecksum());
    }

    public function test_get_source_id_returns_post_id(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['postId' => 55]));
        $this->assertSame(55, $canonical->getSourceId());
    }

    public function test_checksum_is_64_hex_characters(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $canonical->getChecksum());
    }

    public function test_different_inputs_produce_different_checksums(): void
    {
        $a = $this->transformer->transform($this->makeSource(['slug' => 'page-a']));
        $b = $this->transformer->transform($this->makeSource(['slug' => 'page-b']));
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_canonical_page_is_immutable(): void
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
        $this->assertInstanceOf(CanonicalPage::class, $canonical);
    }
}
