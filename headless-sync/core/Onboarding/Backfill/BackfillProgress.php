<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Backfill;

use HSP\Core\Contracts\WpReconciliationSourceInterface;

/**
 * Derived-on-demand backfill progress (ONB-S2; DECISION W (d); DECISION Q).
 *
 * Progress is computed at read time — ZERO new PG persistence, no progress table, no rollups
 * (DECISION Q / DECISION W (d)). Two derivations, compared:
 *   - EXPECTED: the count of in-scope public WordPress aggregates per type, obtained by paging
 *     the EXISTING {@see WpReconciliationSourceInterface::listAggregateIds()} contract (Rule 5 —
 *     no module import, no new contract). This is the WordPress-side source of truth (Rule 1).
 *   - PROJECTED: the live content.* projection row counts (deleted_at IS NULL) via the delivery
 *     handle ({@see BackfillReader}).
 *
 * CONVERGENCE (the completion signal) is declared iff ALL hold:
 *   (a) every in-scope type's projected count >= its expected count (all expected content is
 *       present in delivery — WordPress-wins by construction, DECISION U point 3), AND
 *   (b) there is NO in-flight aggregate (system.events ahead of the processed watermark is 0 —
 *       DECISION U D4 clause 2). This guards against flipping complete while re-emitted events are
 *       still draining the outbox→relay→dispatch→worker pipeline (DECISION W (c)).
 * Expected==0 (an empty site) still converges once (b) holds — there is simply nothing to project.
 *
 * "projected >= expected" (not strict equality) is deliberate: the delivery side may legitimately
 * hold soft-deleted-then-revived rows or historical published aggregates the current expected scan
 * (public set only) does not enumerate; convergence only requires that all currently-expected
 * content is present, never that delivery is a subset. In-flight being zero is what proves the
 * backfill re-emissions have fully drained.
 */
final class BackfillProgress
{
    /** In-scope aggregate types (Blog MVP), fixed by the pipeline's projection targets. */
    private const TYPES = ['page', 'post', 'category'];

    public function __construct(
        private readonly WpReconciliationSourceInterface $source,
        private readonly BackfillReader $reader,
        private readonly int $pageSize = 500,
    ) {}

    /**
     * Compute the current progress snapshot.
     *
     * @return array{
     *     expected: array<string,int>,
     *     projected: array<string,int>,
     *     expected_total: int,
     *     projected_total: int,
     *     in_flight: int,
     *     converged: bool,
     *     percent: int
     * }
     */
    public function snapshot(): array
    {
        $expected  = $this->expectedCounts();
        $projected = $this->reader->liveProjectionCounts();
        $inFlight  = $this->reader->inFlightAggregateCount();

        $expectedTotal  = array_sum($expected);
        $projectedTotal = 0;
        foreach (self::TYPES as $type) {
            $projectedTotal += $projected[$type] ?? 0;
        }

        $converged = $this->isConvergedWith($expected, $projected, $inFlight);

        return [
            'expected'        => $expected,
            'projected'       => $this->onlyInScope($projected),
            'expected_total'  => $expectedTotal,
            'projected_total' => $projectedTotal,
            // null in-flight (DB unreachable) is surfaced as -1 so the client can tell it apart
            // from a genuine 0; convergence already treats null as "not converged".
            'in_flight'       => $inFlight ?? -1,
            'converged'       => $converged,
            'percent'         => $this->percent($expectedTotal, $projectedTotal, $converged),
        ];
    }

    /** True when the backfill has fully converged — the completion signal (DECISION W (d)). */
    public function isConverged(): bool
    {
        return $this->isConvergedWith(
            $this->expectedCounts(),
            $this->reader->liveProjectionCounts(),
            $this->reader->inFlightAggregateCount(),
        );
    }

    /**
     * Expected in-scope aggregate counts by type — public WordPress aggregates, paged through the
     * existing source contract. WordPress is the source of truth (Rule 1).
     *
     * @return array<string,int>
     */
    private function expectedCounts(): array
    {
        $supported = $this->source->getSupportedAggregateTypes();
        $counts    = [];

        foreach (self::TYPES as $type) {
            if (! in_array($type, $supported, true)) {
                $counts[$type] = 0;
                continue;
            }
            $counts[$type] = $this->countExpected($type);
        }

        return $counts;
    }

    /** Page listAggregateIds until exhausted, counting IDs. Bounded by the source's own paging. */
    private function countExpected(string $type): int
    {
        $count   = 0;
        $afterId = 0;

        do {
            $ids = $this->source->listAggregateIds($type, $afterId, $this->pageSize);
            foreach ($ids as $id) {
                $count++;
                $afterId = max($afterId, (int) $id);
            }
        } while ($ids !== []);

        return $count;
    }

    /**
     * @param array<string,int> $expected
     * @param array<string,int> $projected
     */
    private function isConvergedWith(array $expected, array $projected, ?int $inFlight): bool
    {
        // (b) in-flight must be confirmed zero (null = DB unreachable = cannot confirm converged).
        if ($inFlight === null || $inFlight > 0) {
            return false;
        }

        // (a) every in-scope type's projection must cover its expected count.
        foreach (self::TYPES as $type) {
            if (($projected[$type] ?? 0) < ($expected[$type] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,int> $counts @return array<string,int> */
    private function onlyInScope(array $counts): array
    {
        $out = [];
        foreach (self::TYPES as $type) {
            $out[$type] = $counts[$type] ?? 0;
        }

        return $out;
    }

    private function percent(int $expectedTotal, int $projectedTotal, bool $converged): int
    {
        if ($converged) {
            return 100;
        }
        if ($expectedTotal <= 0) {
            return 0;
        }

        $pct = (int) floor(($projectedTotal / $expectedTotal) * 100);

        // Never report 100 unless actually converged (in-flight may still be draining).
        return max(0, min(99, $pct));
    }
}
