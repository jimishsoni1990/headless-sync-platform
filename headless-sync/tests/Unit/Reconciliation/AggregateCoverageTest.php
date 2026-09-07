<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Reconciliation;

use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Modules\Content\Reconciliation\WpReconciliationSource;
use HSP\Modules\Content\Replay\ContentReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * FLAG-RECON-COVERAGE-1 — the CONSUMING side of the aggregate seam.
 *
 * The module declares which aggregates it can reconcile and replay; core decides which of those
 * it actually projects. ReconciliationService::reconcile() `continue`s past any supported type
 * missing from its PROJECTION map — silently, with no error anywhere. The consequence is not a
 * crash but an absence: that type is never reconciled and, since the onboarding backfill IS
 * reconcileFull() (DECISION W (b)), never backfilled either — a fresh site is declared converged
 * with none of it projected. Media (P1B-S1) and tags (P1B-S3) sat in exactly that hole until
 * DECISION AC.
 *
 * Phase1BValidationTest guards the PRODUCING side of the same seam (source vs replay emitter);
 * this guards the three consuming lists. Reflection over private constants is deliberate: the
 * lists are internal, and the whole point is that a future aggregate cannot be added to one side
 * of the seam alone.
 */
final class AggregateCoverageTest extends TestCase
{
    public function testReconciliationServiceProjectsEverySupportedAggregate(): void
    {
        $projection = $this->constantOf(ReconciliationService::class, 'PROJECTION');

        foreach ($this->supportedTypes() as $type) {
            self::assertArrayHasKey(
                $type,
                $projection,
                "'{$type}' is reconcilable but has no ReconciliationService::PROJECTION entry, so "
                . 'every reconcile mode skips it and the onboarding backfill never emits it.',
            );
        }
    }

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

    /** The producing side, asserted in unit so the guard holds without a live database. */
    public function testReplayEmitterCoversEverySupportedAggregate(): void
    {
        self::assertEqualsCanonicalizing(
            $this->supportedTypes(),
            $this->constantOf(ContentReplayEmitter::class, 'AGGREGATE_TYPES'),
            'the replay emitter must be able to re-emit every reconcilable aggregate — '
            . 'reconciliation repairs EXCLUSIVELY by re-emission (DECISION T/U).',
        );
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
        /** @var array<array-key, mixed> $value */
        $value = (new \ReflectionClass($class))->getConstant($name);

        return $value;
    }
}
