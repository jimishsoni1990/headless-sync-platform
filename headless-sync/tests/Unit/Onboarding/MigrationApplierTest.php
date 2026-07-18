<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Contracts\MigrationInterface;
use HSP\Core\Onboarding\MigrationApplier;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MigrationApplier — the thin delegator to the EXISTING migration engine
 * (ONB-S2 self-remediation; DECISION W (e) delegate list; DECISION W (f) v1.23).
 *
 * Proves: it assembles the core + module delegate list and calls the engine (bootstrap + run); it
 * NEVER throws (a resolver/engine failure becomes a reported result, so activation/upgrade and the
 * migrate endpoint can never fatal — Principle 8); it resolves inputs LAZILY (nothing is resolved
 * until apply() runs). A fake runner (duck-typed via the untyped resolver) stands in for the engine.
 */
final class MigrationApplierTest extends TestCase
{
    public function test_apply_bootstraps_then_runs_the_full_core_plus_module_delegate_list(): void
    {
        $runner = new FakeMigrationRunner();
        $core    = [$this->migration('0001_core'), $this->migration('0002_core')];
        $modules = [$this->migration('0001_content'), $this->migration('0002_content')];

        $applier = new MigrationApplier(
            fn () => $runner,
            fn (): array => $core,
            fn (): array => $modules,
        );

        $result = $applier->apply();

        self::assertTrue($result->ran);
        self::assertSame(4, $result->total);
        self::assertSame('', $result->error);
        // bootstrap() ran exactly once before run(), and run() received the full delegate list.
        self::assertSame(1, $runner->bootstrapCalls);
        self::assertCount(4, $runner->ranMigrations);
    }

    public function test_apply_is_lazy_nothing_is_resolved_until_apply_runs(): void
    {
        $touched = ['runner' => false, 'core' => false, 'modules' => false];

        $applier = new MigrationApplier(
            function () use (&$touched) { $touched['runner'] = true; return new FakeMigrationRunner(); },
            function () use (&$touched): array { $touched['core'] = true; return []; },
            function () use (&$touched): array { $touched['modules'] = true; return []; },
        );

        // Construction alone must resolve nothing (the pgsql DDL link would fatal an unconfigured site).
        self::assertFalse($touched['runner']);
        self::assertFalse($touched['core']);
        self::assertFalse($touched['modules']);

        $applier->apply();

        self::assertTrue($touched['runner']);
        self::assertTrue($touched['core']);
        self::assertTrue($touched['modules']);
    }

    public function test_apply_never_throws_when_the_runner_resolver_throws(): void
    {
        // Mirrors the pgsql DDL link failing to open on an unreachable / unconfigured PG.
        $applier = new MigrationApplier(
            fn () => throw new \RuntimeException('libpq connect failed'),
            fn (): array => [],
            fn (): array => [],
        );

        $result = $applier->apply();

        self::assertFalse($result->ran);
        self::assertSame('libpq connect failed', $result->error);
    }

    public function test_apply_never_throws_when_the_engine_run_throws_mid_way(): void
    {
        $runner = new FakeMigrationRunner(throwOnRun: 'DDL failed');

        $applier = new MigrationApplier(
            fn () => $runner,
            fn (): array => [$this->migration('0001_core')],
            fn (): array => [],
        );

        $result = $applier->apply();

        self::assertFalse($result->ran);
        self::assertSame('DDL failed', $result->error);
    }

    private function migration(string $name): MigrationInterface
    {
        return new class ($name) implements MigrationInterface {
            public function __construct(private string $name) {}
            public function getName(): string { return $this->name; }
            public function getSchemaContext(): string { return 'test/pgsql'; }
            public function up(): void {}
            public function down(): void {}
        };
    }
}
