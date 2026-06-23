<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Extractors;

use HSP\Modules\Content\SourceModels\PostSourceModel;
use HSP\Modules\Content\Validation\PostValidator;
use HSP\Modules\Content\Validation\ValidationException;

/**
 * Produces a PostSourceModel from raw WordPress post data.
 *
 * Accepts already-loaded data — callers are responsible for fetching from WP
 * (e.g. get_post(), wp_get_post_terms()) before calling extract().
 * This keeps the extractor free of global WordPress function calls and makes
 * unit testing trivial (no DB, no WordPress required).
 *
 * Normalization performed here:
 *  - Casts numeric IDs to int.
 *  - Converts GMT datetime strings to UTC DateTimeImmutable instances.
 *  - Converts meta value scalars to string.
 *  - Falls back gracefully on missing-but-optional fields.
 *
 * Throws ValidationException on required-field failure (fail-fast, Doc 6 §22).
 */
final class PostExtractor
{
    public function __construct(
        private readonly PostValidator $validator,
    ) {}

    /**
     * @param array<string,mixed> $rawPost      WP_Post object cast to array, or equivalent shape
     * @param array<string,mixed> $rawMeta       get_post_meta()-style flat map (meta_key → scalar value)
     * @param list<int>           $categoryIds   term IDs already resolved to 'category' taxonomy
     *
     * @throws ValidationException when required fields are absent or structurally invalid
     */
    public function extract(
        array $rawPost,
        array $rawMeta = [],
        array $categoryIds = [],
    ): PostSourceModel {
        $this->validator->validate($rawPost);

        $postId     = (int) $rawPost['ID'];
        $title      = (string) ($rawPost['post_title'] ?? '');
        $content    = (string) ($rawPost['post_content'] ?? '');
        $excerpt    = (string) ($rawPost['post_excerpt'] ?? '');
        $slug       = (string) ($rawPost['post_name'] ?? '');
        $status     = (string) ($rawPost['post_status'] ?? '');
        $author     = (string) ($rawPost['post_author_login'] ?? (string) ($rawPost['post_author'] ?? ''));

        $publishedAt = $this->parseDateGmt($rawPost['post_date_gmt'] ?? null, 'post_date_gmt');
        $modifiedAt  = $this->parseDateGmt($rawPost['post_modified_gmt'] ?? null, 'post_modified_gmt');

        $normalizedMeta = $this->normalizeMeta($rawMeta);
        $normalizedCats = array_values(array_map('intval', $categoryIds));

        return new PostSourceModel(
            postId:      $postId,
            title:       $title,
            content:     $content,
            excerpt:     $excerpt,
            slug:        $slug,
            status:      $status,
            author:      $author,
            publishedAt: $publishedAt,
            modifiedAt:  $modifiedAt,
            categoryIds: $normalizedCats,
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
     * Parse a GMT datetime string ('Y-m-d H:i:s' or ISO-8601) into a UTC DateTimeImmutable.
     *
     * WordPress stores GMT datetimes as 'Y-m-d H:i:s'; '0000-00-00 00:00:00' signals
     * an unset datetime (treated as now-UTC fallback rather than failing hard, because
     * draft posts legitimately carry the zero-datetime for published_at).
     *
     * @throws ValidationException when the value is non-null and not parseable
     */
    private function parseDateGmt(mixed $value, string $field): \DateTimeImmutable
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        $str = (string) $value;

        // Already has timezone info — parse directly.
        if (str_contains($str, '+') || str_ends_with($str, 'Z')) {
            $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $str)
                ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $str)
                ?: false;
        } else {
            // WordPress GMT string: 'Y-m-d H:i:s' — treat as UTC.
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
