<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

use HSP\Core\Contracts\ProjectionDescriptor;
use HSP\Core\Contracts\ProjectionRegistryInterface;
use HSP\Core\Projection\ProjectionRegistry;

/**
 * The Content module's projection descriptors, for tests that build a
 * ReconciliationService by hand.
 *
 * Mirrors what ContentServiceProvider::boot() registers into the core-owned
 * ProjectionRegistry (DECISION AG AG-3). Kept in one place so a test cannot drift from
 * the real registration — the drift that let media and tags go unreconciled.
 */
final class ContentProjections
{
    /** Registry pre-loaded with all five Content aggregates. */
    public static function registry(): ProjectionRegistryInterface
    {
        $registry = new ProjectionRegistry();

        foreach (self::descriptors() as $descriptor) {
            $registry->register($descriptor);
        }

        return $registry;
    }

    /** An empty registry — for tests that never reconcile and only need the argument. */
    public static function empty(): ProjectionRegistryInterface
    {
        return new ProjectionRegistry();
    }

    /** @return list<ProjectionDescriptor> */
    public static function descriptors(): array
    {
        return [
            new ProjectionDescriptor('page',     'content.pages',      'source_post_id'),
            new ProjectionDescriptor('post',     'content.posts',      'source_post_id'),
            new ProjectionDescriptor('media',    'content.media',      'source_post_id'),
            new ProjectionDescriptor('category', 'content.taxonomies', 'source_term_id', 'category', 'taxonomy_type'),
            new ProjectionDescriptor('tag',      'content.taxonomies', 'source_term_id', 'post_tag', 'taxonomy_type'),
        ];
    }
}
