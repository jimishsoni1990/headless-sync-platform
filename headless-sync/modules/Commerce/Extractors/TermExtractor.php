<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Extractors;

use HSP\Modules\Commerce\SourceModels\TermSourceModel;
use HSP\Modules\Commerce\Validation\TermValidator;

/**
 * Normalises a loader-shaped term array into an immutable TermSourceModel.
 *
 * Taxonomy-generic — the taxonomy is read from the term itself rather than hardcoded, which is
 * what lets `pa_*` attribute terms (P2-S4) reuse this unchanged. `CategoryExtractor` in the
 * Content module had `'category'` baked in and had to be generalised retroactively in P1B-S3;
 * this starts generic.
 */
final class TermExtractor
{
    public function __construct(private readonly TermValidator $validator)
    {
    }

    /** @param array<string,mixed> $raw */
    public function extract(array $raw): TermSourceModel
    {
        $term = new TermSourceModel(
            termId:       (int) ($raw['term_id'] ?? 0),
            taxonomyType: (string) ($raw['taxonomy'] ?? ''),
            slug:         (string) ($raw['slug'] ?? ''),
            name:         (string) ($raw['name'] ?? ''),
            description:  (string) ($raw['description'] ?? ''),
            parentId:     (int) ($raw['parent'] ?? 0),
            count:        (int) ($raw['count'] ?? 0),
        );

        $this->validator->validate($term);

        return $term;
    }
}
