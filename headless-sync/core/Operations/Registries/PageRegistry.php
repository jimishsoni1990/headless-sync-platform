<?php

declare(strict_types=1);

namespace HSP\Core\Operations\Registries;

use HSP\Core\Contracts\Operations\ConsolePage;
use HSP\Core\Contracts\Operations\PageRegistryInterface;

/**
 * Explicit-registration registry of Operations Console pages (ADR-048/ADR-052).
 *
 * No reflection, no filesystem scanning, no wildcard discovery — every page is an explicit
 * register() call from a ServiceProvider (mirrors EventRegistry / AdapterRegistry). Duplicate
 * slug is a composition-root error (\LogicException); unknown slug on get() throws
 * \RuntimeException. all() is ordered by ConsolePage::$position ascending, ties broken by
 * registration order (stable).
 */
final class PageRegistry implements PageRegistryInterface
{
    /** @var array<string, ConsolePage> slug → page */
    private array $pages = [];

    /** @var array<string, int> slug → registration sequence (stable tiebreaker) */
    private array $sequence = [];

    private int $next = 0;

    public function register(ConsolePage $page): void
    {
        if ($page->slug === '') {
            throw new \InvalidArgumentException('ConsolePage slug must not be empty.');
        }

        if (isset($this->pages[$page->slug])) {
            throw new \LogicException(
                "A console page with slug '{$page->slug}' is already registered. "
                . 'Duplicate registration is a composition-root error.'
            );
        }

        $this->pages[$page->slug]    = $page;
        $this->sequence[$page->slug] = $this->next++;
    }

    public function has(string $slug): bool
    {
        return isset($this->pages[$slug]);
    }

    public function get(string $slug): ConsolePage
    {
        if (! $this->has($slug)) {
            throw new \RuntimeException("No console page registered for slug '{$slug}'.");
        }

        return $this->pages[$slug];
    }

    public function all(): array
    {
        $pages = array_values($this->pages);

        usort($pages, function (ConsolePage $a, ConsolePage $b): int {
            return [$a->position, $this->sequence[$a->slug]]
               <=> [$b->position, $this->sequence[$b->slug]];
        });

        return $pages;
    }
}
