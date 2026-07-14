<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Registries;

use HSP\Core\Contracts\Operations\AssetRegistryInterface;
use HSP\Core\Contracts\Operations\ConsoleAsset;

/**
 * Explicit-registration registry of Operations Console static assets (Doc 12 §4;
 * ADR-048/ADR-052).
 *
 * Keyed by handle. Duplicate handle is a composition-root error (\LogicException). Only
 * self-contained, hand-authored 'script'/'style' files are registrable — the MVP console
 * ships no bundle and has no node toolchain (DECISION V (a)). all()/forPage() preserve
 * registration order.
 */
final class AssetRegistry implements AssetRegistryInterface
{
    /** @var array<string, ConsoleAsset> handle → asset (insertion order preserved) */
    private array $assets = [];

    public function register(ConsoleAsset $asset): void
    {
        if ($asset->handle === '') {
            throw new \InvalidArgumentException('ConsoleAsset handle must not be empty.');
        }

        if ($asset->relPath === '') {
            throw new \InvalidArgumentException(
                "ConsoleAsset '{$asset->handle}' must declare a non-empty path."
            );
        }

        if ($asset->pageSlug === '') {
            throw new \InvalidArgumentException(
                "ConsoleAsset '{$asset->handle}' must target a non-empty page slug."
            );
        }

        if (! in_array($asset->type, [ConsoleAsset::TYPE_SCRIPT, ConsoleAsset::TYPE_STYLE], true)) {
            throw new \InvalidArgumentException(
                "ConsoleAsset '{$asset->handle}' type must be 'script' or 'style', got '{$asset->type}'."
            );
        }

        if (isset($this->assets[$asset->handle])) {
            throw new \LogicException(
                "An asset with handle '{$asset->handle}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->assets[$asset->handle] = $asset;
    }

    public function all(): array
    {
        return array_values($this->assets);
    }

    public function forPage(string $pageSlug): array
    {
        return array_values(array_filter(
            $this->assets,
            static fn (ConsoleAsset $a): bool => $a->pageSlug === $pageSlug,
        ));
    }
}
