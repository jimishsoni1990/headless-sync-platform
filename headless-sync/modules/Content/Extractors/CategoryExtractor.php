<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Extractors;

use HSP\Modules\Content\SourceModels\CategorySourceModel;
use HSP\Modules\Content\Validation\CategoryValidator;
use HSP\Modules\Content\Validation\ValidationException;

/**
 * Produces a CategorySourceModel from raw WordPress term data.
 *
 * Accepts already-loaded data (WP_Term cast to array or equivalent shape).
 * No global WordPress function calls; no DB access inside this class.
 *
 * @throws ValidationException on required-field failure (fail-fast, Doc 6 §22)
 */
final class CategoryExtractor
{
    public function __construct(
        private readonly CategoryValidator $validator,
    ) {}

    /**
     * @param array<string,mixed> $rawTerm  WP_Term cast to array or equivalent shape
     *
     * @throws ValidationException when required fields are absent or structurally invalid
     */
    public function extract(array $rawTerm): CategorySourceModel
    {
        $this->validator->validate($rawTerm);

        $termId      = (int) $rawTerm['term_id'];
        $name        = (string) ($rawTerm['name'] ?? '');
        $slug        = (string) ($rawTerm['slug'] ?? '');
        $description = (string) ($rawTerm['description'] ?? '');
        $parentId    = (int) ($rawTerm['parent'] ?? 0);
        $count       = (int) ($rawTerm['count'] ?? 0);

        return new CategorySourceModel(
            termId:      $termId,
            name:        $name,
            slug:        $slug,
            description: $description,
            parentId:    $parentId,
            count:       $count,
        );
    }
}
