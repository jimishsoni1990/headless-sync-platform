<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Transforms a source model (extracted from WordPress) into a canonical model.
 *
 * Transformers are pure: no side effects, no I/O, no database reads.
 * They depend only on data passed in; the worker loads the WordPress state
 * before invoking the transformer (ADR-044: workers are stateless).
 *
 * Architectural rule: Transform Before Persist (Doc 3 §1 / CLAUDE.md rule 2).
 */
interface TransformerInterface
{
    /**
     * @param object               $source Source model produced by an EntityProviderInterface
     * @param array<string, mixed> $context  Event envelope metadata (event_id, aggregate_version, etc.)
     */
    public function transform(object $source, array $context = []): CanonicalModelInterface;

    /** Returns the canonical model class name this transformer produces. */
    public function getCanonicalModelClass(): string;
}
