<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox;

use HSP\Core\Contracts\AggregateVersionCounterInterface;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * Per-aggregate monotonic version counter backed by wp_hsp_aggregate_counters.
 *
 * DECISION 2 v1.1: single SQL round-trip guarantees no duplicate versions under
 * concurrent saves. Application-layer read-modify-write is prohibited.
 *
 * Atomic SQL pattern:
 *   INSERT INTO `{prefix}hsp_aggregate_counters` (aggregate_type, aggregate_id, version)
 *   VALUES (?, ?, 1)
 *   ON DUPLICATE KEY UPDATE version = LAST_INSERT_ID(version + 1);
 *   SELECT LAST_INSERT_ID();
 */
final class AggregateVersionCounter implements AggregateVersionCounterInterface
{
    public function __construct(private readonly object $wpdb) {}

    public function next(string $aggregateType, string $aggregateId): int
    {
        $table = $this->wpdb->prefix . 'hsp_aggregate_counters';

        $sql = $this->wpdb->prepare(
            "INSERT INTO `{$table}` (`aggregate_type`, `aggregate_id`, `version`)
             VALUES (%s, %s, LAST_INSERT_ID(1))
             ON DUPLICATE KEY UPDATE `version` = LAST_INSERT_ID(`version` + 1)",
            $aggregateType,
            $aggregateId,
        );

        $result = $this->wpdb->query($sql);

        if ($result === false) {
            throw new OutboxWriteException(
                "aggregate_version counter increment failed: {$this->wpdb->last_error}"
            );
        }

        $version = $this->wpdb->get_var('SELECT LAST_INSERT_ID()');

        if ($version === null) {
            throw new OutboxWriteException(
                'aggregate_version counter returned NULL from LAST_INSERT_ID()'
            );
        }

        return (int) $version;
    }
}
