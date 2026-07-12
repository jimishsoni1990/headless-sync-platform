<?php

declare(strict_types=1);

namespace HSP\Core\Cli;

use HSP\Core\Observability\OperationalMetricsQuery;
use HSP\Core\Queue\Exception\DeadLetterReplayException;

/**
 * WP-CLI registration shim for `hsp dlq …` and `hsp status` (DECISION S clause (d),
 * DECISION Q). This is the WordPress boundary — the only class that references \WP_CLI.
 *
 * It is instantiated only inside the WP-CLI runtime (guarded by `defined('WP_CLI')` in
 * headless-sync.php) and delegates all logic to DlqCommand / OperationalMetricsQuery.
 * Keeping the WP-CLI coupling in one thin shim keeps the replay lifecycle and metric
 * queries testable without a WP-CLI runtime.
 *
 * Subcommands:
 *   wp hsp dlq list [--limit=<n>]     — recent dead-letter rows
 *   wp hsp dlq inspect <id>           — full detail for one DLQ row
 *   wp hsp dlq replay <id>            — re-enqueue a dead-lettered event (DECISION S)
 *   wp hsp status                     — derived operational metrics (DECISION Q)
 */
final class WpCliDlqRegistrar
{
    public function __construct(
        private readonly DlqCommand $dlq,
        private readonly OperationalMetricsQuery $metrics,
    ) {}

    /**
     * Register the `hsp dlq` and `hsp status` commands with WP-CLI.
     * No-op if WP-CLI is not present.
     */
    public function register(): void
    {
        if (! class_exists('\WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('hsp dlq', [$this, 'dispatchDlq']);
        \WP_CLI::add_command('hsp status', [$this, 'dispatchStatus']);
    }

    /**
     * `wp hsp dlq <subcommand> [<id>] [--limit=<n>]`
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function dispatchDlq(array $args, array $assoc): void
    {
        $sub = $args[0] ?? '';

        switch ($sub) {
            case 'list':
                $limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 50;
                $rows  = $this->dlq->list($limit);

                if ($rows === []) {
                    \WP_CLI::log('No dead-letter rows.');
                    return;
                }

                \WP_CLI\Utils\format_items(
                    'table',
                    $rows,
                    ['id', 'event_id', 'failure_reason', 'attempt_count', 'created_at', 'replayed_at'],
                );
                return;

            case 'inspect':
                $id  = $args[1] ?? '';
                if ($id === '') {
                    \WP_CLI::error('Usage: wp hsp dlq inspect <id>');
                    return;
                }
                $row = $this->dlq->inspect($id);
                if ($row === null) {
                    \WP_CLI::error("DLQ row '{$id}' not found.");
                    return;
                }
                foreach ($row as $key => $value) {
                    \WP_CLI::log(sprintf('%-18s %s', $key . ':', is_scalar($value) ? (string) $value : json_encode($value)));
                }
                return;

            case 'replay':
                $id = $args[1] ?? '';
                if ($id === '') {
                    \WP_CLI::error('Usage: wp hsp dlq replay <id>');
                    return;
                }
                try {
                    $eventId = $this->dlq->replay($id);
                    \WP_CLI::success("Replayed DLQ row {$id}; event {$eventId} re-enqueued (attempts=0).");
                } catch (DeadLetterReplayException $e) {
                    \WP_CLI::error($e->getMessage());
                }
                return;

            default:
                \WP_CLI::error('Usage: wp hsp dlq <list|inspect|replay> [<id>] [--limit=<n>]');
        }
    }

    /**
     * `wp hsp status` — derived operational metrics (DECISION Q).
     */
    public function dispatchStatus(): void
    {
        $snapshot = $this->metrics->snapshot();

        \WP_CLI::log('HSP operational status (derived on demand — DECISION Q):');
        \WP_CLI::log('  queue depth (total):      ' . $snapshot['queue_depth_total']);
        foreach ($snapshot['queue_depth_by_partition'] as $partition => $depth) {
            \WP_CLI::log("    - {$partition}: {$depth}");
        }
        \WP_CLI::log('  DLQ depth:                ' . $snapshot['dlq_depth']);
        \WP_CLI::log('  oldest pending age (s):   ' . ($snapshot['oldest_pending_age_seconds'] ?? 'n/a'));
        \WP_CLI::log('  worker count:             ' . $snapshot['worker_count']);
    }
}
