<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Operations;

use HSP\Core\Contracts\Operations\ModuleInspection;
use HSP\Core\Contracts\Operations\ModuleInspectionProviderInterface;
use HSP\Modules\Content\Events\ContentEventTypes;

/**
 * Content module self-description for the Module Inspector (Doc 12 §14).
 *
 * Implements the core-owned ModuleInspectionProviderInterface (Rule 5 — the module depends
 * only on core/Contracts/). DESCRIPTIVE METADATA ONLY: this builds a static ModuleInspection
 * from compile-time known facts (the OPEN-1 event types, the six hsp/v1 routes, the module's
 * transformer/adapter/handler names, and the Operations provider keys the module contributes).
 * It performs NO query — no PG, no MySQL, no WordPress read (per the ruling, constraint 3).
 *
 * The version is injected (sourced from the module manifest at the wiring site) rather than
 * hardcoded, so the descriptor never drifts from module.json.
 */
final class ContentModuleInspection implements ModuleInspectionProviderInterface
{
    public function __construct(
        private readonly string $version,
    ) {}

    public function inspect(): ModuleInspection
    {
        return new ModuleInspection(
            name: 'content',
            version: $this->version,
            eventTypes: array_values(ContentEventTypes::ALL),
            endpoints: [
                '/pages',
                '/pages/{slug}',
                '/posts',
                '/posts/{slug}',
                '/categories',
                '/categories/{slug}',
            ],
            transformers: ['PageTransformer', 'PostTransformer', 'CategoryTransformer'],
            adapters: ['PageAdapter', 'PostAdapter', 'CategoryAdapter'],
            // Content contributes no worker strategy of its own; the shared core engine
            // processes its events via handlers. Listed as handler spine for visibility.
            workers: [],
            providerKeys: [
                ContentMetricsProvider::KEY,
                ContentEndpointProvider::KEY,
            ],
            // Operational actions (Replay/Reconcile) are wired in OPSC-S4; none at OPSC-S2.
            actionKeys: [],
        );
    }
}
