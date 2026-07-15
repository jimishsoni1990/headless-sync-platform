<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Services;

use HSP\Core\Contracts\Operations\EndpointProviderInterface;
use HSP\Core\Contracts\Operations\HealthProviderInterface;
use HSP\Core\Contracts\Operations\MetricsProviderInterface;
use HSP\Core\Contracts\Operations\OperationsProviderInterface;
use HSP\Core\Contracts\Operations\QueueStatusProviderInterface;
use HSP\Core\Contracts\Operations\WorkerStatusProviderInterface;

/**
 * Centralizes refresh scheduling for the Operations Console (Doc 12 §8).
 *
 * Providers are registered explicitly by their key() (no reflection, no scanning). On
 * refresh(), the coordinator invokes each provider ONCE and writes its snapshot into the
 * ConsoleStateStore. This is why "widgets never poll independently" (Doc 12 §7): a widget
 * reads its provider's snapshot from the state store instead of calling the provider itself,
 * so N widgets over one provider produce exactly one provider call per refresh.
 *
 * Request-scope only. The coordinator holds no persistent state, opens NO PostgreSQL
 * connection, and writes to nothing but the in-memory state store (DECISION V (c)/(g)). Any
 * PG read lives inside a concrete provider (OPSC-S2) on the delivery handle — never here.
 *
 * Snapshot extraction is a match over the known core provider interfaces (explicit, not
 * reflective). A provider that implements only the base OperationsProviderInterface without
 * one of the known data interfaces is rejected at registration with a clear error.
 */
final class RefreshCoordinator
{
    /** @var array<string, OperationsProviderInterface> provider key → provider */
    private array $providers = [];

    public function __construct(
        private readonly ConsoleStateStore $store,
    ) {}

    /**
     * Register a provider under its own key() (explicit registration only).
     *
     * @throws \InvalidArgumentException if the provider key is empty or the provider is not
     *                                   one of the known data-provider interfaces.
     * @throws \LogicException           if a provider with the same key is already registered.
     */
    public function addProvider(OperationsProviderInterface $provider): void
    {
        $key = $provider->key();

        if ($key === '') {
            throw new \InvalidArgumentException('Provider key() must not be empty.');
        }

        if (! $this->isKnownProvider($provider)) {
            throw new \InvalidArgumentException(
                "Provider '{$key}' does not implement a known Operations data-provider "
                . 'interface (Health/Metrics/WorkerStatus/QueueStatus/Endpoint).'
            );
        }

        if (isset($this->providers[$key])) {
            throw new \LogicException(
                "A provider with key '{$key}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->providers[$key] = $provider;
    }

    /**
     * Whether a provider is registered under the given key.
     */
    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * All registered provider keys.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    /**
     * Keys of every registered EndpointProviderInterface (for the API Playground).
     *
     * Lets a consumer discover which providers describe delivery endpoints without holding a
     * provider reference itself (ADR-053) — the keys route back through snapshot storage.
     *
     * @return string[]
     */
    public function endpointKeys(): array
    {
        $keys = [];
        foreach ($this->providers as $key => $provider) {
            if ($provider instanceof EndpointProviderInterface) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Invoke every registered provider once and store its snapshot in the state store.
     *
     * Returns the number of providers refreshed.
     */
    public function refresh(): int
    {
        foreach ($this->providers as $key => $provider) {
            $this->store->put($key, $this->snapshot($provider));
        }

        return count($this->providers);
    }

    /**
     * Refresh a single provider by key and store its snapshot.
     *
     * @throws \RuntimeException if no provider is registered under the key.
     */
    public function refreshOne(string $key): void
    {
        if (! $this->has($key)) {
            throw new \RuntimeException("No provider registered under key '{$key}'.");
        }

        $this->store->put($key, $this->snapshot($this->providers[$key]));
    }

    // -------------------------------------------------------------------------
    // Internal — explicit (non-reflective) snapshot extraction
    // -------------------------------------------------------------------------

    private function isKnownProvider(OperationsProviderInterface $provider): bool
    {
        return $provider instanceof HealthProviderInterface
            || $provider instanceof MetricsProviderInterface
            || $provider instanceof WorkerStatusProviderInterface
            || $provider instanceof QueueStatusProviderInterface
            || $provider instanceof EndpointProviderInterface;
    }

    private function snapshot(OperationsProviderInterface $provider): mixed
    {
        return match (true) {
            $provider instanceof HealthProviderInterface       => $provider->reports(),
            $provider instanceof MetricsProviderInterface      => $provider->samples(),
            $provider instanceof WorkerStatusProviderInterface => $provider->statuses(),
            $provider instanceof QueueStatusProviderInterface  => $provider->status(),
            $provider instanceof EndpointProviderInterface     => $provider->endpoints(),
            // Unreachable: registration rejects unknown providers up front.
            default => throw new \LogicException(
                'Unknown provider type reached snapshot(): ' . $provider::class
            ),
        };
    }
}
