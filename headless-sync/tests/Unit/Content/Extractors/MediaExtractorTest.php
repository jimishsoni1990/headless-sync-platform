<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Extractors;

use HSP\Modules\Content\Extractors\MediaExtractor;
use HSP\Modules\Content\SourceModels\MediaSourceModel;
use HSP\Modules\Content\Validation\MediaValidator;
use HSP\Modules\Content\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaExtractor (P1B-S1).
 *
 * No WordPress bootstrap: the extractor receives already-loaded data, exactly as it does
 * in production where WpContentLoader::loadAttachment() is the only WP boundary.
 */
final class MediaExtractorTest extends TestCase
{
    private MediaExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new MediaExtractor(new MediaValidator());
    }

    public function test_extracts_every_published_field_from_the_attachment_shape(): void
    {
        $source = $this->extractor->extract($this->attachment());

        self::assertInstanceOf(MediaSourceModel::class, $source);
        self::assertSame(42, $source->postId);
        self::assertSame('sunset', $source->slug);
        self::assertSame('Sunset', $source->title);
        self::assertSame('image/jpeg', $source->mimeType);
        self::assertSame('https://example.test/uploads/2024/01/sunset.jpg', $source->url);
        self::assertSame('A sunset over water', $source->altText);
        self::assertSame('A caption', $source->caption);
        self::assertSame('A description', $source->description);
        self::assertSame(1600, $source->width);
        self::assertSame(900, $source->height);
        self::assertSame(7, $source->attachedToId);
        self::assertSame('2024-01-02T03:04:05+00:00', $source->publishedAt->format(\DateTimeInterface::ATOM));
    }

    public function test_size_variants_are_normalised_and_key_sorted(): void
    {
        // WordPress hands back 'mime-type' (hyphenated) and string-ish numbers, in whatever
        // order the sizes were registered.
        $raw = $this->attachment(metadata: [
            'width'  => 1600,
            'height' => 900,
            'sizes'  => [
                'medium'    => ['file' => 'sunset-300x169.jpg', 'width' => '300', 'height' => '169', 'mime-type' => 'image/jpeg'],
                'thumbnail' => ['file' => 'sunset-150x150.jpg', 'width' => '150', 'height' => '150', 'mime-type' => 'image/jpeg'],
            ],
        ]);

        $sizes = $this->extractor->extract($raw)->sizes;

        // ksorted: an unstable key order would change the canonical checksum for unchanged
        // content and defeat the DECISION 3 write-suppress.
        self::assertSame(['medium', 'thumbnail'], array_keys($sizes));
        self::assertSame(
            ['file' => 'sunset-150x150.jpg', 'width' => 150, 'height' => 150, 'mime_type' => 'image/jpeg'],
            $sizes['thumbnail'],
        );
        self::assertIsInt($sizes['medium']['width']);
    }

    public function test_size_entries_without_a_filename_are_dropped(): void
    {
        $raw = $this->attachment(metadata: [
            'sizes' => [
                'broken' => ['width' => 10, 'height' => 10],
                'good'   => ['file' => 'sunset-10x10.jpg', 'width' => 10, 'height' => 10],
            ],
        ]);

        // A variant with no filename cannot resolve to a URL — publishing it would emit a
        // broken link, so it is dropped rather than guessed.
        self::assertSame(['good'], array_keys($this->extractor->extract($raw)->sizes));
    }

    public function test_a_non_image_attachment_without_metadata_extracts_cleanly(): void
    {
        $raw = $this->attachment(mimeType: 'application/pdf', metadata: []);

        $source = $this->extractor->extract($raw);

        self::assertSame('application/pdf', $source->mimeType);
        self::assertSame(0, $source->width);
        self::assertSame(0, $source->height);
        self::assertSame([], $source->sizes);
    }

    public function test_protected_meta_never_reaches_the_source_model(): void
    {
        $source = $this->extractor->extract($this->attachment(), [
            '_wp_attached_file'          => '2024/01/sunset.jpg',
            '_wp_attachment_image_alt'   => 'A sunset over water',
            'photographer'               => 'Ada',
        ]);

        // The delivery API is public: WordPress bookkeeping must not be published (Rule 6).
        self::assertSame(['photographer' => 'Ada'], $source->meta);
    }

    public function test_missing_mime_type_fails_validation(): void
    {
        $raw = $this->attachment();
        unset($raw['post_mime_type']);

        $this->expectException(ValidationException::class);
        $this->extractor->extract($raw);
    }

    public function test_a_non_attachment_post_type_is_rejected(): void
    {
        $raw = $this->attachment();
        $raw['post_type'] = 'post';

        $this->expectException(ValidationException::class);
        $this->extractor->extract($raw);
    }

    /**
     * @param array<string,mixed>|null $metadata
     * @return array<string,mixed>
     */
    private function attachment(string $mimeType = 'image/jpeg', ?array $metadata = null): array
    {
        return [
            'ID'                => 42,
            'post_name'         => 'sunset',
            'post_title'        => 'Sunset',
            'post_type'         => 'attachment',
            'post_status'       => 'inherit',
            'post_mime_type'    => $mimeType,
            'post_excerpt'      => 'A caption',
            'post_content'      => 'A description',
            'post_parent'       => '7',
            'post_date_gmt'     => '2024-01-02 03:04:05',
            'post_modified_gmt' => '2024-01-02 03:04:05',
            'hsp_url'           => 'https://example.test/uploads/2024/01/sunset.jpg',
            'hsp_alt'           => 'A sunset over water',
            'hsp_metadata'      => $metadata ?? ['width' => 1600, 'height' => 900, 'sizes' => []],
        ];
    }
}
