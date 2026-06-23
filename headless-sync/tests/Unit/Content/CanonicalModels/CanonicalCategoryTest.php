<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\CanonicalModels;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalCategory;
use PHPUnit\Framework\TestCase;

final class CanonicalCategoryTest extends TestCase
{
    private function make(array $overrides = []): CanonicalCategory
    {
        $defaults = [
            'termId'      => 1,
            'name'        => 'General',
            'slug'        => 'general',
            'description' => '',
            'parentId'    => 0,
            'count'       => 0,
        ];
        $p = array_merge($defaults, $overrides);
        return new CanonicalCategory(
            termId:      $p['termId'],
            name:        $p['name'],
            slug:        $p['slug'],
            description: $p['description'],
            parentId:    $p['parentId'],
            count:       $p['count'],
        );
    }

    public function test_implements_canonical_model_interface(): void
    {
        $this->assertInstanceOf(CanonicalModelInterface::class, $this->make());
    }

    public function test_get_source_id_returns_term_id(): void
    {
        $this->assertSame(9, $this->make(['termId' => 9])->getSourceId());
    }

    public function test_all_properties_accessible(): void
    {
        $m = $this->make([
            'termId'      => 4,
            'name'        => 'News',
            'slug'        => 'news',
            'description' => 'Latest news',
            'parentId'    => 2,
            'count'       => 7,
        ]);
        $this->assertSame(4, $m->termId);
        $this->assertSame('News', $m->name);
        $this->assertSame('news', $m->slug);
        $this->assertSame('Latest news', $m->description);
        $this->assertSame(2, $m->parentId);
        $this->assertSame(7, $m->count);
    }

    public function test_checksum_pinned_known_input(): void
    {
        // Pinned digest for the exact input below — computed once and locked.
        // Field order: termId|name|slug|description|parentId|count
        // Separator: chr(0). All fields are scalars; no array encoding needed.
        $m = new CanonicalCategory(
            termId:      3,
            name:        'PHP',
            slug:        'php',
            description: 'PHP programming',
            parentId:    7,
            count:       5,
        );
        $this->assertSame(
            'b5845d198f701098ea1bf28d2b1c02013d6f30f328cc73f63f28e76b07249811',
            $m->getChecksum(),
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

    public function test_checksum_changes_with_name(): void
    {
        $a = $this->make(['name' => 'Alpha']);
        $b = $this->make(['name' => 'Beta']);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_description(): void
    {
        $a = $this->make(['description' => '']);
        $b = $this->make(['description' => 'Some text']);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_changes_with_count(): void
    {
        $a = $this->make(['count' => 0]);
        $b = $this->make(['count' => 1]);
        $this->assertNotSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_empty_description_accepted(): void
    {
        $m = $this->make(['description' => '']);
        $this->assertSame('', $m->description);
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
