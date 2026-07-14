<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Services;

/**
 * Request-scope cache of provider snapshots for the Operations Console (Doc 12 §9).
 *
 * The single source of truth for console UI state WITHIN ONE REQUEST. This is a plain
 * in-memory map — NOT persistence and NOT a JavaScript state store. The MVP console is
 * server-rendered PHP (DECISION V (a)); this store lives for the duration of one wp-admin
 * request and is discarded when the request ends. It writes NOTHING to any database and
 * opens NO PostgreSQL connection (DECISION V (c)/(g) — zero new persistence; provider PG
 * reads, when they exist in OPSC-S2, ride the delivery handle inside the provider, never
 * here).
 *
 * The Refresh Coordinator populates this store once per refresh; widgets read their
 * snapshot from here by the provider key they reference (Doc 12 §7/§8 — widgets never poll
 * independently).
 */
final class ConsoleStateStore
{
    /** @var array<string, mixed> provider key → last snapshot produced this request */
    private array $snapshots = [];

    /**
     * Store the snapshot produced by the provider with the given key.
     */
    public function put(string $providerKey, mixed $snapshot): void
    {
        if ($providerKey === '') {
            throw new \InvalidArgumentException('Provider key must not be empty.');
        }

        $this->snapshots[$providerKey] = $snapshot;
    }

    /**
     * Whether a snapshot has been stored for the given provider key this request.
     */
    public function has(string $providerKey): bool
    {
        return array_key_exists($providerKey, $this->snapshots);
    }

    /**
     * Return the stored snapshot for the given provider key.
     *
     * @throws \RuntimeException if no snapshot has been stored for the key this request.
     */
    public function get(string $providerKey): mixed
    {
        if (! $this->has($providerKey)) {
            throw new \RuntimeException(
                "No snapshot stored for provider key '{$providerKey}'. "
                . 'Refresh the coordinator before reading widget state.'
            );
        }

        return $this->snapshots[$providerKey];
    }

    /**
     * All stored snapshots keyed by provider key.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->snapshots;
    }

    /**
     * Discard all stored snapshots (e.g. to force a full re-refresh within a request).
     */
    public function clear(): void
    {
        $this->snapshots = [];
    }
}
