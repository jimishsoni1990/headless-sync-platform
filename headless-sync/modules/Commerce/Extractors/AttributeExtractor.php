<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Extractors;

use HSP\Modules\Commerce\SourceModels\AttributeSourceModel;
use HSP\Modules\Commerce\Validation\AttributeValidator;

/** Normalises a loader-shaped attribute array into an immutable AttributeSourceModel. */
final class AttributeExtractor
{
    public function __construct(private readonly AttributeValidator $validator)
    {
    }

    /** @param array<string,mixed> $raw */
    public function extract(array $raw): AttributeSourceModel
    {
        $attribute = new AttributeSourceModel(
            attributeId: (int) ($raw['id'] ?? 0),
            slug:        (string) ($raw['slug'] ?? ''),
            name:        (string) ($raw['name'] ?? ''),
            type:        (string) ($raw['type'] ?? 'select'),
            orderBy:     (string) ($raw['order_by'] ?? 'menu_order'),
            hasArchives: (bool) ($raw['has_archives'] ?? false),
        );

        $this->validator->validate($attribute);

        return $attribute;
    }
}
