<?php

declare(strict_types=1);

namespace HSP\Core\Rest;

/**
 * The single, authoritative list of every CORE-owned HSP REST registrar (OAPI-S1).
 *
 * WHY THIS EXISTS: the OpenAPI drift guard (ADR-055 (f)) must enumerate the FULL live `hsp/v1`
 * route index as external ground truth. That is only omission-proof if route collection funnels
 * through the SAME aggregation production uses — otherwise a core registrar added to production but
 * not to the test's list would register live routes the guard never sees (the exact drift the
 * guard exists to catch).
 *
 * This registry is that funnel FOR CORE REST REGISTRARS: `headless-sync.php` boots them by
 * iterating this list, and the drift-guard test enumerates the same list. Adding a core REST
 * registrar in ONE place makes it visible to the guard automatically.
 *
 * MODULE REST registrars (e.g. Content's delivery routes) are NOT listed here: they are booted
 * through the module lifecycle (`ModuleRegistrar` → `Module::boot()`), which is itself omission-
 * proof (a new module is auto-discovered), and the drift-guard test drives that same module-boot
 * path. So between this list (core) and module boot (modules), no live `hsp/v1` route can be
 * registered outside the guard's reach.
 *
 * Each key resolves to an object with a public `register(): void` that calls register_rest_route();
 * registrars no-op outside a WordPress runtime.
 *
 * Adding a core REST registrar: add its container key here — nowhere else.
 */
final class RestRegistrarRegistry
{
    /**
     * Container binding keys for every CORE HSP REST registrar.
     *
     * @return list<class-string>
     */
    public static function coreRegistrarKeys(): array
    {
        return [
            // OAPI-S1: GET /hsp/v1/openapi.json (ADR-055) — public + stateless generator endpoint.
            \HSP\Core\Operations\OpenApi\OpenApiRestRegistrar::class,
            // Onboarding first-run admin JSON endpoints (hsp/v1/onboarding/* — DECISION W (a)/(e)).
            // Present in the live index; EXEMPTED from the completeness assertion by the frozen
            // `hsp/v1/onboarding/` prefix (ADR-055 (f), v1.28) — admin surface, not delivery contract.
            \HSP\Core\Onboarding\OnboardingRestRegistrar::class,
        ];
    }
}
