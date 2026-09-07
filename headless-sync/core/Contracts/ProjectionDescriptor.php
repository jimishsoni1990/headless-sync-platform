<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Infrastructure metadata describing where ONE aggregate type's delivery projection lives.
 *
 * DECISION AG (AG-3). Core must not know every domain table in the platform, so the three
 * lists that used to hardcode `content.*` — ReconciliationService::PROJECTION,
 * BackfillReader::PROJECTION and BackfillProgress::TYPES — are replaced by descriptors that
 * modules register.
 *
 * This is deliberately NOT a domain model. It carries only what reconciliation and backfill
 * genuinely need in order to count and compare rows: which table, which source-identity
 * column, and (for a shared projection told apart by a discriminator, DECISION AA/AG-9)
 * which discriminator value.
 *
 * SECURITY — the identifiers below reach SQL as identifiers, which cannot be bound as
 * parameters. They are therefore accepted ONLY from trusted module registration code and
 * are validated here against a strict pattern. They must never originate from API input,
 * user input, or any request-derived value.
 */
final class ProjectionDescriptor
{
    /**
     * Schema-qualified table or bare identifier: letters, digits and underscores per
     * segment, at most one dot. Deliberately stricter than PostgreSQL allows — quoted,
     * mixed-case and unicode identifiers are refused rather than escaped, because no
     * projection in this platform needs them and permitting them widens the surface.
     */
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*(\.[a-z_][a-z0-9_]*)?$/';

    /** Column identifier: a single unqualified segment. */
    private const COLUMN = '/^[a-z_][a-z0-9_]*$/';

    /**
     * @param string      $aggregateType     Aggregate type key, e.g. 'post', 'product'.
     * @param string      $table             Schema-qualified projection table, e.g. 'content.posts'.
     * @param string      $sourceIdColumn    Column holding the WordPress source id, e.g. 'source_post_id'.
     * @param string|null $discriminatorValue Value of the shared-projection discriminator, e.g.
     *                                        'post_tag' for tags inside content.taxonomies. Null for
     *                                        a table that holds exactly one aggregate type.
     * @param string|null $discriminatorColumn Column carrying that discriminator, e.g. 'taxonomy_type'.
     *
     * @throws \InvalidArgumentException if any identifier fails validation, or if exactly one
     *                                   half of the discriminator pair is supplied.
     */
    public function __construct(
        public readonly string $aggregateType,
        public readonly string $table,
        public readonly string $sourceIdColumn,
        public readonly ?string $discriminatorValue = null,
        public readonly ?string $discriminatorColumn = null,
    ) {
        if ($aggregateType === '') {
            throw new \InvalidArgumentException('ProjectionDescriptor: aggregateType must not be empty.');
        }

        if (preg_match(self::IDENTIFIER, $table) !== 1) {
            throw new \InvalidArgumentException(
                "ProjectionDescriptor: table '{$table}' is not a valid identifier."
            );
        }

        if (preg_match(self::COLUMN, $sourceIdColumn) !== 1) {
            throw new \InvalidArgumentException(
                "ProjectionDescriptor: sourceIdColumn '{$sourceIdColumn}' is not a valid identifier."
            );
        }

        // A discriminator is meaningless without both halves: a column with no value cannot
        // be filtered, and a value with no column has nowhere to go. Half a pair is a bug in
        // the registering module, so refuse it rather than silently ignoring the stray half —
        // an unscoped read of a shared taxonomy table is exactly the DECISION AA defect class.
        $hasColumn = $discriminatorColumn !== null;
        $hasValue  = $discriminatorValue !== null;

        if ($hasColumn !== $hasValue) {
            throw new \InvalidArgumentException(
                "ProjectionDescriptor for '{$aggregateType}': discriminatorColumn and"
                . ' discriminatorValue must be supplied together or not at all.'
            );
        }

        if ($hasColumn && preg_match(self::COLUMN, (string) $discriminatorColumn) !== 1) {
            throw new \InvalidArgumentException(
                "ProjectionDescriptor: discriminatorColumn '{$discriminatorColumn}' is not a valid identifier."
            );
        }
    }

    /** Does this projection share its table with other aggregate types? */
    public function isDiscriminated(): bool
    {
        return $this->discriminatorColumn !== null;
    }
}
