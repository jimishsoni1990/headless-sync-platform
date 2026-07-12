<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Replay;

/**
 * In-memory WordPress state for the replay integration test — the controllable stand-in
 * for get_post()/get_term() (DECISION H reload boundary a headless process cannot bootstrap).
 *
 * Tests mutate this store between replay runs (putPost / deletePost) to model current
 * WordPress reality; ReplayReadingLoader reads from it, and the ContentReplayEmitter decides
 * .updated vs .deleted from what it finds here (DECISION T point 2).
 */
final class FakeWpStore
{
    /** @var array<int, array{status:string,type:string,slug:string}> */
    private array $posts = [];

    /** @var array<int, array{slug:string}> */
    private array $terms = [];

    public function putPost(int $id, string $status, string $type, string $slug): void
    {
        $this->posts[$id] = ['status' => $status, 'type' => $type, 'slug' => $slug];
    }

    public function deletePost(int $id): void
    {
        unset($this->posts[$id]);
    }

    public function putTerm(int $id, string $slug): void
    {
        $this->terms[$id] = ['slug' => $slug];
    }

    public function deleteTerm(int $id): void
    {
        unset($this->terms[$id]);
    }

    /** @return array<string,mixed>|null */
    public function post(int $id, string $expectedType): ?array
    {
        if (! isset($this->posts[$id])) {
            return null;
        }
        $p = $this->posts[$id];

        return [
            'ID'                => $id,
            'post_title'        => "Title {$id}",
            'post_content'      => '<p>Body</p>',
            'post_excerpt'      => 'Excerpt',
            'post_name'         => $p['slug'],
            'post_status'       => $p['status'],
            'post_type'         => $expectedType !== '' ? $expectedType : $p['type'],
            'post_author'       => '1',
            'post_date_gmt'     => '2024-01-01 00:00:00',
            'post_modified_gmt' => '2024-06-01 00:00:00',
            'post_parent'       => '0',
            'menu_order'        => '0',
        ];
    }

    /** @return array<string,mixed>|null */
    public function term(int $id): ?array
    {
        if (! isset($this->terms[$id])) {
            return null;
        }

        return [
            'term_id'     => $id,
            'name'        => "Category {$id}",
            'slug'        => $this->terms[$id]['slug'],
            'description' => '',
            'parent'      => 0,
            'count'       => 0,
        ];
    }
}
