<?php

declare(strict_types=1);

namespace HSP\Core\Cli;

/**
 * WP-CLI registration shim for `hsp reconcile …` (DECISION U v1.19).
 *
 * The WordPress boundary — the only reconcile class that references \WP_CLI. Instantiated
 * only inside the WP-CLI runtime (guarded by `defined('WP_CLI')` in headless-sync.php) and
 * delegates all logic to ReconcileCommand. Keeps the reconciliation mechanism testable
 * without a WP-CLI runtime.
 *
 * Subcommands:
 *   wp hsp reconcile drift        [--dry-run]  — hourly-mode: timestamp/existence, WP→PG
 *   wp hsp reconcile incremental  [--dry-run]  — nightly-mode: window + checksum recompute
 *   wp hsp reconcile full         [--dry-run]  — weekly-mode: full corpus + checksum + orphans
 *   wp hsp reconcile status                    — alias for `drift --dry-run` (detect only)
 */
final class WpCliReconcileRegistrar
{
    public function __construct(
        private readonly ReconcileCommand $reconcile,
    ) {}

    /** Register `hsp reconcile` with WP-CLI. No-op if WP-CLI is not present. */
    public function register(): void
    {
        if (! class_exists('\WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('hsp reconcile', [$this, 'dispatch']);
    }

    /**
     * `wp hsp reconcile <drift|incremental|full|status> [--dry-run]`
     *
     * @param array<int,string>    $args
     * @param array<string,string> $assoc
     */
    public function dispatch(array $args, array $assoc): void
    {
        $sub    = $args[0] ?? '';
        $dryRun = array_key_exists('dry-run', $assoc);

        try {
            switch ($sub) {
                case 'drift':
                case 'incremental':
                case 'full':
                    $result = $this->reconcile->run($sub, $dryRun);
                    break;

                case 'status':
                    // Detection-only summary — never repairs.
                    $result = $this->reconcile->run('drift', true);
                    break;

                default:
                    \WP_CLI::error('Usage: wp hsp reconcile <drift|incremental|full|status> [--dry-run]');
                    return;
            }
        } catch (\InvalidArgumentException $e) {
            \WP_CLI::error($e->getMessage());
            return;
        }

        if ($result->repairedCount() > 0) {
            \WP_CLI\Utils\format_items(
                'table',
                $result->repaired,
                array_keys($result->repaired[0]),
            );
        }

        $verb = $result->dryRun ? 'detected' : 'repaired (re-emitted)';
        \WP_CLI::success(sprintf(
            'Reconcile (%s): scanned %d, suppressed %d in-flight, %s %d aggregate(s).',
            $result->mode,
            $result->scanned,
            $result->suppressed,
            $verb,
            $result->repairedCount(),
        ));
    }
}
