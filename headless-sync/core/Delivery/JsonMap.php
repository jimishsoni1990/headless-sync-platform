<?php

declare(strict_types=1);

namespace HSP\Core\Delivery;

/**
 * Decodes a stored JSON map into a value that ALWAYS serializes back as a JSON object.
 *
 * WHY THIS EXISTS
 * ---------------
 * PHP cannot distinguish an empty list from an empty map: both are `[]`, and `json_encode([])`
 * emits `[]`. So every Resource that decoded a JSON map and got nothing back published
 *
 *     "meta": []
 *
 * where the contract — and every non-empty response — says `{}`. A generated consumer type for
 * `Record<string, unknown>` breaks on the array form, and the mismatch appears only on the
 * resources that happen to have no metadata, which is why it survived so long.
 *
 * The fix belongs here rather than in each Resource because the duplication WAS the defect: five
 * private copies of the same decode helper (`decodeMeta`, `decodeJson`, `jsonObject`) each
 * returned `[]` independently. Core owns infrastructure (Rule 5); modules call this and share one
 * definition of "an empty map is `{}`".
 *
 * MAPS ONLY. This must never be used for a field whose contract is a LIST — `tags`,
 * `gallery_ids` — where `[]` is the correct empty value. The distinction is the caller's to make,
 * and it is exactly the distinction PHP's array type erases.
 *
 * ADR-038: transport-agnostic pure data transformation — no HTTP or WordPress types.
 */
final class JsonMap
{
    /**
     * Decode a JSON map from a projection column.
     *
     * Returns the decoded associative array when it has entries — preserving array access for
     * callers and tests — and an empty `stdClass` when it does not, because that is the only PHP
     * value that encodes as `{}`. A JSON *list* in the column is treated as absent: the field's
     * contract is a map, and publishing a list under a map's schema is the bug being fixed.
     *
     * @param  mixed $value raw column value: a JSON string, an already-decoded array, or null
     * @return array<string,mixed>|\stdClass
     */
    public static function decode(mixed $value): array|\stdClass
    {
        if (\is_string($value)) {
            $value = $value === '' ? null : \json_decode($value, associative: true);
        }

        if (! \is_array($value) || $value === []) {
            return new \stdClass();
        }

        // A JSON array (list) decodes to a PHP list. The contract here is a map, so an
        // accidental list is published as an empty object rather than as the wrong JSON type.
        if (\array_is_list($value)) {
            return new \stdClass();
        }

        /** @var array<string,mixed> $value */
        return $value;
    }
}
