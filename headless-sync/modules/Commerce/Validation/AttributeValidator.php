<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Validation;

use HSP\Modules\Commerce\SourceModels\AttributeSourceModel;

/** Fail-fast structural validation of a global attribute definition. */
final class AttributeValidator
{
    public function validate(AttributeSourceModel $attribute): void
    {
        if ($attribute->attributeId <= 0) {
            throw new ValidationException('Attribute source id must be a positive integer.');
        }

        if (trim($attribute->slug) === '') {
            throw new ValidationException(
                "Attribute {$attribute->attributeId} has no taxonomy slug; nothing could join its terms."
            );
        }

        if (trim($attribute->name) === '') {
            throw new ValidationException("Attribute {$attribute->attributeId} has an empty label.");
        }
    }
}
