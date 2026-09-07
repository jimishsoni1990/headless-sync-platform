<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Rest;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\HierarchicalQueryProviderInterface;
use HSP\Core\Contracts\QueryProviderInterface;

/**
 * Controllable fake for QueryProviderInterface used in ContentRestRegistrar unit tests.
 *
 * Also implements the hierarchical capability so it can stand in for the PAGE provider, whose
 * constructor slot requires both (DECISION AD). $pathRow is scripted separately from $singleRow so
 * a test can express the case the whole ruling turns on: an exact-path MISS alongside a leaf HIT,
 * which is the only situation where the deprecated one-segment fallback may fire.
 */
final class FakeQueryProvider implements QueryProviderInterface, HierarchicalQueryProviderInterface
{
    public ?FilterSet $lastFilters = null;

    /** @var list<string> every path passed to findByPath(), in order. */
    public array $pathCalls = [];

    /** @var list<string> every slug passed to findBySlug(), in order. */
    public array $slugCalls = [];

    public function __construct(
        private readonly CursorPage $listResult,
        private readonly ?array     $singleRow = null,
        private readonly ?array     $pathRow = null,
    ) {}

    public function list(FilterSet $filters): CursorPage
    {
        $this->lastFilters = $filters;
        return $this->listResult;
    }

    public function findBySlug(string $slug): ?array
    {
        $this->slugCalls[] = $slug;
        return $this->singleRow;
    }

    public function findByPath(string $path): ?array
    {
        $this->pathCalls[] = $path;
        return $this->pathRow;
    }
}
