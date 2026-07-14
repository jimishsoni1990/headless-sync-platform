<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptor of one Operations Console operational action (Doc 12 §17).
 *
 * DESCRIPTOR ONLY. OPSC-S1 defines the shape and the Action Registry; it wires NO
 * behaviour and NO state-changing code (that is OPSC-S4). The console is read-only by
 * default (ADR-053) — every action carries a required capability and a confirmation
 * flag so state change is always explicit, discoverable, and auditable.
 *
 * DECISION V binds the action set for the whole platform:
 *   - The only permitted actions are Replay and Reconcile — thin delegators to the
 *     ratified ReplayService (DECISION T/S) and ReconciliationService (DECISION U);
 *     they never open a second repair path (DECISION V (d)).
 *   - There is NO Flush Queue (DECISION V (e)) and NO Restart Workers (DECISION V (f)).
 *   - ADR-051 (Operational Actions) is HELD and NOT citable; the authority for the
 *     action model is DECISION V (d)/(e)/(f) + ADR-053, cited directly.
 * This descriptor deliberately has no "destructive" affordance beyond the fields below;
 * no session may register a destructive-deletion action.
 *
 * Fields:
 *   $key                  — stable identifier, unique within the Action Registry
 *                           (e.g. 'replay', 'reconcile').
 *   $label                — human-readable action label.
 *   $capability           — WordPress capability required to invoke (enforced at the
 *                           wp-admin boundary in OPSC-S4 — DECISION V (b)).
 *   $confirmationRequired — the UI must confirm before invoking (Doc 12 §17).
 */
final class ConsoleAction
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $capability,
        public readonly bool $confirmationRequired = true,
    ) {}
}
