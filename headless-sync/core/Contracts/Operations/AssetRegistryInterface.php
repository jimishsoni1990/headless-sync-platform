<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Registry of Operations Console static assets (Doc 12 §4; ADR-048/ADR-052).
 *
 * EXPLICIT REGISTRATION ONLY — no reflection or scanning. Assets are registered by
 * ServiceProviders during register(). Duplicate handle registration is a composition-root
 * error and MUST throw \LogicException.
 *
 * Only self-contained, hand-authored static files are registrable — the MVP console has
 * no node/npm/bundler toolchain and ships no JS/TS bundle (DECISION V (a)). Enqueueing at
 * the wp-admin boundary happens in a later session; this registry records the files only.
 *
 * Core owns this contract; depends on nothing outside core/Contracts/.
 */
interface AssetRegistryInterface
{
    /**
     * Register a static asset.
     *
     * @throws \InvalidArgumentException if the handle, path, or page slug is empty,
     *                                   or the type is neither 'script' nor 'style'.
     * @throws \LogicException           if an asset with the same handle is already registered.
     */
    public function register(ConsoleAsset $asset): void;

    /**
     * All registered assets, in registration order.
     *
     * @return ConsoleAsset[]
     */
    public function all(): array;

    /**
     * Registered assets scoped to the given page, in the same order as all().
     *
     * @return ConsoleAsset[]
     */
    public function forPage(string $pageSlug): array;
}
