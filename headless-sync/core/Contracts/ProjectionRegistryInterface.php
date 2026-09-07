<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Core-owned registry of module-supplied projection descriptors (DECISION AG AG-3).
 *
 * Replaces the hardcoded `content.*` maps that used to live inside
 * ReconciliationService, BackfillReader and BackfillProgress. Those lists could not grow
 * into content.* + commerce.* + membership.* without core learning every domain table in
 * the platform; modules now register what they own.
 *
 * Registration is EXPLICIT — no reflection, no scanning (ADR-048/052). Duplicate
 * registration for one aggregate type throws immediately: silently overwriting one
 * module's projection with another's is the defect class AG-2 and AG-3 exist to remove.
 */
interface ProjectionRegistryInterface
{
    /**
     * @throws \LogicException if this aggregate type is already registered.
     */
    public function register(ProjectionDescriptor $descriptor): void;

    /** Is there a projection registered for this aggregate type? */
    public function has(string $aggregateType): bool;

    /**
     * @throws \InvalidArgumentException if the aggregate type is not registered. Callers
     *         must NOT silently skip an unknown aggregate — that is precisely the
     *         `continue` that let media and tags go unreconciled (DECISION AC).
     */
    public function get(string $aggregateType): ProjectionDescriptor;

    /**
     * All registered descriptors, keyed by aggregate type.
     *
     * @return array<string, ProjectionDescriptor>
     */
    public function all(): array;

    /**
     * All registered aggregate types.
     *
     * @return list<string>
     */
    public function aggregateTypes(): array;
}
