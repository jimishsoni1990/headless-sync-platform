<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content;

use HSP\Modules\Content\WpContentLoader;

/**
 * Configurable fake WpContentLoader for unit tests.
 *
 * Handlers under test receive this via constructor injection (DECISION H / ADR-012).
 * No WordPress bootstrap required.
 */
class FakeWpContentLoader implements WpContentLoader
{
    /** @var array<string,mixed>|null */
    public ?array $postResult = ['ID' => 1, 'post_title' => 'Test', 'post_content' => '',
        'post_name' => 'test', 'post_status' => 'publish', 'post_type' => 'page',
        'post_author' => '1', 'post_date_gmt' => '2024-01-01 00:00:00',
        'post_modified_gmt' => '2024-01-01 00:00:00', 'post_parent' => '0', 'menu_order' => '0',
        'post_excerpt' => ''];

    /** @var array<string,string> */
    public array $postMetaResult = [];

    /** @var list<int> */
    public array $categoryIdsResult = [];

    /** @var array<string,mixed>|null */
    public ?array $termResult = ['term_id' => 5, 'name' => 'Category', 'slug' => 'category',
        'description' => '', 'parent' => 0, 'count' => 3];

    /**
     * Attachment shape: the WP_Post columns plus the three loader-resolved extras
     * (hsp_url / hsp_alt / hsp_metadata) that only WordPress can compute.
     *
     * @var array<string,mixed>|null
     */
    public ?array $attachmentResult = [
        'ID' => 42, 'post_title' => 'Sunset', 'post_name' => 'sunset',
        'post_type' => 'attachment', 'post_status' => 'inherit',
        'post_mime_type' => 'image/jpeg', 'post_excerpt' => 'A caption',
        'post_content' => 'A description', 'post_parent' => '7',
        'post_date_gmt' => '2024-01-01 00:00:00', 'post_modified_gmt' => '2024-01-01 00:00:00',
        'hsp_url' => 'https://example.test/wp-content/uploads/2024/01/sunset.jpg',
        'hsp_alt' => 'A sunset over water',
        'hsp_metadata' => [
            'width'  => 1600,
            'height' => 900,
            'sizes'  => [
                'thumbnail' => ['file' => 'sunset-150x150.jpg', 'width' => 150, 'height' => 150, 'mime-type' => 'image/jpeg'],
                'medium'    => ['file' => 'sunset-300x169.jpg', 'width' => 300, 'height' => 169, 'mime-type' => 'image/jpeg'],
            ],
        ],
    ];

    public function loadPost(int $postId): ?array
    {
        return $this->postResult;
    }

    public function loadPostMeta(int $postId): array
    {
        return $this->postMetaResult;
    }

    public function loadTerm(int $termId): ?array
    {
        return $this->termResult;
    }

    public function loadAttachment(int $postId): ?array
    {
        return $this->attachmentResult;
    }

    public function loadPostCategoryIds(int $postId): array
    {
        return $this->categoryIdsResult;
    }

    /** @var array<string, list<int>> taxonomy → term ids (P1B-S3) */
    public array $termIdsResult = [];

    public function loadPostTermIds(int $postId, string $taxonomy): array
    {
        if ($taxonomy === 'category') {
            return $this->categoryIdsResult;
        }

        return $this->termIdsResult[$taxonomy] ?? [];
    }
}
