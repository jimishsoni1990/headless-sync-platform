<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Validation;

/**
 * Validates raw WordPress page data before PageExtractor builds a PageSourceModel.
 *
 * Required fields: ID (numeric > 0), post_type must be 'page'.
 * Structural checks: post_date_gmt and post_modified_gmt format validity.
 */
final class PageValidator
{
    private const REQUIRED_NUMERIC = ['ID'];
    private const REQUIRED_STRING  = ['post_name', 'post_status'];

    /**
     * @param array<string,mixed> $rawPost
     * @throws ValidationException
     */
    public function validate(array $rawPost): void
    {
        $violations = [];

        foreach (self::REQUIRED_NUMERIC as $field) {
            if (! isset($rawPost[$field]) || ! is_numeric($rawPost[$field]) || (int) $rawPost[$field] <= 0) {
                $violations[] = "Required numeric field '{$field}' is missing or not a positive integer.";
            }
        }

        foreach (self::REQUIRED_STRING as $field) {
            if (! isset($rawPost[$field]) || trim((string) $rawPost[$field]) === '') {
                $violations[] = "Required string field '{$field}' is missing or empty.";
            }
        }

        if (isset($rawPost['post_type']) && $rawPost['post_type'] !== 'page') {
            $violations[] = "Field 'post_type' must be 'page'; got '{$rawPost['post_type']}'.";
        }

        if (! empty($violations)) {
            throw new ValidationException(
                'Page source data failed validation: ' . implode(' ', $violations),
                $violations,
            );
        }

        $this->validateDateField($rawPost, 'post_date_gmt');
        $this->validateDateField($rawPost, 'post_modified_gmt');
    }

    /** @param array<string,mixed> $rawPost */
    private function validateDateField(array $rawPost, string $field): void
    {
        $value = $rawPost[$field] ?? null;

        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return;
        }

        $str = (string) $value;
        $dt  = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $str)
            ?: false;

        if ($dt === false) {
            throw new ValidationException(
                "Field '{$field}' is not a parseable datetime value: '{$str}'.",
                ["Field '{$field}' is not a parseable datetime value: '{$str}'."],
            );
        }
    }
}
