<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Transformers;

use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use HSP\Modules\Content\SourceModels\PostSourceModel;
use HSP\Modules\Content\Transformers\PostTransformer;
use PHPUnit\Framework\TestCase;

final class PostTransformerTest extends TestCase
{
    private PostTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new PostTransformer();
    }

    private function makeSource(array $overrides = []): PostSourceModel
    {
        $defaults = [
            'postId'      => 42,
            'title'       => 'Hello World',
            'content'     => '<p>Body</p>',
            'excerpt'     => 'Summary',
            'slug'        => 'hello-world',
            'status'      => 'publish',
            'author'      => 'admin',
            'publishedAt' => new \DateTimeImmutable('2024-03-01 09:00:00', new \DateTimeZone('UTC')),
            'modifiedAt'  => new \DateTimeImmutable('2024-03-02 10:00:00', new \DateTimeZone('UTC')),
            'categoryIds' => [1, 2],
            'meta'        => ['_custom' => 'val'],
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

    public function test_implements_transformer_interface(): void
    {
        $this->assertInstanceOf(TransformerInterface::class, $this->transformer);
    }

    public function test_get_canonical_model_class(): void
    {
        $this->assertSame(CanonicalPost::class, $this->transformer->getCanonicalModelClass());
    }

    public function test_transform_returns_canonical_post(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $this->assertInstanceOf(CanonicalPost::class, $canonical);
    }

    public function test_all_fields_mapped_correctly(): void
    {
        $pub = new \DateTimeImmutable('2024-03-01 09:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-03-02 10:00:00', new \DateTimeZone('UTC'));
        $source = $this->makeSource([
            'postId'      => 7,
            'title'       => 'My Post',
            'content'     => '<p>Content</p>',
            'excerpt'     => 'Excerpt',
            'slug'        => 'my-post',
            'status'      => 'publish',
            'author'      => 'editor',
            'publishedAt' => $pub,
            'modifiedAt'  => $mod,
            'categoryIds' => [3, 5],
            'meta'        => ['key' => 'val'],
        ]);

        $canonical = $this->transformer->transform($source);

        $this->assertSame(7, $canonical->postId);
        $this->assertSame('My Post', $canonical->title);
        $this->assertSame('<p>Content</p>', $canonical->content);
        $this->assertSame('Excerpt', $canonical->excerpt);
        $this->assertSame('my-post', $canonical->slug);
        $this->assertSame('publish', $canonical->status);
        $this->assertSame('editor', $canonical->author);
        $this->assertSame($pub, $canonical->publishedAt);
        $this->assertSame($mod, $canonical->modifiedAt);
        $this->assertSame([3, 5], $canonical->categoryIds);
        $this->assertSame(['key' => 'val'], $canonical->meta);
    }

    public function test_title_is_trimmed(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['title' => '  Spaced  ']));
        $this->assertSame('Spaced', $canonical->title);
    }

    public function test_empty_category_ids_and_meta_preserved(): void
    {
        $canonical = $this->transformer->transform($this->makeSource([
            'categoryIds' => [],
            'meta'        => [],
        ]));
        $this->assertSame([], $canonical->categoryIds);
        $this->assertSame([], $canonical->meta);
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
        $with    = $this->transformer->transform($source, ['event_id' => 'abc123', 'aggregate_version' => 5]);

        $this->assertSame($without->getChecksum(), $with->getChecksum());
    }

    public function test_get_source_id_returns_post_id(): void
    {
        $canonical = $this->transformer->transform($this->makeSource(['postId' => 99]));
        $this->assertSame(99, $canonical->getSourceId());
    }

    public function test_checksum_is_64_hex_characters(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $canonical->getChecksum());
    }

    public function test_different_inputs_produce_different_checksums(): void
    {
        $a = $this->transformer->transform($this->makeSource(['title' => 'Post A']));
        $b = $this->transformer->transform($this->makeSource(['title' => 'Post B']));
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_canonical_post_is_immutable(): void
    {
        $canonical = $this->transformer->transform($this->makeSource());
        $ref = new \ReflectionClass($canonical);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly.");
        }
    }

    public function test_no_wordpress_functions_called(): void
    {
        // Pure-function guarantee: no WP functions exist in the test env; transform must succeed.
        $source = $this->makeSource();
        $canonical = $this->transformer->transform($source);
        $this->assertInstanceOf(CanonicalPost::class, $canonical);
    }
}
