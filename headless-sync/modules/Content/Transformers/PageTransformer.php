<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalPage;
use HSP\Modules\Content\SourceModels\PageSourceModel;

/**
 * Transforms a PageSourceModel into a CanonicalPage.
 *
 * Pure function: no side effects, no I/O, no DB, no WordPress global calls,
 * no statics, no randomness. Same input always produces same output (Doc 6 §24).
 */
final class PageTransformer implements TransformerInterface
{
    /**
     * @param PageSourceModel      $source  Source model from PageExtractor
     * @param array<string, mixed> $context Event envelope metadata (unused by pure transform)
     */
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        assert($source instanceof PageSourceModel);

        return new CanonicalPage(
            postId:      $source->postId,
            title:       trim($source->title),
            content:     $source->content,
            slug:        $source->slug,
            status:      $source->status,
            parentId:    $source->parentId,
            menuOrder:   $source->menuOrder,
            publishedAt: $source->publishedAt,
            modifiedAt:  $source->modifiedAt,
            meta:        $source->meta,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalPage::class;
    }
}
