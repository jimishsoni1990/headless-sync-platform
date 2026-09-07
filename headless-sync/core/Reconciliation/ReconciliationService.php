<?php

declare(strict_types=1);

namespace HSP\Core\Reconciliation;

use HSP\Core\Contracts\ProjectionRegistryInterface;
use HSP\Core\Contracts\ReconciliationSourceRegistryInterface;
use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Core\Replay\ReplayService;

/**
 * Reconciliation detector + orchestrator (DECISION U v1.19).
 *
 * Detects drift between WordPress (source of truth) and the content.* delivery projections
 * and repairs it EXCLUSIVELY by re-emission through the DECISION T primitive
 * (ReplayService::replayEntity). This service writes NO projections and never mutates
 * WordPress — WordPress-wins holds by construction (ADR-026/027/045, CLAUDE.md Rule 1).
 *
 * Three modes, one detector + a mode parameter (DECISION U D1):
 *   - drift       (hourly)  — WP→PG. Timestamp/existence comparison. Posts/pages: WP
 *                             post_modified_gmt vs projection updated_at + presence.
 *                             Categories: existence-only (terms have no modified ts — D2).
 *                             No checksum recompute.
 *   - incremental (nightly) — WP→PG over a recent window + checksum recompute (catches
 *                             silent field drift; only staleness signal for taxonomies).
 *   - full        (weekly)  — whole corpus + checksum + orphan sweep (PG→WP). Unbounded,
 *                             paged; page size config-driven. Missed-delete latency ≤ weekly.
 *
 * False-positive suppression (DECISION U D4): before repairing any WP→PG candidate, skip it
 * as IN-FLIGHT iff a pending unrelayed wp_hsp_outbox row exists (MySQL, via the source
 * contract) OR a system.events row exists with aggregate_version > latest_processed_version
 * (PG, read here). Only genuinely-uncaptured drift is repaired.
 *
 * Connections (DECISION L Ruling 0 — topology frozen at four): PG reads (content.*,
 * system.aggregate_versions, system.events) use the EXISTING delivery
 * DatabaseConnectionInterface handle. WordPress + pending-outbox reads go through
 * WpReconciliationSourceInterface. No fifth handle; no new pg_* wrapper.
 *
 * Module isolation (Rule 5): depends only on core-owned contracts (the source interface,
 * the delivery connection, and ReplayService). Never imports a module.
 */
final class ReconciliationService
{
    public const MODE_DRIFT       = 'drift';
    public const MODE_INCREMENTAL = 'incremental';
    public const MODE_FULL        = 'full';

    /**
     * Module reconciliation sources, keyed by aggregate type (DECISION AG AG-2).
     *
     * Was a single scalar WpReconciliationSourceInterface, which gave a second module's
     * source nowhere to go. Projection metadata — table, source-id column and the
     * discriminator for a SHARED table — now comes from the module-registered
     * ProjectionRegistry (AG-3) rather than a hardcoded `content.*` map in core.
     *
     * The discriminator still matters for exactly the reason DECISION AA gave: categories
     * and tags share content.taxonomies, and the orphan sweep lists rows BY TABLE with no id
     * to disambiguate them, so without it a 'category' pass claims every tag row.
     *
     * An aggregate type a source supports but no projection covers is reported in
     * ReconciliationResult::$uncovered — never skipped in silence (FLAG-RECON-COVERAGE-1).
     */
    private readonly ReconciliationSourceRegistryInterface $sources;

    /**
     * @param ReconciliationSourceRegistryInterface|WpReconciliationSourceInterface $sources
     *        The core-owned source registry (DECISION AG AG-2). A single source is still
     *        accepted and wrapped so hand-composed services — chiefly tests — keep working;
     *        production composition passes the registry, which is what lets a SECOND module's
     *        aggregates be reconciled at all.
     * @param ProjectionRegistryInterface $projections Module-registered projection descriptors
     *        (AG-3) — core no longer hardcodes `content.*`.
     */
    public function __construct(
        private readonly DatabaseConnectionInterface       $conn,
        ReconciliationSourceRegistryInterface|WpReconciliationSourceInterface $sources,
        private readonly ReplayService                     $replay,
        private readonly ProjectionRegistryInterface       $projections,
        private readonly int                               $pageSize = 500,
    ) {
        if ($sources instanceof ReconciliationSourceRegistryInterface) {
            $this->sources = $sources;
        } else {
            $registry = new ReconciliationSourceRegistry();
            $registry->register($sources);
            $this->sources = $registry;
        }
    }

    /**
     * Run a reconciliation pass.
     *
     * @param string $mode   One of MODE_DRIFT|MODE_INCREMENTAL|MODE_FULL.
     * @param bool   $dryRun When true, detect and report but do NOT re-emit (status surface).
     *
     * @throws \InvalidArgumentException on unknown mode.
     */
    public function reconcile(string $mode, bool $dryRun = false): ReconciliationResult
    {
        $useChecksum = match ($mode) {
            self::MODE_DRIFT                        => false,
            self::MODE_INCREMENTAL, self::MODE_FULL => true,
            default => throw new \InvalidArgumentException("Unknown reconciliation mode '{$mode}'."),
        };
        $orphanSweep = ($mode === self::MODE_FULL);

        $scanned    = 0;
        $suppressed = 0;
        $repaired   = [];
        $uncovered  = [];

        foreach ($this->sources->aggregateTypes() as $type) {
            if (! $this->projections->has($type)) {
                // A supported aggregate with no registered projection cannot be scanned.
                // Record it so the pass cannot report success over a platform it only
                // partially covered (DECISION AG AG-2) — the silent `continue` here is
                // exactly what hid media and tags until DECISION AC.
                $uncovered[] = $type;
                continue;
            }

            // WP → PG: missed create / update (and checksum drift in checksum modes).
            $afterId = 0;
            do {
                $ids = $this->sources->get($type)->listAggregateIds($type, $afterId, $this->pageSize);
                foreach ($ids as $id) {
                    $scanned++;
                    $afterId = max($afterId, (int) $id);

                    $decision = $this->classifyForward($type, $id, $useChecksum);
                    if ($decision === null) {
                        continue; // consistent — no drift
                    }

                    if ($this->isInFlight($type, $id)) {
                        $suppressed++;
                        continue;
                    }

                    $repaired[] = $this->record($type, $id, $decision, $dryRun);
                }
            } while ($ids !== []);

            // PG → WP orphan sweep (full mode only, DECISION U D3).
            if ($orphanSweep) {
                foreach ($this->findOrphans($type) as $id) {
                    $scanned++;

                    $state = $this->sources->get($type)->getSourceState($type, $id);
                    if ($state->exists && $state->public) {
                        continue; // not an orphan (covered by the forward pass)
                    }

                    if ($this->isInFlight($type, $id)) {
                        $suppressed++;
                        continue;
                    }

                    $repaired[] = $this->record($type, $id, 'orphan', $dryRun);
                }
            }
        }

        return new ReconciliationResult($mode, $scanned, $suppressed, $repaired, $dryRun, $uncovered);
    }

    // -------------------------------------------------------------------------
    // Detection
    // -------------------------------------------------------------------------

    /**
     * WP→PG classification for one live-WP aggregate. Returns a repair reason
     * ('missed_capture' | 'checksum_drift') or null when the projection is consistent.
     */
    private function classifyForward(string $type, string $id, bool $useChecksum): ?string
    {
        $state = $this->sources->get($type)->getSourceState($type, $id);

        // A non-public / absent WP entity is handled by the orphan sweep (full mode),
        // never by the forward pass — the forward pass only pushes public state to PG.
        if (! $state->exists || ! $state->public) {
            return null;
        }

        $row = $this->projectionRow($type, $id);

        // Missed create: WP public but no live projection row.
        if ($row === null || $row['deleted_at'] !== null) {
            return 'missed_capture';
        }

        // Timestamp comparison (pages/posts; categories have modifiedAt === null and skip this).
        if ($state->modifiedAt !== null) {
            $projUpdated = $this->parseTs($row['updated_at']);
            if ($projUpdated === null || $state->modifiedAt > $projUpdated) {
                return 'missed_capture';
            }
        }

        // Checksum recompute (incremental/full; only staleness signal for categories — D2).
        if ($useChecksum) {
            $current = $this->sources->get($type)->computeCurrentChecksum($type, $id);
            if ($current !== null && $current !== (string) $row['checksum']) {
                return 'checksum_drift';
            }
        }

        return null;
    }

    /**
     * IN-FLIGHT suppression (DECISION U D4): pending unrelayed outbox row (MySQL) OR a
     * captured-not-yet-processed system.events row (PG). Either means the pipeline will
     * project the change — not drift.
     */
    private function isInFlight(string $type, string $id): bool
    {
        if ($this->sources->get($type)->hasPendingOutbox($type, $id)) {
            return true;
        }

        $rows = $this->conn->query(
            'SELECT 1
             FROM   system.events e
             LEFT JOIN system.aggregate_versions av
                    ON av.aggregate_type = e.aggregate_type
                   AND av.aggregate_id   = e.aggregate_id
             WHERE  e.aggregate_type = $1
               AND  e.aggregate_id   = $2
               AND  e.aggregate_version > COALESCE(av.latest_processed_version, 0)
             LIMIT 1',
            [$type, $id],
        );

        return $rows !== [];
    }

    /**
     * @return array<int, string> Orphan candidate IDs: live projection rows (deleted_at IS NULL)
     *                            for this aggregate type. WP existence is checked per-id by caller.
     */
    private function findOrphans(string $type): array
    {
        $meta = $this->projections->get($type);

        $rows = $this->conn->query(
            "SELECT {$meta->sourceIdColumn} AS aggregate_id
             FROM   {$meta->table}
             WHERE  deleted_at IS NULL{$this->typeScope($type)}
             ORDER BY {$meta->sourceIdColumn}",
        );

        return array_map(static fn (array $r): string => (string) $r['aggregate_id'], $rows);
    }

    /**
     * @return array<string, mixed>|null The live/soft-deleted projection row, or null if absent.
     */
    private function projectionRow(string $type, string $id): ?array
    {
        $meta = $this->projections->get($type);

        $rows = $this->conn->query(
            "SELECT checksum, updated_at, deleted_at
             FROM   {$meta->table}
             WHERE  {$meta->sourceIdColumn} = $1{$this->typeScope($type)}",
            [$id],
        );

        return $rows[0] ?? null;
    }

    /**
     * The discriminator predicate for aggregate types whose projection table is SHARED, or ''
     * for the tables that are not (DECISION AA query rule: identify BOTH taxonomy type and term
     * identity).
     *
     * The column and value come from a ProjectionDescriptor, which validates both as strict
     * identifiers at registration and accepts them only from trusted module code — never from
     * request input (DECISION AG AG-3). They are interpolated because an identifier cannot be
     * bound as a parameter; the value is additionally single-quoted as a literal.
     */
    private function typeScope(string $type): string
    {
        $meta = $this->projections->get($type);

        if (! $meta->isDiscriminated()) {
            return '';
        }

        return " AND {$meta->discriminatorColumn} = '{$meta->discriminatorValue}'";
    }

    // -------------------------------------------------------------------------
    // Repair — DECISION T re-emission ONLY (no direct PG writes)
    // -------------------------------------------------------------------------

    /**
     * Record a detected divergence. In a live run this REPAIRS by re-emission through the
     * DECISION T primitive (ReplayService::replayEntity) — the only repair path; no direct PG
     * write. In a dry run it records the detection ONLY and performs no re-emission.
     *
     * @return array<string, mixed> One ReconciliationResult::$repaired row.
     */
    private function record(string $type, string $id, string $reason, bool $dryRun): array
    {
        if ($dryRun) {
            return [
                'aggregate_type' => $type,
                'aggregate_id'   => $id,
                'reason'         => $reason,
            ];
        }

        $result  = $this->replay->replayEntity($type, $id);
        $emitted = $result->emitted[0] ?? [];

        return [
            'aggregate_type'    => $type,
            'aggregate_id'      => $id,
            'reason'            => $reason,
            'event_type'        => $emitted['event_type']        ?? null,
            'event_id'          => $emitted['event_id']          ?? null,
            'aggregate_version' => $emitted['aggregate_version'] ?? null,
            'correlation_id'    => $result->correlationId,
        ];
    }

    private function parseTs(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
