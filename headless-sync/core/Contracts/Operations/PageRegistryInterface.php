<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Registry of Operations Console pages (ADR-048 / ADR-052 — registry-driven, no hardcoding).
 *
 * EXPLICIT REGISTRATION ONLY — no reflection, no filesystem scanning, no wildcard
 * discovery (mirrors EventRegistry / AdapterRegistry). Pages are registered by
 * ServiceProviders during the register() phase. Duplicate slug registration is a
 * composition-root error and MUST throw \LogicException. Lookup of an unknown slug via
 * get() MUST throw \RuntimeException; has() answers presence without throwing.
 *
 * Core owns this contract; the console UI (OPSC-S3) consumes the registry to build nav
 * and route requests. This contract depends on nothing outside core/Contracts/.
 */
interface PageRegistryInterface
{
    /**
     * Register a console page.
     *
     * @throws \InvalidArgumentException if the page slug is empty.
     * @throws \LogicException           if a page with the same slug is already registered.
     */
    public function register(ConsolePage $page): void;

    /**
     * Whether a page with the given slug is registered.
     */
    public function has(string $slug): bool;

    /**
     * Return the registered page for the given slug.
     *
     * @throws \RuntimeException if no page is registered for the slug.
     */
    public function get(string $slug): ConsolePage;

    /**
     * All registered pages, ordered by position (ascending) then registration order.
     *
     * @return ConsolePage[]
     */
    public function all(): array;
}
