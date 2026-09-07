<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Validation;

use HSP\Modules\Commerce\SourceModels\TermSourceModel;

/**
 * Fail-fast structural validation of an extracted Commerce term.
 *
 * The taxonomy check is not decoration: a term with no taxonomy cannot be written to a SHARED
 * projection at all, because the discriminator is what tells a `product_cat` row from a
 * `pa_colour` row. An unscoped or blank-scoped row in that table is the DECISION AA defect
 * class — a read that forgets the predicate then sees rows it should never have seen.
 */
final class TermValidator
{
    public function validate(TermSourceModel $term): void
    {
        if ($term->termId <= 0) {
            throw new ValidationException('Term source id must be a positive integer.');
        }

        if (trim($term->taxonomyType) === '') {
            throw new ValidationException(
                "Term {$term->termId} has no taxonomy; it cannot be written to the shared "
                . 'commerce.taxonomies projection without a discriminator.'
            );
        }

        if (trim($term->slug) === '') {
            throw new ValidationException("Term {$term->termId} has an empty slug and cannot be addressed.");
        }

        if (trim($term->name) === '') {
            throw new ValidationException("Term {$term->termId} has an empty name.");
        }
    }
}
