<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalPost;
use HSP\Modules\Content\SourceModels\PostSourceModel;

/**
 * Transforms a PostSourceModel into a CanonicalPost.
 *
 * Pure function: no side effects, no I/O, no DB, no WordPress global calls,
 * no statics, no randomness. Same input always produces same output (Doc 6 §24).
 */
final class PostTransformer implements TransformerInterface
{
    /**
     * @param PostSourceModel      $source  Source model from PostExtractor
     * @param array<string, mixed> $context Event envelope metadata (unused by pure transform)
     */
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        assert($source instanceof PostSourceModel);

        return new CanonicalPost(
            postId:      $source->postId,
            title:       trim($source->title),
            content:     $source->content,
            excerpt:     $source->excerpt,
            slug:        $source->slug,
            status:      $source->status,
            author:      $source->author,
            publishedAt: $source->publishedAt,
            modifiedAt:  $source->modifiedAt,
            categoryIds: $source->categoryIds,
            meta:        $source->meta,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalPost::class;
    }
}
