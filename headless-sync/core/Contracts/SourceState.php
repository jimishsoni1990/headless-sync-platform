<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Immutable snapshot of an aggregate's CURRENT WordPress state, as observed by
 * WpReconciliationSourceInterface for drift detection (DECISION U v1.19).
 *
 *   $exists    — the WP entity currently exists.
 *   $public    — the entity is in the public set (OPEN-10 {publish}); for taxonomies,
 *                "public" == "exists" (terms have no post_status — DECISION U D2).
 *   $modifiedAt— WP post_modified_gmt as a UTC instant for pages/posts; null for
 *                taxonomies (no term modified timestamp — D2) or when the entity is
 *                absent. The hourly timestamp comparison uses this; a null value means
 *                the caller must fall back to existence-only / checksum detection.
 */
final class SourceState
{
    public function __construct(
        public readonly bool $exists,
        public readonly bool $public,
        public readonly ?\DateTimeImmutable $modifiedAt,
    ) {}

    /** A definitively-absent aggregate (missing in WordPress). */
    public static function absent(): self
    {
        return new self(false, false, null);
    }
}
