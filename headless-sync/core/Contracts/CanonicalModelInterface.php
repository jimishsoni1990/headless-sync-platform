<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Marker interface for canonical domain models produced by transformers.
 *
 * Canonical models are the single agreed representation of an entity between
 * transformer output and adapter input. Consumers and adapters depend on the
 * canonical model; they never depend on WordPress source models or PostgreSQL
 * schema columns directly (architectural rule 6 / CLAUDE.md).
 *
 * Implementations are value objects: immutable, no side effects.
 */
interface CanonicalModelInterface
{
    /** Identifier of the source WordPress entity (post ID, term ID, etc.). */
    public function getSourceId(): int;

    /**
     * sha256 checksum of the canonical representation; used for write-suppress
     * comparison against the stored projection checksum — DECISION 3.
     */
    public function getChecksum(): string;
}
