<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptive metadata about one module for the Module Inspector (Doc 12 §14).
 *
 * DESCRIPTIVE METADATA ONLY — static per request. This DTO carries what a module IS
 * (its version, the event types it owns, the endpoints it publishes, its transformer /
 * adapter / worker class-or-type names, and the provider/action keys it contributes). It
 * carries NO live state and triggers NO query: there is no PG, MySQL, or WordPress read on
 * the descriptor path. Live operational state (queue depth, worker liveness, metrics) is the
 * job of the OPSC-S2 providers (Health/Worker/Queue/Metrics), not of this descriptor.
 *
 * Additive OPSC-S2 contract (seated per architect ruling 2026-07-15): it modifies no OPSC-S1
 * contract, DTO, or registry. It deliberately does NOT extend OperationsProviderInterface —
 * module inspection is a directly-queried diagnostics surface, kept OUT of the
 * RefreshCoordinator snapshot path (static metadata needs no refresh semantics), so the
 * coordinator's five-kind provider match is untouched.
 *
 * Rule 5: this DTO imports nothing outside core/Contracts/. Fields are plain strings/lists.
 *
 * Fields (all descriptive):
 *   $name          — module name (e.g. 'content').
 *   $version       — module version string (from the module manifest).
 *   $eventTypes    — fully-qualified event type names the module owns (OPEN-1).
 *   $endpoints     — published route paths the module serves (e.g. '/posts', '/posts/{slug}').
 *   $transformers  — transformer identifiers (class short names or logical names).
 *   $adapters      — adapter identifiers.
 *   $workers       — worker/strategy identifiers the module contributes, if any.
 *   $providerKeys  — Operations provider key()s the module registers (e.g. its diagnostics/metrics).
 *   $actionKeys    — Operations action keys the module registers (OPSC-S4; empty at OPSC-S2).
 *
 * @psalm-immutable
 */
final class ModuleInspection
{
    /**
     * @param list<string> $eventTypes
     * @param list<string> $endpoints
     * @param list<string> $transformers
     * @param list<string> $adapters
     * @param list<string> $workers
     * @param list<string> $providerKeys
     * @param list<string> $actionKeys
     */
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly array $eventTypes = [],
        public readonly array $endpoints = [],
        public readonly array $transformers = [],
        public readonly array $adapters = [],
        public readonly array $workers = [],
        public readonly array $providerKeys = [],
        public readonly array $actionKeys = [],
    ) {}
}
