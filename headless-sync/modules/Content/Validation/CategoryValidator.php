<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Validation;

/**
 * Validates raw WordPress term data before CategoryExtractor builds a CategorySourceModel.
 *
 * Required fields: term_id (numeric > 0), name (non-empty), slug (non-empty).
 * Structural check: taxonomy, if present, must be 'category'.
 */
final class CategoryValidator
{
    /** Taxonomies that project into content.taxonomies (P1B-S3). */
    public const SUPPORTED_TAXONOMIES = ['category', 'post_tag'];

    private const REQUIRED_NUMERIC = ['term_id'];
    private const REQUIRED_STRING  = ['name', 'slug'];

    /**
     * @param array<string,mixed> $rawTerm
     * @throws ValidationException
     */
    public function validate(array $rawTerm): void
    {
        $violations = [];

        foreach (self::REQUIRED_NUMERIC as $field) {
            if (! isset($rawTerm[$field]) || ! is_numeric($rawTerm[$field]) || (int) $rawTerm[$field] <= 0) {
                $violations[] = "Required numeric field '{$field}' is missing or not a positive integer.";
            }
        }

        foreach (self::REQUIRED_STRING as $field) {
            if (! isset($rawTerm[$field]) || trim((string) $rawTerm[$field]) === '') {
                $violations[] = "Required string field '{$field}' is missing or empty.";
            }
        }

        // P1B-S3: tags ride the same projection, so the validator accepts the supported
        // taxonomy set rather than 'category' alone. An unsupported taxonomy is still rejected —
        // HookWiring never emits one, and a stray event must not project.
        if (isset($rawTerm['taxonomy']) && ! in_array($rawTerm['taxonomy'], self::SUPPORTED_TAXONOMIES, true)) {
            $violations[] = sprintf(
                "Field 'taxonomy' must be one of %s; got '%s'.",
                implode(', ', self::SUPPORTED_TAXONOMIES),
                (string) $rawTerm['taxonomy'],
            );
        }

        if (! empty($violations)) {
            throw new ValidationException(
                'Category source data failed validation: ' . implode(' ', $violations),
                $violations,
            );
        }
    }
}
