<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Contracts\MigrationInterface;

/**
 * Duck-typed stand-in for the EXISTING MigrationRunner (which is final and opens a live pgsql
 * link). MigrationApplier resolves the runner through an untyped closure, so this fake — exposing
 * the same bootstrap()/run() surface — lets the applier be tested without a database.
 */
final class FakeMigrationRunner
{
    public int $bootstrapCalls = 0;

    /** @var list<MigrationInterface> */
    public array $ranMigrations = [];

    public function __construct(private readonly ?string $throwOnRun = null) {}

    public function bootstrap(): void
    {
        $this->bootstrapCalls++;
    }

    /** @param list<MigrationInterface> $migrations */
    public function run(array $migrations): void
    {
        if ($this->throwOnRun !== null) {
            throw new \RuntimeException($this->throwOnRun);
        }

        $this->ranMigrations = $migrations;
    }
}
