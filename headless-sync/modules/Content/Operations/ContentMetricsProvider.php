<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Operations;

use HSP\Core\Contracts\Operations\MetricSample;
use HSP\Core\Contracts\Operations\MetricsProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Module-provided derived metrics for the Content domain (Doc 12 §14; DECISION Q).
 *
 * Implements the core-owned MetricsProviderInterface (Rule 5). Exposes live counts of the
 * content projection rows (pages / posts / categories, excluding soft-deleted tombstones)
 * derived on demand — ZERO new persistence (DECISION V (c)). This is the "module-provided
 * metrics behind core/Contracts/Operations/" surface the OPSC-S2 scope calls for.
 *
 * DECISION V (g) / DECISION K: reads run on the delivery DatabaseConnectionInterface handle
 * injected here — the SAME handle the content query providers already use — so it opens no
 * fifth handle (DECISION L Ruling 0) and adds no new pg_* wrapper (DECISION E). Read-only.
 */
final class ContentMetricsProvider implements MetricsProviderInterface
{
    public const KEY = 'content.metrics';

    public function __construct(
        private readonly DatabaseConnectionInterface $conn,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    /** @return MetricSample[] */
    public function samples(): array
    {
        return [
            new MetricSample('content_pages', $this->liveCount('content.pages'), 'rows'),
            new MetricSample('content_posts', $this->liveCount('content.posts'), 'rows'),
            new MetricSample('content_categories', $this->liveCount('content.taxonomies'), 'rows'),
        ];
    }

    /**
     * Count non-tombstoned projection rows in a content table. Table name is a fixed literal
     * from the closed set above (never user input), so interpolation is safe here.
     */
    private function liveCount(string $table): int
    {
        $rows = $this->conn->query(
            "SELECT COUNT(*) AS c FROM {$table} WHERE deleted_at IS NULL"
        );

        return (int) ($rows[0]['c'] ?? 0);
    }
}
