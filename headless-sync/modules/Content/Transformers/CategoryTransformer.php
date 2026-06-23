<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalCategory;
use HSP\Modules\Content\SourceModels\CategorySourceModel;

/**
 * Transforms a CategorySourceModel into a CanonicalCategory.
 *
 * Pure function: no side effects, no I/O, no DB, no WordPress global calls,
 * no statics, no randomness. Same input always produces same output (Doc 6 §24).
 */
final class CategoryTransformer implements TransformerInterface
{
    /**
     * @param CategorySourceModel  $source  Source model from CategoryExtractor
     * @param array<string, mixed> $context Event envelope metadata (unused by pure transform)
     */
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        assert($source instanceof CategorySourceModel);

        return new CanonicalCategory(
            termId:      $source->termId,
            name:        trim($source->name),
            slug:        $source->slug,
            description: $source->description,
            parentId:    $source->parentId,
            count:       $source->count,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalCategory::class;
    }
}
