<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding\Backfill;

use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Tests\Unit\Reconciliation\FakeReconciliationSource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ONB-S2 derived-on-demand progress + convergence rule (DECISION W (d);
 * DECISION Q; DECISION U D4 in-flight semantics).
 *
 * Expected counts come from the existing WpReconciliationSourceInterface (a scripted fake); live
 * projection + in-flight counts come from a read-only scripted connection. Convergence requires ALL
 * expected content projected AND zero in-flight — never flip complete mid-flight. Zero new
 * persistence: every count is computed at read time (the scripted connection throws on any write).
 */
final class BackfillProgressTest extends TestCase
{
    public function test_snapshot_reports_expected_vs_projected_and_percent(): void
    {
        $progress = $this->progress(
            expected: ['post' => 4, 'page' => 2, 'category' => 0],
            projected: ['content.posts' => 2, 'content.pages' => 1, 'content.taxonomies' => 0],
            inFlight: 3,
        );

        $snap = $progress->snapshot();

        self::assertSame(6, $snap['expected_total']);   // 4 + 2 + 0
        self::assertSame(3, $snap['projected_total']);  // 2 + 1 + 0
        self::assertSame(3, $snap['in_flight']);
        self::assertFalse($snap['converged']);
        self::assertSame(50, $snap['percent']);         // 3/6, clamped to 99 max (here 50)
    }

    public function test_converges_when_all_projected_and_zero_in_flight(): void
    {
        $progress = $this->progress(
            expected: ['post' => 3, 'page' => 1, 'category' => 2],
            projected: ['content.posts' => 3, 'content.pages' => 1, 'content.taxonomies' => 2],
            inFlight: 0,
        );

        $snap = $progress->snapshot();

        self::assertTrue($snap['converged']);
        self::assertSame(100, $snap['percent']);
        self::assertTrue($progress->isConverged());
    }

    public function test_does_not_converge_while_events_are_in_flight(): void
    {
        // Projections already cover expected, but in-flight > 0 → NOT converged (D4 guard).
        $progress = $this->progress(
            expected: ['post' => 2, 'page' => 0, 'category' => 0],
            projected: ['content.posts' => 2, 'content.pages' => 0, 'content.taxonomies' => 0],
            inFlight: 1,
        );

        $snap = $progress->snapshot();

        self::assertFalse($snap['converged']);
        self::assertLessThan(100, $snap['percent']);
    }

    public function test_empty_site_converges_once_in_flight_is_zero(): void
    {
        $progress = $this->progress(
            expected: ['post' => 0, 'page' => 0, 'category' => 0],
            projected: ['content.posts' => 0, 'content.pages' => 0, 'content.taxonomies' => 0],
            inFlight: 0,
        );

        $snap = $progress->snapshot();

        self::assertSame(0, $snap['expected_total']);
        self::assertTrue($snap['converged']);
    }

    public function test_unreachable_db_reports_in_flight_minus_one_and_never_converges(): void
    {
        // The connection throws on connect → freshest/counts fall back, in-flight is null → -1.
        $reader = new BackfillReader(static function (): never {
            throw new \RuntimeException('PG unreachable');
        });
        $source = new FakeReconciliationSource();
        $source->addLive('post', '1', true, new \DateTimeImmutable('now'));

        $progress = new BackfillProgress($source, $reader, 500);
        $snap     = $progress->snapshot();

        self::assertSame(-1, $snap['in_flight']);
        self::assertFalse($snap['converged']);
        // Expected still counted from WP source (source of truth) even when PG is down.
        self::assertSame(1, $snap['expected_total']);
        self::assertSame(0, $snap['projected_total']);
    }

    /**
     * Categories and tags share content.taxonomies (DECISION AA), while the WordPress-side
     * expected count is categories alone. Counting the whole table therefore inflated the
     * projected side by every tag — enough to declare the backfill converged while categories
     * were still missing.
     */
    public function test_taxonomy_projection_count_is_scoped_to_categories(): void
    {
        $conn = new ScriptedConnection();
        $conn->on('behind', [['c' => 0]]);
        $conn->on("taxonomy_type = 'category'", [['c' => 2]]);

        $reader = new BackfillReader(fn (): ScriptedConnection => $conn);

        self::assertSame(2, $reader->liveProjectionCounts()['category']);

        $taxonomyQueries = array_values(array_filter(
            $conn->queries,
            static fn (string $sql): bool => str_contains($sql, 'content.taxonomies')
        ));

        self::assertCount(1, $taxonomyQueries);
        self::assertStringContainsString("taxonomy_type = 'category'", $taxonomyQueries[0]);
    }

    // --- helpers ------------------------------------------------------------

    /**
     * @param array<string,int> $expected  aggregate type → expected count (scripts the WP source)
     * @param array<string,int> $projected content.* table → live row count
     */
    private function progress(array $expected, array $projected, int $inFlight): BackfillProgress
    {
        $source = new FakeReconciliationSource();
        foreach ($expected as $type => $count) {
            for ($i = 1; $i <= $count; $i++) {
                // Distinct ascending numeric ids per type so listAggregateIds paging terminates.
                $source->addLive($type, (string) $this->idFor($type, $i), true, new \DateTimeImmutable('now'));
            }
        }

        $conn = new ScriptedConnection();
        foreach ($projected as $table => $count) {
            $conn->on("FROM {$table}", [['c' => $count]]);
        }
        $conn->on('behind', [['c' => $inFlight]]);

        $reader = new BackfillReader(fn (): ScriptedConnection => $conn);

        return new BackfillProgress($source, $reader, 500);
    }

    private function idFor(string $type, int $i): int
    {
        // Distinct numeric id ranges per type so max(afterId) paging is monotonic.
        $base = match ($type) {
            'post'     => 1000,
            'page'     => 2000,
            'category' => 3000,
            default    => 9000,
        };

        return $base + $i;
    }
}
