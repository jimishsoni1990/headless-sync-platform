<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\CanonicalModels;

use HSP\Modules\Content\CanonicalModels\CanonicalMedia;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CanonicalMedia (P1B-S1).
 *
 * The checksum is the DECISION 3 write-suppress signal: if a field can change without the
 * checksum moving, that change is silently lost — the adapter compares the freshly computed
 * projection checksum against the stored one and skips the upsert when they match. Every
 * published field therefore gets a "moves the checksum" case here.
 */
final class CanonicalMediaTest extends TestCase
{
    public function test_checksum_is_stable_for_identical_input(): void
    {
        self::assertSame($this->media()->getChecksum(), $this->media()->getChecksum());
    }

    public function test_checksum_is_a_sha256_hex_digest(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $this->media()->getChecksum());
    }

    public function test_checksum_ignores_meta_key_order(): void
    {
        // WordPress does not guarantee meta key order; an order-sensitive checksum would
        // churn the projection on every re-sync.
        $a = $this->media(meta: ['alpha' => '1', 'beta' => '2']);
        $b = $this->media(meta: ['beta' => '2', 'alpha' => '1']);

        self::assertSame($a->getChecksum(), $b->getChecksum());
    }

    public function test_checksum_ignores_size_key_order(): void
    {
        $variants = [
            'thumbnail' => ['url' => 'https://example.test/s-150.jpg', 'width' => 150, 'height' => 150, 'mime_type' => 'image/jpeg'],
            'medium'    => ['url' => 'https://example.test/s-300.jpg', 'width' => 300, 'height' => 169, 'mime_type' => 'image/jpeg'],
        ];

        $a = $this->media(sizes: $variants);
        $b = $this->media(sizes: array_reverse($variants, preserve_keys: true));

        self::assertSame($a->getChecksum(), $b->getChecksum());
    }

    /**
     * Each published field must move the checksum, or a change to it is silently suppressed.
     */
    public function test_every_published_field_moves_the_checksum(): void
    {
        $base = $this->media()->getChecksum();

        $variants = [
            'slug'         => $this->media(slug: 'other'),
            'title'        => $this->media(title: 'Other'),
            'mime_type'    => $this->media(mimeType: 'image/png'),
            'url'          => $this->media(url: 'https://example.test/other.jpg'),
            'alt_text'     => $this->media(altText: 'Different alt'),
            'caption'      => $this->media(caption: 'Different caption'),
            'description'  => $this->media(description: 'Different description'),
            'width'        => $this->media(width: 1601),
            'height'       => $this->media(height: 901),
            'sizes'        => $this->media(sizes: ['thumbnail' => ['url' => 'https://example.test/x.jpg', 'width' => 1, 'height' => 1, 'mime_type' => 'image/jpeg']]),
            'attached_to'  => $this->media(attachedToId: 8),
            'modified_at'  => $this->media(modifiedAt: new \DateTimeImmutable('2025-01-01T00:00:00Z')),
            'meta'         => $this->media(meta: ['photographer' => 'Grace']),
        ];

        foreach ($variants as $field => $model) {
            self::assertNotSame(
                $base,
                $model->getChecksum(),
                "Changing '{$field}' must move the checksum, or DECISION 3 suppresses the write and the change is lost.",
            );
        }
    }

    /**
     * @param array<string, array{url:string,width:int,height:int,mime_type:string}>|null $sizes
     * @param array<string,string>|null $meta
     */
    private function media(
        string $slug = 'sunset',
        string $title = 'Sunset',
        string $mimeType = 'image/jpeg',
        string $url = 'https://example.test/uploads/sunset.jpg',
        string $altText = 'A sunset over water',
        string $caption = 'A caption',
        string $description = 'A description',
        int $width = 1600,
        int $height = 900,
        ?array $sizes = null,
        int $attachedToId = 7,
        ?\DateTimeImmutable $modifiedAt = null,
        ?array $meta = null,
    ): CanonicalMedia {
        return new CanonicalMedia(
            postId:       42,
            slug:         $slug,
            title:        $title,
            mimeType:     $mimeType,
            url:          $url,
            altText:      $altText,
            caption:      $caption,
            description:  $description,
            width:        $width,
            height:       $height,
            sizes:        $sizes ?? ['thumbnail' => ['url' => 'https://example.test/uploads/sunset-150x150.jpg', 'width' => 150, 'height' => 150, 'mime_type' => 'image/jpeg']],
            attachedToId: $attachedToId,
            publishedAt:  new \DateTimeImmutable('2024-01-02T03:04:05Z'),
            modifiedAt:   $modifiedAt ?? new \DateTimeImmutable('2024-01-02T03:04:05Z'),
            meta:         $meta ?? ['photographer' => 'Ada'],
        );
    }
}
