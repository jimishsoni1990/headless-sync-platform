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
            ->on('FROM content.taxonomies WHERE deleted_at IS NULL', [['c' => '5']]);

        $samples = (new ContentMetricsProvider($conn))->samples();
        $by = [];
        foreach ($samples as $s) {
            self::assertInstanceOf(MetricSample::class, $s);
            $by[$s->name] = $s->value;
        }

        self::assertSame(12, $by['content_pages']);
        self::assertSame(34, $by['content_posts']);
        self::assertSame(5, $by['content_categories']);
    }

    public function test_provider_is_read_only(): void
    {
        $conn = (new ScriptedReaderConnection())->on('deleted_at IS NULL', [['c' => '0']]);

        (new ContentMetricsProvider($conn))->samples();

        self::assertSame(0, $conn->writeAttempts);
    }
}
