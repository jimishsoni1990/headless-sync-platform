<?php

declare(strict_types=1);

namespace HSP\Modules\Content\SourceModels;

/**
 * Normalized, immutable snapshot of a WordPress category term (taxonomy='category').
 *
 * Produced by CategoryExtractor from raw WP_Term-shaped data.
 * Consumed by CategoryTransformer (P1A-S3) — never by adapters directly.
 */
final class CategorySourceModel
{
    /**
     * @param int    $termId      wp_terms.term_id
     * @param string $name        term name (display label)
     * @param string $slug        URL slug
     * @param string $description term description (may be empty string)
     * @param int    $parentId    parent term_id (0 = top-level category)
     * @param int    $count       post count from wp_term_taxonomy.count
     */
    public function __construct(
        public readonly int $termId,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $description,
        public readonly int $parentId,
        public readonly int $count,
    ) {}
}
