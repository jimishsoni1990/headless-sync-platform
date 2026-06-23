<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use PHPUnit\Framework\TestCase;

final class CanonicalPageTest extends TestCase
{
    private function make(array $overrides = []): CanonicalPage
    {
        $defaults = [
            'postId'      => 1,
            'title'       => 'Home',
            'content'     => '<p>Welcome</p>',
            'slug'        => 'home',
            'status'      => 'publish',
            'parentId'    => 0,
            'menuOrder'   => 0,
            'publishedAt' => new \DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            'modifiedAt'  => new \DateTimeImmutable('2024-01-02 00:00:00', new \DateTimeZone('UTC')),
            'meta'        => [],
        ];
        $p = array_merge($defaults, $overrides);
        return new CanonicalPage(
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

    public function test_implements_canonical_model_interface(): void
    {
        $this->assertInstanceOf(CanonicalModelInterface::class, $this->make());
    }

    public function test_get_source_id_returns_post_id(): void
    {
        $this->assertSame(33, $this->make(['postId' => 33])->getSourceId());
    }

    public function test_all_properties_accessible(): void
    {
        $pub = new \DateTimeImmutable('2024-03-01 00:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-03-02 00:00:00', new \DateTimeZone('UTC'));
        $m = $this->make([
            'postId'      => 20,
            'title'       => 'About',
            'content'     => '<p>About us</p>',
            'slug'        => 'about',
            'status'      => 'publish',
            'parentId'    => 5,
            'menuOrder'   => 3,
            'publishedAt' => $pub,
            'modifiedAt'  => $mod,
            'meta'        => ['_foo' => 'bar'],
        ]);
        $this->assertSame(20, $m->postId);
        $this->assertSame('About', $m->title);
        $this->assertSame('<p>About us</p>', $m->content);
        $this->assertSame('about', $m->slug);
        $this->assertSame('publish', $m->status);
        $this->assertSame(5, $m->parentId);
        $this->assertSame(3, $m->menuOrder);
        $this->assertSame($pub, $m->publishedAt);
        $this->assertSame($mod, $m->modifiedAt);
        $this->assertSame(['_foo' => 'bar'], $m->meta);
    }

    public function test_checksum_pinned_known_input(): void
    {
        // Pinned digest for the exact input below — computed once and locked.
        // Field order: postId|title|content|slug|status|parentId|menuOrder|
        //              publishedAt(ATOM)|modifiedAt(ATOM)|meta(JSON ksorted)
        // Separator: chr(0). Arrays: json_encode with JSON_UNESCAPED_UNICODE.
        $pub = new \DateTimeImmutable('2024-01-01 08:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-01-02 08:00:00', new \DateTimeZone('UTC'));
        $m = new CanonicalPage(
            postId:      5,
            title:       'Contact',
            content:     '<p>Contact page</p>',
            slug:        'contact',
            status:      'publish',
            parentId:    3,
            menuOrder:   2,
            publishedAt: $pub,
            modifiedAt:  $mod,
            meta:        ['_seo' => 'contact'],
        );
        $this->assertSame(
            '13621b9d94428d024d108405bd5992438e8c174c2b7d5667269d2c6cc81f1307',
            $m->getChecksum(),
        );
    }

    public function test_checksum_order_independent_for_meta(): void
    {
        // Two instances identical in value but with meta keys in different input order
        // must produce the same checksum — key order from WordPress is not stable.
        // Pinned digest: dc020024...
        $pub = new \DateTimeImmutable('2024-01-01 08:00:00', new \DateTimeZone('UTC'));
        $mod = new \DateTimeImmutable('2024-01-02 08:00:00', new \DateTimeZone('UTC'));
        $base = [
            'postId' => 5, 'title' => 'Contact', 'content' => '<p>Contact page</p>',
            'slug' => 'contact', 'status' => 'publish', 'parentId' => 3, 'menuOrder' => 2,
            'publishedAt' => $pub, 'modifiedAt' => $mod,
        ];
        $a = new CanonicalPage(...$base, meta: ['z' => 'last', 'a' => 'first']);
        $b = new CanonicalPage(...$base, meta: ['a' => 'first', 'z' => 'last']);
        $this->assertSame($a->getChecksum(), $b->getChecksum());
        $this->assertSame(
            'dc020024d310f586655d5d387a5b6f7ead3c0c69c930017cefb6c34a9184243f',
            $a->getChecksum(),
        );
    }

    public function test_checksum_is_sha256_hex(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->make()->getChecksum());
    }

    public function test_checksum_deterministic(): void
    {
        $a = $this->make();
        $b = $this->make();
        $this->assertSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_slug(): void
    {
        $a = $this->make(['slug' => 'alpha']);
        $b = $this->make(['slug' => 'beta']);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_parent_id(): void
    {
        $a = $this->make(['parentId' => 0]);
        $b = $this->make(['parentId' => 5]);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_menu_order(): void
    {
        $a = $this->make(['menuOrder' => 0]);
        $b = $this->make(['menuOrder' => 10]);
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
