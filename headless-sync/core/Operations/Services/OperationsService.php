<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Services;

use HSP\Core\Contracts\Operations\ActionRegistryInterface;
use HSP\Core\Contracts\Operations\AssetRegistryInterface;
use HSP\Core\Contracts\Operations\NavigationRegistryInterface;
use HSP\Core\Contracts\Operations\PageRegistryInterface;
use HSP\Core\Contracts\Operations\WidgetRegistryInterface;

/**
 * The Operations Services layer — the single seam the console UI talks to (Doc 12 §10).
 *
 * "The UI never communicates directly with infrastructure" (Doc 12 §10; ADR-053). This
 * service fronts the registries (discovery) and the RefreshCoordinator + ConsoleStateStore
 * (provider runtime data) so that a would-be UI caller (OPSC-S3) can build the console
 * entirely from this one object — it never needs, and is never handed, a
 * DatabaseConnectionInterface, a concrete provider, a queue provider, or any other
 * infrastructure class. No infrastructure type appears in this class's public surface.
 *
 * OPSC-S1 is read-only: this service exposes navigation/pages/widgets/assets/actions for
 * DISCOVERY and provider snapshots for DISPLAY. It performs NO state change — the Replay /
 * Reconcile actions are wired as thin delegators in OPSC-S4 (DECISION V (d)). It opens NO
 * PostgreSQL connection and writes NO persistence (DECISION V (c)/(g)); provider PG reads,
 * when they arrive in OPSC-S2, are encapsulated inside the concrete providers on the
 * delivery handle and reach this layer only as immutable snapshots via the state store.
 *
 * All dependencies are constructor-injected (ADR-012 / Rule 7); this class never calls
 * Container::get().
 */
final class OperationsService
{
    public function __construct(
        private readonly PageRegistryInterface $pages,
        private readonly NavigationRegistryInterface $navigation,
        private readonly WidgetRegistryInterface $widgets,
        private readonly ActionRegistryInterface $actions,
        private readonly AssetRegistryInterface $assets,
        private readonly RefreshCoordinator $coordinator,
        private readonly ConsoleStateStore $store,
    ) {}

    // -------------------------------------------------------------------------
    // Discovery (from registries) — read-only
    // -------------------------------------------------------------------------

    /** @return \HSP\Core\Contracts\Operations\NavigationItem[] */
    public function navigation(): array
    {
        return $this->navigation->all();
    }

    /** @return \HSP\Core\Contracts\Operations\ConsolePage[] */
    public function pages(): array
    {
        return $this->pages->all();
    }

    /** @return \HSP\Core\Contracts\Operations\ConsoleWidget[] */
    public function widgetsForPage(string $pageSlug): array
    {
        return $this->widgets->forPage($pageSlug);
    }

    /** @return \HSP\Core\Contracts\Operations\ConsoleAsset[] */
    public function assetsForPage(string $pageSlug): array
    {
        return $this->assets->forPage($pageSlug);
    }

    /** @return \HSP\Core\Contracts\Operations\ConsoleAction[] */
    public function actions(): array
    {
        return $this->actions->all();
    }

    // -------------------------------------------------------------------------
    // Runtime data (from providers, via the coordinator + store) — read-only
    // -------------------------------------------------------------------------

    /**
     * Refresh every registered provider once, then return all snapshots keyed by provider.
     *
     * Centralized refresh (Doc 12 §8): one provider call per provider regardless of how many
     * widgets consume it.
     *
     * @return array<string, mixed> provider key → snapshot
     */
    public function refreshAll(): array
    {
        $this->coordinator->refresh();

        return $this->store->all();
    }

    /**
     * Return the current snapshot for one provider key, refreshing it if not yet cached
     * this request.
     *
     * @throws \RuntimeException if no provider is registered under the key.
     */
    public function snapshot(string $providerKey): mixed
    {
        if (! $this->store->has($providerKey)) {
            $this->coordinator->refreshOne($providerKey);
        }

        return $this->store->get($providerKey);
    }

    /**
     * The snapshot feeding a specific widget — resolved by the widget's providerKey.
     *
     * Widgets never poll infrastructure (Doc 12 §7); they read their provider's snapshot
     * through this method.
     *
     * @throws \RuntimeException if no widget with $widgetId exists on $pageSlug, or its
     *                           provider is not registered.
     */
    public function widgetSnapshot(string $pageSlug, string $widgetId): mixed
    {
        foreach ($this->widgets->forPage($pageSlug) as $widget) {
            if ($widget->id === $widgetId) {
                return $this->snapshot($widget->providerKey);
            }
        }

        throw new \RuntimeException(
            "No widget '{$widgetId}' registered on page '{$pageSlug}'."
        );
    }

    /**
     * Every published endpoint descriptor, aggregated across all registered endpoint providers.
     *
     * Feeds the API Playground (ADR-050 / Doc 12 §15) so it lists and executes endpoints from
     * EndpointProviderInterface metadata — never hardcoding routes. Refreshes any endpoint
     * provider not yet snapshotted this request, then flattens all endpoint snapshots. The UI
     * receives plain EndpointDescriptor DTOs; it never touches a provider or infrastructure
     * (ADR-053).
     *
     * @return \HSP\Core\Contracts\Operations\EndpointDescriptor[]
     */
    public function endpointDescriptors(): array
    {
        $descriptors = [];

        foreach ($this->coordinator->endpointKeys() as $key) {
            $snapshot = $this->snapshot($key);
            if (is_array($snapshot)) {
                foreach ($snapshot as $descriptor) {
                    $descriptors[] = $descriptor;
                }
            }
        }

        return $descriptors;
    }
}
