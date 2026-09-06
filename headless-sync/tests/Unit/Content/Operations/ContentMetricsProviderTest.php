<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Content\Operations;

use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Contracts\Operations\MetricsProviderInterface;
use HSP\Modules\Content\Operations\ContentMetricsProvider;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

final class ContentMetricsProviderTest extends TestCase
{
    public function test_implements_core_metrics_contract_and_key(): void
    {
        $provider = new ContentMetricsProvider(new ScriptedReaderConnection());

        self::assertInstanceOf(MetricsProviderInterface::class, $provider);
        self::assertSame('content.metrics', $provider->key());
    }

    public function test_counts_live_projection_rows_excluding_tombstones(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('FROM content.pages WHERE deleted_at IS NULL', [['c' => '12']])
            ->on('FROM content.posts WHERE deleted_at IS NULL', [['c' => '34']])
            ->on("taxonomy_type = 'category'", [['c' => '5']])
            ->on("taxonomy_type = 'post_tag'", [['c' => '7']]);

        $samples = (new ContentMetricsProvider($conn))->samples();
        $by = [];
        foreach ($samples as $s) {
            self::assertInstanceOf(MetricSample::class, $s);
            $by[$s->name] = $s->value;
        }

        self::assertSame(12, $by['content_pages']);
        self::assertSame(34, $by['content_posts']);
        self::assertSame(5, $by['content_categories']);
        self::assertSame(7, $by['content_tags']);
    }

    /**
     * Categories and tags share content.taxonomies (DECISION AA), so a count of the TABLE is not
     * a count of either taxonomy. Before FLAG-TAXSCHEMA-1 was settled this provider counted the
     * table and published the result as `content_categories`.
     */
    public function test_taxonomy_counts_are_scoped_to_one_taxonomy_type(): void
    {
        $conn = (new ScriptedReaderConnection())->on('deleted_at IS NULL', [['c' => '0']]);

        (new ContentMetricsProvider($conn))->samples();

        $taxonomyQueries = array_values(array_filter(
            $conn->queries,
            static fn (string $sql): bool => str_contains($sql, 'content.taxonomies')
        ));

        self::assertCount(2, $taxonomyQueries, 'one count per taxonomy, not one for the table');
        foreach ($taxonomyQueries as $sql) {
            self::assertMatchesRegularExpression('/taxonomy_type\s*=/', $sql, "unscoped: {$sql}");
        }
    }

    public function test_provider_is_read_only(): void
    {
        $conn = (new ScriptedReaderConnection())->on('deleted_at IS NULL', [['c' => '0']]);

        (new ContentMetricsProvider($conn))->samples();

        self::assertSame(0, $conn->writeAttempts);
    }
}
