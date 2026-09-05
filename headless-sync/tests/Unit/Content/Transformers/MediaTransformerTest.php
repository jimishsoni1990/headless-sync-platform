<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Transformers;

use HSP\Modules\Content\CanonicalModels\CanonicalMedia;
use HSP\Modules\Content\SourceModels\MediaSourceModel;
use HSP\Modules\Content\Transformers\MediaTransformer;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaTransformer (P1B-S1).
 *
 * The transformer is a pure function (Doc 6 §24): no I/O, no WordPress, no randomness.
 */
final class MediaTransformerTest extends TestCase
{
    private MediaTransformer $transformer;

    protected function setUp(): void
    {
        $this->transformer = new MediaTransformer();
    }

    public function test_transforms_to_canonical_media(): void
    {
        $canonical = $this->transformer->transform($this->source());

        self::assertInstanceOf(CanonicalMedia::class, $canonical);
        self::assertSame(CanonicalMedia::class, $this->transformer->getCanonicalModelClass());
        self::assertSame(42, $canonical->getSourceId());
    }

    public function test_size_variants_are_resolved_to_absolute_urls(): void
    {
        $canonical = $this->transformer->transform($this->source());

        // Resolving write-side is the point: a consumer must never reconstruct an upload
        // path from a bare filename, which would couple it to WordPress internals (Rule 6).
        self::assertSame(
            'https://example.test/uploads/2024/01/sunset-150x150.jpg',
            $canonical->sizes['thumbnail']['url'],
        );
        self::assertSame(150, $canonical->sizes['thumbnail']['width']);
        self::assertSame('image/jpeg', $canonical->sizes['thumbnail']['mime_type']);
        self::assertArrayNotHasKey('file', $canonical->sizes['thumbnail']);
    }

    public function test_variants_are_dropped_when_there_is_no_original_url_to_resolve_against(): void
    {
        $canonical = $this->transformer->transform($this->source(url: ''));

        // Half-built URLs are worse than no variant at all.
        self::assertSame([], $canonical->sizes);
    }

    public function test_title_and_alt_text_are_trimmed(): void
    {
        $canonical = $this->transformer->transform($this->source(title: '  Sunset  ', alt: "  A sunset\n"));

        self::assertSame('Sunset', $canonical->title);
        self::assertSame('A sunset', $canonical->altText);
    }

    public function test_transform_is_deterministic(): void
    {
        $a = $this->transformer->transform($this->source());
        $b = $this->transformer->transform($this->source());

        self::assertSame($a->getChecksum(), $b->getChecksum());
    }

    private function source(
        string $url = 'https://example.test/uploads/2024/01/sunset.jpg',
        string $title = 'Sunset',
        string $alt = 'A sunset over water',
    ): MediaSourceModel {
        return new MediaSourceModel(
            postId:       42,
            slug:         'sunset',
            title:        $title,
            mimeType:     'image/jpeg',
            url:          $url,
            altText:      $alt,
            caption:      'A caption',
            description:  'A description',
            width:        1600,
            height:       900,
            sizes:        [
                'thumbnail' => ['file' => 'sunset-150x150.jpg', 'width' => 150, 'height' => 150, 'mime_type' => 'image/jpeg'],
            ],
            attachedToId: 7,
            publishedAt:  new \DateTimeImmutable('2024-01-02T03:04:05Z'),
            modifiedAt:   new \DateTimeImmutable('2024-01-02T03:04:05Z'),
            meta:         ['photographer' => 'Ada'],
        );
    }
}
