<?php

declare(strict_types=1);

namespace HSP\Core\Cli;

/**
 * WP-CLI registration shim for `hsp replay …` (DECISION T).
 *
 * This is the WordPress boundary — the only replay class that references \WP_CLI. It is
 * instantiated only inside the WP-CLI runtime (guarded by `defined('WP_CLI')` in
 * headless-sync.php) and delegates all logic to ReplayCommand. Keeping the WP-CLI coupling
 * in one thin shim keeps the replay mechanism testable without a WP-CLI runtime.
 *
 * Subcommands:
 *   wp hsp replay entity <aggregate_type> <aggregate_id>   — reproject one aggregate
 *   wp hsp replay range <from> <to>                        — reproject a [from, to) window
 */
final class WpCliReplayRegistrar
{
    public function __construct(
        private readonly ReplayCommand $replay,
    ) {}

    /** Register `hsp replay` with WP-CLI. No-op if WP-CLI is not present. */
    public function register(): void
    {
        if (! class_exists('\WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('hsp replay', [$this, 'dispatch']);
    }

    /**
     * `wp hsp replay <entity|range> …`
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function dispatch(array $args, array $assoc): void
    {
        $sub = $args[0] ?? '';

        try {
            switch ($sub) {
                case 'entity':
                    $type = $args[1] ?? '';
                    $id   = $args[2] ?? '';
                    if ($type === '' || $id === '') {
                        \WP_CLI::error('Usage: wp hsp replay entity <aggregate_type> <aggregate_id>');
                        return;
                    }
                    $result = $this->replay->entity($type, $id);
                    break;

                case 'range':
                    $from = $args[1] ?? '';
                    $to   = $args[2] ?? '';
                    if ($from === '' || $to === '') {
                        \WP_CLI::error('Usage: wp hsp replay range <from> <to>');
                        return;
                    }
                    $result = $this->replay->range($from, $to);
                    break;

                default:
                    \WP_CLI::error('Usage: wp hsp replay <entity|range> …');
                    return;
            }
        } catch (\InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }

        if ($result->count() === 0) {
            \WP_CLI::success(
                "Replay ({$sub}) emitted 0 synthetic events (no matching aggregates). "
                . "correlation_id={$result->correlationId}"
            );
            return;
        }

        \WP_CLI\Utils\format_items(
            'table',
            $result->emitted,
            ['aggregate_type', 'aggregate_id', 'event_type', 'event_id', 'aggregate_version'],
        );

        \WP_CLI::success(
            "Replay ({$sub}) emitted {$result->count()} synthetic event(s) through the outbox. "
            . "correlation_id={$result->correlationId} causation_id={$result->causationId}"
        );
    }
}
