<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalTerm;
use HSP\Modules\Commerce\SourceModels\TermSourceModel;

/** Pure source → canonical transformation for Commerce taxonomy terms (Doc 6 §24). */
final class TermTransformer implements TransformerInterface
{
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        if (! $source instanceof TermSourceModel) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . TermSourceModel::class . ', got ' . $source::class . '.'
            );
        }

        return new CanonicalTerm(
            sourceTermId: $source->termId,
            taxonomyType: $source->taxonomyType,
            slug:         $source->slug,
            name:         $source->name,
            description:  $source->description,
            parentId:     $source->parentId,
            count:        $source->count,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalTerm::class;
    }
}
