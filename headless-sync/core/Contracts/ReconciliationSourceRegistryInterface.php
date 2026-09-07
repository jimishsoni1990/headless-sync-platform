<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Core-owned registry of module WordPress-side reconciliation sources (DECISION AG AG-2).
 *
 * Replaces the SCALAR `WpReconciliationSourceInterface` binding, which was the worse half
 * of the single-module coupling: ReconciliationService and BackfillProgress each took one
 * instance, so a second module's source had nowhere to go at all.
 *
 * Backfill and reconciliation must enumerate ALL registered sources — enumerating one, or
 * skipping an aggregate that has no descriptor, is how media and tags went unreconciled
 * until DECISION AC.
 */
interface ReconciliationSourceRegistryInterface
{
    /**
     * @throws \LogicException if any of the source's aggregate types is already registered.
     */
    public function register(WpReconciliationSourceInterface $source): void;

    public function has(string $aggregateType): bool;

    /**
     * @throws \InvalidArgumentException if no source is registered for the type.
     */
    public function get(string $aggregateType): WpReconciliationSourceInterface;

    /**
     * Every aggregate type any registered source supports, in registration order.
     *
     * @return list<string>
     */
    public function aggregateTypes(): array;

    /**
     * The distinct registered sources.
     *
     * @return list<WpReconciliationSourceInterface>
     */
    public function sources(): array;
}
