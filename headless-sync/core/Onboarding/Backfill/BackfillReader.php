<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Backfill;

use HSP\Core\Database\DatabaseConnectionInterface;

/**
 * Read-only PostgreSQL reads for the ONB-S2 backfill gate + derived progress
 * (DECISION W (b)/(c)/(d)/(e); DECISION P; DECISION Q; DECISION K/L Ruling 0/E).
 *
 * Onboarding opens NO PostgreSQL handle of its own (DECISION W (e)). Every read here reuses the
 * EXISTING delivery DatabaseConnectionInterface (DECISION K) — no fifth handle (DECISION L Ruling
 * 0), no new pg_* wrapper (DECISION E). The handle is resolved LAZILY through an injected resolver
 * (identical rationale to {@see \HSP\Core\Onboarding\OnboardingConnectionProbe}): the delivery
 * provider opens libpq eagerly and THROWS when PG is unreachable, so a pre-resolved handle would
 * fire that throw during container resolution → HTTP 500 on the onboarding endpoints. Resolving on
 * demand makes a connect failure a caught, reportable "blocked" condition instead.
 *
 * Every method is a SELECT; nothing here writes (the backfill repair path is re-emission only —
 * DECISION W (b)). Reads that touch the DB translate connection/DML failure to a safe fallback so
 * the gate/progress endpoints never 500 when PG is momentarily unreachable.
 *
 * @phpstan-type HandleResolver callable(): DatabaseConnectionInterface
 */
final class BackfillReader
{
    /**
     * Live projection table per in-scope aggregate type, plus the taxonomy discriminator where
     * the table is SHARED.
     *
     * Categories and tags live in one content.taxonomies table (DECISION AA), so counting the
     * table reports "categories" as categories + tags. That inflated the projected count against
     * a WordPress-side expected count of categories alone, which could declare the backfill
     * converged while categories were still missing.
     */
    private const PROJECTION = [
        'page'     => ['table' => 'content.pages',      'type' => null],
        'post'     => ['table' => 'content.posts',      'type' => null],
        'category' => ['table' => 'content.taxonomies', 'type' => 'category'],
    ];

    /** @var callable(): DatabaseConnectionInterface */
    private $resolveConnection;

    /**
     * @param callable(): DatabaseConnectionInterface $resolveConnection resolves the EXISTING
     *        delivery handle on demand (may throw if the underlying link cannot be opened).
     */
    public function __construct(callable $resolveConnection)
    {
        $this->resolveConnection = $resolveConnection;
    }

    /**
     * Age in seconds of the freshest worker heartbeat, or null when no heartbeat row exists at all
     * (no worker has ever ticked) or the DB is unreachable. Read from the single current-state
     * system.worker_heartbeats table (DECISION P — upsert per tick, no history), against the DB
     * clock so it is immune to PHP/DB clock skew — the same read path WorkerStatusProvider uses.
     */
    public function freshestHeartbeatAgeSeconds(): ?float
    {
        try {
            $rows = $this->connection()->query(
                'SELECT EXTRACT(EPOCH FROM (NOW() - MAX(last_heartbeat_at))) AS age
                 FROM   system.worker_heartbeats'
            );
        } catch (\Throwable) {
            return null;
        }

        $age = $rows[0]['age'] ?? null;

        return $age === null ? null : (float) $age;
    }

    /**
     * Live projection row counts (deleted_at IS NULL) per in-scope aggregate type, derived on
     * demand (DECISION Q — zero new persistence). Missing types default to 0. Returns all-zero on
     * an unreachable DB so progress reads as "nothing projected yet" rather than throwing.
     *
     * @return array<string,int> aggregate type → live projection row count
     */
    public function liveProjectionCounts(): array
    {
        $counts = [];
        foreach (self::PROJECTION as $type => $meta) {
            $counts[$type] = $this->countLive($meta['table'], $meta['type']);
        }

        return $counts;
    }

    /**
     * Number of aggregates whose latest captured/relayed event is not yet projected — the
     * point-in-time in-flight signal (DECISION U D4 clause 2; mirrors
     * OperationsQueryReader::unprocessedAggregateCount()). Convergence must NOT be declared while
     * this is non-zero (guards against flipping complete mid-flight — DECISION U D4 semantics).
     * Returns null when the DB is unreachable (treated as "cannot confirm converged").
     */
    public function inFlightAggregateCount(): ?int
    {
        try {
            $rows = $this->connection()->query(
                'SELECT COUNT(*) AS c
                 FROM (
                     SELECT e.aggregate_type, e.aggregate_id
                     FROM   system.events e
                     LEFT JOIN system.aggregate_versions av
                            ON av.aggregate_type = e.aggregate_type
                           AND av.aggregate_id   = e.aggregate_id
                     GROUP BY e.aggregate_type, e.aggregate_id
                     HAVING MAX(e.aggregate_version) > COALESCE(MAX(av.latest_processed_version), 0)
                 ) AS behind'
            );
        } catch (\Throwable) {
            return null;
        }

        return (int) ($rows[0]['c'] ?? 0);
    }

    private function countLive(string $table, ?string $taxonomyType): int
    {
        // Fixed literals from PROJECTION above — never user input.
        $scope = $taxonomyType === null ? '' : " AND taxonomy_type = '{$taxonomyType}'";

        try {
            $rows = $this->connection()->query(
                "SELECT COUNT(*) AS c FROM {$table} WHERE deleted_at IS NULL{$scope}"
            );
        } catch (\Throwable) {
            return 0;
        }

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * Resolve the delivery handle on demand. May throw (e.g. libpq connect failure) — callers wrap
     * this so a failure becomes a gate/progress fallback, never an uncaught error.
     */
    private function connection(): DatabaseConnectionInterface
    {
        return ($this->resolveConnection)();
    }
}
