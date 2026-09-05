<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Validation;

/**
 * Validates raw WordPress attachment data before MediaExtractor builds a MediaSourceModel.
 *
 * Required fields: ID (numeric > 0), post_name (slug), post_mime_type.
 * post_type must be 'attachment' when present.
 *
 * post_status is deliberately NOT required: attachments carry 'inherit', which is outside
 * the {publish} public set (OPEN-10), so status plays no part in media membership.
 */
final class MediaValidator
{
    private const REQUIRED_NUMERIC = ['ID'];
    private const REQUIRED_STRING  = ['post_name', 'post_mime_type'];

    /**
     * @param array<string,mixed> $rawAttachment
     * @throws ValidationException
     */
    public function validate(array $rawAttachment): void
    {
        $violations = [];

        foreach (self::REQUIRED_NUMERIC as $field) {
            $value = $rawAttachment[$field] ?? null;
            if ($value === null || ! is_numeric($value) || (int) $value <= 0) {
                $violations[] = "Required numeric field '{$field}' is missing or not a positive integer.";
            }
        }

        foreach (self::REQUIRED_STRING as $field) {
            if (! isset($rawAttachment[$field]) || trim((string) $rawAttachment[$field]) === '') {
                $violations[] = "Required string field '{$field}' is missing or empty.";
            }
        }

        if (isset($rawAttachment['post_type']) && $rawAttachment['post_type'] !== 'attachment') {
            $violations[] = "Field 'post_type' must be 'attachment'; got '{$rawAttachment['post_type']}'.";
        }

        if (! empty($violations)) {
            throw new ValidationException(
                'Media source data failed validation: ' . implode(' ', $violations),
                $violations,
            );
        }

        $this->validateDateField($rawAttachment, 'post_date_gmt');
        $this->validateDateField($rawAttachment, 'post_modified_gmt');
    }

    /** @param array<string,mixed> $rawAttachment */
    private function validateDateField(array $rawAttachment, string $field): void
    {
        $value = $rawAttachment[$field] ?? null;

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
