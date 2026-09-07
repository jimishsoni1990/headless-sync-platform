<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Commerce\CanonicalModels\CanonicalAttribute;
use HSP\Modules\Commerce\SourceModels\AttributeSourceModel;

/** Pure source → canonical transformation for global attribute definitions (Doc 6 §24). */
final class AttributeTransformer implements TransformerInterface
{
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        if (! $source instanceof AttributeSourceModel) {
            throw new \InvalidArgumentException(
                self::class . ' requires a ' . AttributeSourceModel::class . ', got ' . $source::class . '.'
            );
        }

        return new CanonicalAttribute(
            sourceAttributeId: $source->attributeId,
            slug:              $source->slug,
            name:              $source->name,
            type:              $source->type,
            orderBy:           $source->orderBy,
            hasArchives:       $source->hasArchives,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalAttribute::class;
    }
}
