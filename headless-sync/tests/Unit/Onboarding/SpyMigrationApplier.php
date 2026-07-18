<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Onboarding\MigrationApplier;
use HSP\Core\Onboarding\MigrationApplyResult;

/**
 * Test double for {@see MigrationApplier} — scripts the engine outcome and counts invocations so
 * the controller boundary can be tested without a live PostgreSQL / migration engine.
 *
 * The real delegate closures are never invoked (they are no-ops here); apply() returns the scripted
 * result. `applyCalls` proves the endpoint gates the engine correctly (e.g. env preflight blocks it).
 */
final class SpyMigrationApplier extends MigrationApplier
{
    public int $applyCalls = 0;

    public function __construct(
        private readonly bool $ran,
        private readonly string $error = '',
        private readonly int $total = 13,
    ) {
        parent::__construct(
            static fn () => null,
            static fn (): array => [],
            static fn (): array => [],
        );
    }

    public function apply(): MigrationApplyResult
    {
        $this->applyCalls++;

        return new MigrationApplyResult($this->ran, $this->ran ? $this->total : 0, $this->error);
    }
}
