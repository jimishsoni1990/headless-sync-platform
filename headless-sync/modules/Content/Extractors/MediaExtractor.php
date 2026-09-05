<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Extractors;

use HSP\Modules\Content\SourceModels\MediaSourceModel;
use HSP\Modules\Content\Validation\MediaValidator;
use HSP\Modules\Content\Validation\ValidationException;

/**
 * Produces a MediaSourceModel from raw WordPress attachment data.
 *
 * Accepts already-loaded data — callers fetch from WP before calling extract().
 * No global WordPress function calls; no DB access inside this class.
 *
 * The attachment shape is the WP_Post array plus three loader-resolved extras, which
 * WordPress alone can compute and which no extractor may fetch itself:
 *   hsp_url       — wp_get_attachment_url()
 *   hsp_alt       — _wp_attachment_image_alt
 *   hsp_metadata  — wp_get_attachment_metadata() (width / height / sizes)
 * They are `hsp_`-prefixed so they cannot collide with a WP_Post column.
 *
 * @throws ValidationException on required-field failure (fail-fast, Doc 6 §22)
 */
final class MediaExtractor
{
    public function __construct(
        private readonly MediaValidator $validator,
    ) {
    }

    /**
     * @param array<string,mixed> $rawAttachment WP_Post cast to array + hsp_url / hsp_alt / hsp_metadata
     * @param array<string,mixed> $rawMeta       get_post_meta()-style flat map
     *
     * @throws ValidationException when required fields are absent or structurally invalid
     */
    public function extract(array $rawAttachment, array $rawMeta = []): MediaSourceModel
    {
        $this->validator->validate($rawAttachment);

        $metadata = is_array($rawAttachment['hsp_metadata'] ?? null) ? $rawAttachment['hsp_metadata'] : [];

        return new MediaSourceModel(
            postId:       (int) $rawAttachment['ID'],
            slug:         (string) ($rawAttachment['post_name'] ?? ''),
            title:        (string) ($rawAttachment['post_title'] ?? ''),
            mimeType:     (string) ($rawAttachment['post_mime_type'] ?? ''),
            url:          (string) ($rawAttachment['hsp_url'] ?? ''),
            altText:      (string) ($rawAttachment['hsp_alt'] ?? ''),
            caption:      (string) ($rawAttachment['post_excerpt'] ?? ''),
            description:  (string) ($rawAttachment['post_content'] ?? ''),
            width:        (int) ($metadata['width'] ?? 0),
            height:       (int) ($metadata['height'] ?? 0),
            sizes:        $this->normalizeSizes($metadata['sizes'] ?? null),
            attachedToId: (int) ($rawAttachment['post_parent'] ?? 0),
            publishedAt:  $this->parseDateGmt($rawAttachment['post_date_gmt'] ?? null, 'post_date_gmt'),
            modifiedAt:   $this->parseDateGmt($rawAttachment['post_modified_gmt'] ?? null, 'post_modified_gmt'),
            meta:         ProtectedMeta::publicOnly($rawMeta),
        );
    }

    /**
     * Normalise the registered size variants to a stable, fully-typed shape.
     *
     * WordPress records each variant as ['file' => …, 'width' => …, 'height' => …,
     * 'mime-type' => …]. The hyphenated key and loose types are WordPress internals; the
     * published contract uses `mime_type` and real ints. Malformed or partial entries are
     * dropped rather than guessed — a missing filename cannot resolve to a URL.
     *
     * Sorted by size name so the canonical checksum is order-independent: WordPress does not
     * guarantee the order of this array, and an unstable order would produce a different
     * checksum for identical content, defeating the DECISION 3 write-suppress.
     *
     * @return array<string, array{file:string,width:int,height:int,mime_type:string}>
     */
    private function normalizeSizes(mixed $sizes): array
    {
        if (! is_array($sizes)) {
            return [];
        }

        $out = [];

        foreach ($sizes as $name => $size) {
            if (! is_string($name) || ! is_array($size)) {
                continue;
            }

            $file = (string) ($size['file'] ?? '');
            if ($file === '') {
                continue;
            }

            $out[$name] = [
                'file'      => $file,
                'width'     => (int) ($size['width'] ?? 0),
                'height'    => (int) ($size['height'] ?? 0),
                'mime_type' => (string) ($size['mime-type'] ?? $size['mime_type'] ?? ''),
            ];
        }

        ksort($out);

        return $out;
    }

    /**
     * @throws ValidationException when the value is non-null and not parseable
     */
    private function parseDateGmt(mixed $value, string $field): \DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        $str = (string) $value;

        if (str_contains($str, '+') || str_ends_with($str, 'Z')) {
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $str)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $str)
                ?: false;
        } else {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $str, new \DateTimeZone('UTC'));
        }

        if ($dt === false) {
            throw new ValidationException(
                "Field '{$field}' contains an unparseable datetime value: '{$str}'."
            );
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }
}
