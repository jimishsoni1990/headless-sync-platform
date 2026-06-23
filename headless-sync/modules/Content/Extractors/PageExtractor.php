<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Extractors;

use HSP\Modules\Content\SourceModels\PageSourceModel;
use HSP\Modules\Content\Validation\PageValidator;
use HSP\Modules\Content\Validation\ValidationException;

/**
 * Produces a PageSourceModel from raw WordPress page data.
 *
 * Accepts already-loaded data — callers fetch from WP before calling extract().
 * No global WordPress function calls; no DB access inside this class.
 *
 * Pages carry parentId and menuOrder instead of categoryIds and excerpt.
 *
 * @throws ValidationException on required-field failure (fail-fast, Doc 6 §22)
 */
final class PageExtractor
{
    public function __construct(
        private readonly PageValidator $validator,
    ) {}

    /**
     * @param array<string,mixed> $rawPost  WP_Post cast to array or equivalent shape
     * @param array<string,mixed> $rawMeta  get_post_meta()-style flat map
     *
     * @throws ValidationException when required fields are absent or structurally invalid
     */
    public function extract(
        array $rawPost,
        array $rawMeta = [],
    ): PageSourceModel {
        $this->validator->validate($rawPost);

        $postId     = (int) $rawPost['ID'];
        $title      = (string) ($rawPost['post_title'] ?? '');
        $content    = (string) ($rawPost['post_content'] ?? '');
        $slug       = (string) ($rawPost['post_name'] ?? '');
        $status     = (string) ($rawPost['post_status'] ?? '');
        $parentId   = (int) ($rawPost['post_parent'] ?? 0);
        $menuOrder  = (int) ($rawPost['menu_order'] ?? 0);

        $publishedAt = $this->parseDateGmt($rawPost['post_date_gmt'] ?? null, 'post_date_gmt');
        $modifiedAt  = $this->parseDateGmt($rawPost['post_modified_gmt'] ?? null, 'post_modified_gmt');

        $normalizedMeta = $this->normalizeMeta($rawMeta);

        return new PageSourceModel(
            postId:      $postId,
            title:       $title,
            content:     $content,
            slug:        $slug,
            status:      $status,
            parentId:    $parentId,
            menuOrder:   $menuOrder,
            publishedAt: $publishedAt,
            modifiedAt:  $modifiedAt,
            meta:        $normalizedMeta,
        );
    }

    /**
     * @param array<string,mixed> $rawMeta
     * @return array<string,string>
     */
    private function normalizeMeta(array $rawMeta): array
    {
        $out = [];
        foreach ($rawMeta as $key => $value) {
            $out[(string) $key] = (string) $value;
        }
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
