<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Declares whether a module's external runtime requirements are satisfied.
 *
 * DECISION AG (AG-12) separates three lifecycle conditions:
 *
 *   DISCOVERED      module code is known to HSP (a module.json exists)
 *   AVAILABLE       external/runtime requirements are satisfied — this contract
 *   READY / ACTIVE  required module migrations have applied; registrations may participate
 *
 * Availability is NOT readiness. A module that reports available but whose migrations
 * have not run is still not runtime-active, and no endpoint or projection consumer may
 * run against its missing schema.
 *
 * Implemented by a module's ServiceProvider rather than by ModuleInterface itself: the
 * module INSTANCE is resolved from the container and so cannot exist before its own
 * provider has registered, whereas availability must be known BEFORE that provider is
 * composed. A provider that does not implement this contract is always available.
 *
 * Availability must never be inferred from a missing service binding, and must never be
 * hidden inside a provider as an undocumented no-op — it is an explicit declaration.
 *
 * Example: the Commerce module is available only when WooCommerce is present.
 */
interface ModuleAvailabilityInterface
{
    /**
     * Are this module's external runtime requirements satisfied right now?
     *
     * Must be cheap and side-effect free — it is called during composition, on every
     * request, before any binding is registered. Must not open a database connection,
     * perform I/O, or throw: an unavailable dependency is a normal state, not an error.
     */
    public function isAvailable(): bool;
}
