<?php

declare(strict_types=1);

namespace HSP\Core\Module;

use HSP\Core\Contracts\ServiceProviderInterface;

/**
 * Result of composing the discovered modules' service providers (DECISION AG AG-1).
 *
 * Immutable value object handed from ModuleProviderComposer to the composition root.
 */
final class ModuleComposition
{
    /**
     * @param list<ServiceProviderInterface> $providers          Providers of AVAILABLE modules, in discovery order.
     * @param list<string>                   $availableModules   Names of modules whose requirements are satisfied.
     * @param list<string>                   $unavailableModules Names of discovered-but-unavailable modules.
     */
    public function __construct(
        public readonly array $providers,
        public readonly array $availableModules,
        public readonly array $unavailableModules,
    ) {
    }
}
