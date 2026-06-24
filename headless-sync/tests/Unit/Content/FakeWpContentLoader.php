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

    public function loadPostCategoryIds(int $postId): array
    {
        return $this->categoryIdsResult;
    }
}
