<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Rest;

use HSP\Core\Contracts\CursorPage;
use HSP\Core\Contracts\FilterSet;
use HSP\Core\Contracts\QueryProviderInterface;

/**
 * Controllable fake for QueryProviderInterface used in ContentRestRegistrar unit tests.
 */
final class FakeQueryProvider implements QueryProviderInterface
{
    public ?FilterSet $lastFilters = null;

    public function __construct(
        private readonly CursorPage $listResult,
        private readonly ?array     $singleRow = null,
    ) {}

    public function list(FilterSet $filters): CursorPage
    {
        $this->lastFilters = $filters;
        return $this->listResult;
    }

    public function findBySlug(string $slug): ?array
    {
        return $this->singleRow;
    }
}
