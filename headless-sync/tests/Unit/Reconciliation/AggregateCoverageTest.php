<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Reconciliation;

use HSP\Core\Contracts\ProjectionRegistryInterface;
use HSP\Core\Contracts\ReconciliationSourceRegistryInterface;
use HSP\Core\Contracts\ReplayEmitterRegistryInterface;
use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Modules\Content\Reconciliation\WpReconciliationSource;
use HSP\Modules\Content\Replay\ContentReplayEmitter;
use HSP\Tests\Support\ContentProjections;
use PHPUnit\Framework\TestCase;

/**
 * FLAG-RECON-COVERAGE-1, generalised past Content by DECISION AG (AG-2/AG-3).
 *
 * A module declares which aggregates it can reconcile and replay; core decides which of
 * those it actually projects. If those two sides disagree the consequence is not a crash
 * but an ABSENCE: the type is never reconciled and — since the onboarding backfill IS
 * reconcileFull() (DECISION W (b)) — never backfilled either, so a site is declared
 * converged with none of it projected. Media (P1B-S1) and tags (P1B-S3) sat in exactly
 * that hole until DECISION AC.
 *
 * What changed in P2-S1: the three hardcoded `content.*` lists in core are gone, replaced
 * by registries modules populate. The seam therefore moved — it is no longer "is this type
 * in core's constant?" but "did the module register a projection and an emitter for every
 * type its source supports?". That question is domain-agnostic, so this guard now protects
 * Commerce and every module after it, not just Content.
 *
 * Phase1BValidationTest guards the same seam on live infrastructure; this holds without a
 * database, which is the condition under which someone actually adds an aggregate.
 */
final class AggregateCoverageTest extends TestCase
{
    /**
     * The generalised invariant: for EVERY registered reconciliation source, every aggregate
     * type it supports must have a projection descriptor and a replay emitter.
     *
     * This is the assertion that would have caught media and tags, and it is written against
     * the registry contracts rather than any module's constants — so a second module gets it
     * for free.
     */
    public function testEverySupportedAggregateHasAProjectionAndAnEmitter(): void
    {
        $sources     = $this->sourceRegistry();
        $projections = $this->projectionRegistry();
        $emitters    = $this->emitterRegistry();

        self::assertNotSame([], $sources->aggregateTypes(), 'the guard must actually scan something');

        foreach ($sources->aggregateTypes() as $type) {
            self::assertTrue(
                $projections->has($type),
                "'{$type}' is reconcilable but has no registered ProjectionDescriptor, so every "
                . 'reconcile mode reports it uncovered and the onboarding backfill never emits it.',
            );

            self::assertTrue(
                $emitters->has($type),
                "'{$type}' is reconcilable but has no registered replay emitter — reconciliation "
                . 'repairs EXCLUSIVELY by re-emission (DECISION T/U), so it could be detected as '
                . 'drifted and never repaired.',
            );
        }
    }

    /**
     * An aggregate a source supports but no projection covers must be REPORTED, never skipped
     * in silence. This is the runtime half of the guard: DECISION AC deliberately rejected a
     * runtime throw here, because a module shipping an aggregate ahead of core would then fatal
     * every cron cycle instead of failing a build.
     */
    public function testUncoveredAggregateIsReportedRatherThanSilentlySkipped(): void
    {
        $result = new \HSP\Core\Reconciliation\ReconciliationResult('full', 0, 0, [], false, ['ghost']);

        self::assertFalse($result->isComplete(), 'a pass with an uncovered type must not read as complete');
        self::assertSame(['ghost'], $result->uncovered);
    }

    // -------------------------------------------------------------------------
    // The consuming lists that remain core-owned (backfill counting)
    // -------------------------------------------------------------------------

    public function testBackfillReaderCountsEverySupportedAggregate(): void
    {
        $projection = $this->constantOf(BackfillReader::class, 'PROJECTION');

        foreach ($this->supportedTypes() as $type) {
            self::assertArrayHasKey(
                $type,
                $projection,
                "'{$type}' has no BackfillReader::PROJECTION entry, so its projected count is "
                . 'always 0 and backfill progress under-reports it.',
            );
        }
    }

    public function testBackfillProgressScoresEverySupportedAggregate(): void
    {
        $types = $this->constantOf(BackfillProgress::class, 'TYPES');

        foreach ($this->supportedTypes() as $type) {
            self::assertContains(
                $type,
                $types,
                "'{$type}' is missing from BackfillProgress::TYPES, so convergence ignores it "
                . 'and the site flips complete with none of it projected.',
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers — registries populated exactly as ContentServiceProvider::boot() does
    // -------------------------------------------------------------------------

    private function sourceRegistry(): ReconciliationSourceRegistryInterface
    {
        $registry = new \HSP\Core\Reconciliation\ReconciliationSourceRegistry();
        $registry->register(new StubContentSource($this->supportedTypes()));

        return $registry;
    }

    private function projectionRegistry(): ProjectionRegistryInterface
    {
        return ContentProjections::registry();
    }

    private function emitterRegistry(): ReplayEmitterRegistryInterface
    {
        $registry = new \HSP\Core\Replay\ReplayEmitterRegistry();
        $registry->register(new StubContentEmitter(
            $this->constantOf(ContentReplayEmitter::class, 'AGGREGATE_TYPES'),
        ));

        return $registry;
    }

    /** @return list<string> Aggregate types the Content module can reconcile. */
    private function supportedTypes(): array
    {
        /** @var list<string> $types */
        $types = $this->constantOf(WpReconciliationSource::class, 'AGGREGATE_TYPES');

        return $types;
    }

    /** @return array<array-key, mixed> */
    private function constantOf(string $class, string $name): array
    {
        $value = (new \ReflectionClass($class))->getConstant($name);

        self::assertIsArray($value, "{$class}::{$name} must exist and be an array");

        /** @var array<array-key, mixed> $value */
        return $value;
    }
}

/**
 * Stand-ins carrying only the real classes' declared aggregate types.
 *
 * The registries need instances, and constructing the real WpReconciliationSource /
 * ContentReplayEmitter would drag in the WordPress loader and the outbox writer for a test
 * whose only subject is which TYPES are declared.
 */
final class StubContentSource implements \HSP\Core\Contracts\WpReconciliationSourceInterface
{
    /** @param list<string> $types */
    public function __construct(private readonly array $types) {}

    public function getSupportedAggregateTypes(): array { return $this->types; }
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array { return []; }
    public function getSourceState(string $aggregateType, string $aggregateId): \HSP\Core\Contracts\SourceState
    {
        return new \HSP\Core\Contracts\SourceState(false, false, null);
    }
    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string { return null; }
    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool { return false; }
}

final class StubContentEmitter implements \HSP\Core\Contracts\ReplayEmitterInterface
{
    /** @param list<string> $types */
    public function __construct(private readonly array $types) {}

    public function getSupportedAggregateTypes(): array { return $this->types; }

    public function emitForAggregate(
        string $aggregateType,
        string $aggregateId,
        string $correlationId,
        string $causationId,
    ): \HSP\Core\Contracts\EventInterface {
        throw new \LogicException('not exercised');
    }
}
