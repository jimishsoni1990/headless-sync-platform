<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use PHPUnit\Framework\TestCase;

final class CanonicalPostTest extends TestCase
{
    private function make(array $overrides = []): CanonicalPost
    {
        $defaults = [
            'postId'      => 1,
            'title'       => 'A Title',
            'content'     => '<p>Body</p>',
            'excerpt'     => 'Excerpt',
            'slug'        => 'a-title',
            'status'      => 'publish',
            'author'      => 'admin',
            'publishedAt' => new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            'modifiedAt'  => new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
            'categoryIds' => [1],
            'meta'        => [],
        ];
        $p = array_merge($defaults, $overrides);
        return new CanonicalPost(
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

    public function test_implements_canonical_model_interface(): void
    {
        $this->assertInstanceOf(CanonicalModelInterface::class, $this->make());
    }

    public function test_get_source_id_returns_post_id(): void
    {
        $this->assertSame(42, $this->make(['postId' => 42])->getSourceId());
    }

    public function test_all_properties_accessible(): void
    {
        $pub = new \DateTimeImmutable('2024-02-01 12:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-02-02 12:00:00', new \DateTimeZone('UTC'));
        $m = $this->make([
            'postId'      => 10,
            'title'       => 'T',
            'content'     => 'C',
            'excerpt'     => 'E',
            'slug'        => 's',
            'status'      => 'publish',
            'author'      => 'bob',
            'publishedAt' => $pub,
            'modifiedAt'  => $mod,
            'categoryIds' => [2, 3],
            'meta'        => ['k' => 'v'],
        ]);
        $this->assertSame(10, $m->postId);
        $this->assertSame('T', $m->title);
        $this->assertSame('C', $m->content);
        $this->assertSame('E', $m->excerpt);
        $this->assertSame('s', $m->slug);
        $this->assertSame('publish', $m->status);
        $this->assertSame('bob', $m->author);
        $this->assertSame($pub, $m->publishedAt);
        $this->assertSame($mod, $m->modifiedAt);
        $this->assertSame([2, 3], $m->categoryIds);
        $this->assertSame(['k' => 'v'], $m->meta);
    }

    public function test_checksum_pinned_known_input(): void
    {
        // Pinned digest for the exact input below — computed once and locked.
        // Field order: postId|title|content|excerpt|slug|status|author|
        //              publishedAt(ATOM)|modifiedAt(ATOM)|categoryIds(JSON sorted asc)|meta(JSON ksorted)
        // Separator: chr(0). Arrays: json_encode with JSON_UNESCAPED_UNICODE.
        $pub = new \DateTimeImmutable('2024-03-01 09:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-03-02 10:00:00', new \DateTimeZone('UTC'));
        $m = new CanonicalPost(
            postId:      7,
            title:       'My Post',
            content:     '<p>Content</p>',
            excerpt:     'Excerpt',
            slug:        'my-post',
            status:      'publish',
            author:      'editor',
            publishedAt: $pub,
            modifiedAt:  $mod,
            categoryIds: [3, 5],
            meta:        ['key' => 'val'],
        );
        $this->assertSame(
            'bb16c1752b439914af06305934cffd96308c7e25803f241e8b8991af4969de96',
            $m->getChecksum(),
        );
    }

    public function test_checksum_order_independent_for_category_ids_and_meta(): void
    {
        // Two instances identical in value but with categoryIds and meta keys in different
        // input order must produce the same checksum — order is not semantically meaningful
        // for sets and associative maps. Pinned digest: 96de8d80...
        $pub = new \DateTimeImmutable('2024-03-01 09:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-03-02 10:00:00', new \DateTimeZone('UTC'));
        $base = [
            'postId' => 7, 'title' => 'My Post', 'content' => '<p>Content</p>',
            'excerpt' => 'Excerpt', 'slug' => 'my-post', 'status' => 'publish',
            'author' => 'editor', 'publishedAt' => $pub, 'modifiedAt' => $mod,
        ];
        $a = new CanonicalPost(
            ...$base,
            categoryIds: [3, 5],
            meta:        ['b' => '2', 'a' => '1'],
        );
        $b = new CanonicalPost(
            ...$base,
            categoryIds: [5, 3],
            meta:        ['a' => '1', 'b' => '2'],
        );
        $this->assertSame($a->getChecksum(), $b->getChecksum());
        $this->assertSame(
            '96de8d8059ea8f5d0c0808d5a5f973fecca145722c75110a92f6f36b6ceeeb88',
            $a->getChecksum(),
        );
    }

    public function test_checksum_is_64_hex_characters(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->make()->getChecksum());
    }

    public function test_checksum_deterministic(): void
    {
        $a = $this->make();
        $b = $this->make();
        $this->assertSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_title(): void
    {
        $a = $this->make(['title' => 'One']);
        $b = $this->make(['title' => 'Two']);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_content(): void
    {
        $a = $this->make(['content' => '<p>A</p>']);
        $b = $this->make(['content' => '<p>B</p>']);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_category_ids(): void
    {
        $a = $this->make(['categoryIds' => [1]]);
        $b = $this->make(['categoryIds' => [2]]);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_is_immutable(): void
    {
        $m = $this->make();
        $ref = new \ReflectionClass($m);
        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} must be readonly.");
        }
    }
}
