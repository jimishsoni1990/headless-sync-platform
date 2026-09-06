<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Operations;

use HSP\Core\Database\PostgresDatabaseConnection;
use HSP\Core\Operations\Diagnostics\ModuleInspector;
use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Diagnostics\SystemInformationProvider;
use HSP\Core\Operations\Providers\HealthProvider;
use HSP\Core\Operations\Providers\MetricsProvider;
use HSP\Core\Operations\Providers\QueueStatusProvider;
use HSP\Core\Operations\Providers\WorkerStatusProvider;
use HSP\Modules\Content\Operations\ContentMetricsProvider;
use HSP\Tests\Integration\Reconciliation\WriteSpyConnection;
use HSP\Tests\Support\ContentSchema;
use HSP\Tests\Unit\Operations\Fakes\FakeModuleInspectionProvider;
use PHPUnit\Framework\TestCase;

/**
 * OPSC-S2 — live-PostgreSQL integration proofs (DoD).
 *
 * Proves against a real database:
 *   - The console providers return LIVE queue depth, DLQ depth, worker count, and
 *     oldest-pending age from the EXISTING system.* / content.* tables.
 *   - Worker-offline is a heartbeat-age query (DECISION P): a stale row reads offline, a
 *     fresh row reads online, from the same current-state table.
 *   - Derived point-in-time status (processing rate, replay counts, reconciliation backlog)
 *     is computed at read time with ZERO persistence.
 *   - System Information reads module_versions / schema_versions (OPEN-8).
 *   - Provider reads execute on the delivery DatabaseConnectionInterface handle (DECISION V
 *     (g)) and the four-handle topology (DECISION L Ruling 0) is unchanged: relay / queue /
 *     delivery / dispatcher are four DISTINCT backend PIDs and NO fifth PID is introduced.
 *   - Providers are READ-ONLY: a write-spy over the delivery handle records ZERO execute /
 *     beginTransaction / commit across every provider call.
 *
 * Environment (self-skips if PG absent):
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class OperationsProvidersIntegrationTest extends TestCase
{
    private const OFFLINE_AFTER_SECONDS = 60;

    private mixed $pgConn = null;
    private PostgresDatabaseConnection $db;

    protected function setUp(): void
    {
        $this->pgConn = $this->connectPgsql();
        $this->db     = new PostgresDatabaseConnection($this->pgConn);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
            pg_close($this->pgConn);
            $this->pgConn = null;
        }
    }

    // =========================================================================
    // Live queue / DLQ / oldest-pending from existing tables
    // =========================================================================

    public function test_queue_status_provider_reports_live_depths_and_oldest_pending(): void
    {
        // Three available (one older), one claimed (excluded), two DLQ rows.
        $this->enqueue('available', ageSeconds: 120);
        $this->enqueue('available', ageSeconds: 30);
        $this->enqueue('available', ageSeconds: 5);
        $this->enqueue('claimed', ageSeconds: 10);
        $this->insertDlq(replayed: false);
        $this->insertDlq(replayed: true);

        $status = (new QueueStatusProvider(new OperationsQueryReader($this->db)))->status();

        self::assertSame(3, $status->depth, 'only available jobs count toward depth');
        self::assertSame(2, $status->deadLetterDepth, 'DLQ rows are permanent (both counted)');
        self::assertNotNull($status->oldestPendingAge);
        // Oldest available is ~120s old; allow generous slack for clock/runtime.
        $ageSeconds = $this->intervalToSeconds($status->oldestPendingAge);
        self::assertGreaterThanOrEqual(90, $ageSeconds, 'oldest-pending age reflects the ~120s-old job');
    }

    // =========================================================================
    // Worker-offline is a heartbeat-age query (DECISION P)
    // =========================================================================

    public function test_worker_status_provider_derives_online_offline_by_heartbeat_age(): void
    {
        $freshId = $this->insertHeartbeat('event', 'idle', ageSeconds: 2);
        $staleId = $this->insertHeartbeat('maintenance', 'running', ageSeconds: 3600);

        $statuses = (new WorkerStatusProvider(new OperationsQueryReader($this->db), self::OFFLINE_AFTER_SECONDS))
            ->statuses();

        $byId = [];
        foreach ($statuses as $s) {
            $byId[$s->workerId] = $s;
        }

        self::assertCount(2, $statuses, 'both current-state heartbeat rows are visible');
        self::assertTrue($byId[$freshId]->online, 'fresh heartbeat → online');
        self::assertFalse($byId[$staleId]->online, 'heartbeat older than threshold → offline');
        self::assertInstanceOf(\DateTimeImmutable::class, $byId[$freshId]->lastHeartbeatAt);
    }

    // =========================================================================
    // Derived point-in-time metrics — zero persistence
    // =========================================================================

    public function test_metrics_provider_derives_counts_and_status_point_in_time(): void
    {
        $this->enqueue('available', ageSeconds: 10);
        $this->enqueue('completed', ageSeconds: 5);   // within the rate window
        $this->insertHeartbeat('event', 'idle', ageSeconds: 1);
        $this->insertDlq(replayed: false);
        $this->insertDlq(replayed: true);
        // An aggregate captured/relayed (v2) but only processed up to v1 → backlog = 1.
        $this->seedEvent('post', 'agg-behind', 2);
        $this->seedAggregateVersion('post', 'agg-behind', 1);

        $samples = (new MetricsProvider(new OperationsQueryReader($this->db), 3600))->samples();
        $by = [];
        foreach ($samples as $s) {
            $by[$s->name] = $s->value;
        }

        self::assertSame(1, $by['queue_depth']);
        self::assertSame(2, $by['dlq_depth']);
        self::assertSame(1, $by['worker_count']);
        self::assertSame(1, $by['replay_pending']);
        self::assertSame(1, $by['replay_completed']);
        self::assertSame(1, $by['reconciliation_backlog'], 'aggregate behind its latest event counts as backlog');
        self::assertGreaterThan(0.0, $by['processing_rate'], 'a job completed in-window → positive rate');
        // ADR-054 §17/§27 cycle metrics derived from the per-cycle heartbeat rows (one 'event'
        // heartbeat was inserted at age 1s → one cycle in the 3600s window).
        self::assertSame(1, $by['cycles_completed'], 'one recent cycle heartbeat → one cycle');
        self::assertArrayHasKey('avg_cycle_duration', $by, 'a recent cycle → an average duration');
        self::assertArrayHasKey('per_stage_throughput.event', $by, 'per-stage throughput for the event stage');
        self::assertArrayNotHasKey('worker_uptime', $by, 'daemon metrics are gone (ADR-054 §6)');
        self::assertArrayNotHasKey('restart_count', $by);
    }

    // =========================================================================
    // Health rollup from live state
    // =========================================================================

    public function test_health_provider_rolls_up_live_state(): void
    {
        $this->insertHeartbeat('event', 'idle', ageSeconds: 2);
        $this->insertDlq(replayed: false);

        $reports = (new HealthProvider(new OperationsQueryReader($this->db), self::OFFLINE_AFTER_SECONDS))->reports();
        $by = [];
        foreach ($reports as $r) {
            $by[$r->component] = $r;
        }

        self::assertSame(\HSP\Core\Contracts\Operations\Severity::OK, $by['database']->severity);
        // Cycle-freshness (ADR-054 §5): a recent cycle heartbeat with an empty queue → processing OK.
        self::assertSame(\HSP\Core\Contracts\Operations\Severity::OK, $by['processing']->severity);
        self::assertStringNotContainsStringIgnoringCase('offline', $by['processing']->summary);
        // One DLQ row → warning.
        self::assertSame(\HSP\Core\Contracts\Operations\Severity::WARNING, $by['dead_letter_queue']->severity);
    }

    // =========================================================================
    // System Information reads module_versions / schema_versions (OPEN-8)
    // =========================================================================

    public function test_system_information_reads_open8_version_tables(): void
    {
        $this->insertModuleVersion('content', '1.0.0');
        $this->insertSchemaVersion('0012_create_system_worker_heartbeats');
        $this->insertSchemaVersion('0013_add_replayed_at_to_dead_letter_jobs');

        $info = (new SystemInformationProvider(
            new OperationsQueryReader($this->db),
            '0.1.0',
            null,
            'database',
        ))->snapshot();

        self::assertSame('0.1.0', $info->platformVersion);
        self::assertSame(PHP_VERSION, $info->phpVersion);
        self::assertNotSame('unknown', $info->postgresVersion, 'real PG version reported via SHOW server_version');
        self::assertSame(['content' => '1.0.0'], $info->moduleVersions);
        self::assertSame(2, $info->appliedMigrationCount);
        self::assertSame('0013_add_replayed_at_to_dead_letter_jobs', $info->latestMigration);
    }

    // =========================================================================
    // Content module metrics via the delivery handle (Rule 5, module-provided)
    // =========================================================================

    public function test_content_metrics_provider_counts_live_projection_rows(): void
    {
        $this->insertContentPage(deleted: false);
        $this->insertContentPage(deleted: false);
        $this->insertContentPage(deleted: true); // tombstone excluded

        // Categories and tags share content.taxonomies (DECISION AA), so the two counts must be
        // told apart by taxonomy_type — a count of the TABLE would report 3 categories here.
        $this->insertTaxonomyTerm('category', 'news', deleted: false);
        $this->insertTaxonomyTerm('post_tag', 'news', deleted: false); // same slug, other taxonomy
        $this->insertTaxonomyTerm('post_tag', 'php', deleted: false);
        $this->insertTaxonomyTerm('category', 'gone', deleted: true);  // tombstone excluded

        $samples = (new ContentMetricsProvider($this->db))->samples();
        $by = [];
        foreach ($samples as $s) {
            $by[$s->name] = $s->value;
        }

        self::assertSame(2, $by['content_pages'], 'soft-deleted rows are excluded');
        self::assertSame(0, $by['content_posts']);
        self::assertSame(1, $by['content_categories'], 'tags must not be counted as categories');
        self::assertSame(2, $by['content_tags']);
    }

    // =========================================================================
    // Module Inspector aggregates descriptors (no query on the inspection path)
    // =========================================================================

    public function test_module_inspector_aggregates_descriptors(): void
    {
        $inspector = new ModuleInspector([new FakeModuleInspectionProvider('content', '1.0.0')]);

        $all = $inspector->all();
        self::assertCount(1, $all);
        self::assertSame('content', $all[0]->name);
    }

    // =========================================================================
    // Four-handle topology unchanged — provider reads on the delivery PID; no 5th
    // =========================================================================

    public function test_provider_reads_on_delivery_handle_and_topology_stays_four_distinct_pids(): void
    {
        $dsn = $this->dsn();

        // The four ratified runtime handles (DECISION L Ruling 0), each FORCE_NEW.
        $relayRaw      = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $queueRaw      = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $deliveryRaw   = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);
        $dispatcherRaw = \pg_connect($dsn, PGSQL_CONNECT_FORCE_NEW);

        if ($relayRaw === false || $queueRaw === false || $deliveryRaw === false || $dispatcherRaw === false) {
            self::markTestSkipped('Could not open the four topology handles.');
        }

        try {
            $relayPid      = $this->backendPid($relayRaw);
            $queuePid      = $this->backendPid($queueRaw);
            $deliveryPid   = $this->backendPid($deliveryRaw);
            $dispatcherPid = $this->backendPid($dispatcherRaw);

            // Four physically distinct backends — the frozen topology.
            $pids = [$relayPid, $queuePid, $deliveryPid, $dispatcherPid];
            self::assertCount(4, array_unique($pids), 'relay/queue/delivery/dispatcher are four distinct PIDs');

            // The console reader runs on the DELIVERY handle — its reads execute on the
            // delivery backend PID, opening NO fifth connection.
            $deliveryConn = new PostgresDatabaseConnection($deliveryRaw);
            $reader = new OperationsQueryReader($deliveryConn);

            // Exercise the reader (these are the calls the providers make)…
            $reader->queueDepth();
            $reader->deadLetterDepth();
            $reader->workerHeartbeats();
            $reader->oldestPendingAgeSeconds();

            // …then confirm the delivery backend PID is unchanged (same physical link, no
            // fresh connect, no fifth handle).
            $pidAfter = $this->backendPid($deliveryRaw);
            self::assertSame($deliveryPid, $pidAfter, 'reader used the delivery handle — no fifth PID introduced');

            // And the delivery PID is one of the four, not a new one.
            self::assertContains($deliveryPid, $pids);
        } finally {
            pg_close($relayRaw);
            pg_close($queueRaw);
            pg_close($deliveryRaw);
            pg_close($dispatcherRaw);
        }
    }

    // =========================================================================
    // Providers are read-only — write-spy over the delivery handle records zero DML
    // =========================================================================

    public function test_all_providers_issue_zero_dml_on_the_read_path(): void
    {
        $this->enqueue('available', ageSeconds: 5);
        $this->insertHeartbeat('event', 'idle', ageSeconds: 1);
        $this->insertDlq(replayed: false);
        $this->insertModuleVersion('content', '1.0.0');
        $this->insertSchemaVersion('0001_create_system_schema');
        $this->insertContentPage(deleted: false);

        $spy    = new WriteSpyConnection($this->db);
        $reader = new OperationsQueryReader($spy);

        // Every core provider + content metrics + system information, over the spy.
        (new HealthProvider($reader, self::OFFLINE_AFTER_SECONDS))->reports();
        (new QueueStatusProvider($reader))->status();
        (new WorkerStatusProvider($reader, self::OFFLINE_AFTER_SECONDS))->statuses();
        (new MetricsProvider($reader, 3600))->samples();
        (new SystemInformationProvider($reader, '0.1.0', null, 'database'))->snapshot();
        (new ContentMetricsProvider($spy))->samples();

        self::assertSame(0, $spy->executeCount, 'no execute() — providers never write');
        self::assertSame(0, $spy->beginCount, 'no beginTransaction() — providers never open a write txn');
    }

    // =========================================================================
    // Helpers — seeding
    // =========================================================================

    private function enqueue(string $status, int $ageSeconds): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.queue_jobs
                 (id, event_id, queue_name, status, attempts, available_at, completed_at)
             VALUES ($1::uuid, $2::uuid, 'content', $3::varchar, 0,
                     NOW() - make_interval(secs => $4::int),
                     CASE WHEN $3::varchar = 'completed' THEN NOW() - make_interval(secs => $4::int) ELSE NULL END)",
            [$this->newUuid(), $this->newUuid(), $status, $ageSeconds],
        );
    }

    private function insertDlq(bool $replayed): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.dead_letter_jobs
                 (id, job_id, event_id, failure_reason, created_at, attempt_count, payload_snapshot, replayed_at)
             VALUES ($1::uuid, $2::uuid, $3::uuid, 'boom', NOW(), 1, '{}'::jsonb,
                     CASE WHEN $4::boolean THEN NOW() ELSE NULL END)",
            [$this->newUuid(), $this->newUuid(), $this->newUuid(), $replayed ? 't' : 'f'],
        );
    }

    private function insertHeartbeat(string $type, string $status, int $ageSeconds): string
    {
        $id = $this->newUuid();
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.worker_heartbeats
                 (worker_id, worker_type, status, last_heartbeat_at, started_at)
             VALUES ($1::uuid, $2, $3, NOW() - make_interval(secs => $4::int), NOW() - make_interval(secs => $4::int))",
            [$id, $type, $status, $ageSeconds],
        );
        return $id;
    }

    private function seedEvent(string $aggType, string $aggId, int $aggVersion): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.events
                 (id, event_type, event_version, aggregate_type, aggregate_id, aggregate_version,
                  payload, checksum, source_updated_at, created_at, correlation_id, causation_id)
             VALUES ($1::uuid, 'content.post.updated', 1, $2, $3, $4, '{}'::jsonb, $5, NOW(), NOW(), $6::uuid, NULL)",
            [$this->newUuid(), $aggType, $aggId, $aggVersion, str_repeat('a', 64), $this->newUuid()],
        );
    }

    private function seedAggregateVersion(string $aggType, string $aggId, int $version): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.aggregate_versions
                 (aggregate_type, aggregate_id, latest_processed_version, latest_processed_at)
             VALUES ($1, $2, $3, NOW())",
            [$aggType, $aggId, $version],
        );
    }

    private function insertModuleVersion(string $module, string $version): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.module_versions (id, module_name, schema_version, applied_at)
             VALUES ($1::uuid, $2, $3, NOW())",
            [$this->newUuid(), $module, $version],
        );
    }

    private function insertSchemaVersion(string $migration): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO system.schema_versions (id, migration_name, schema_context, applied_at, checksum)
             VALUES ($1::uuid, $2, 'core/pgsql', NOW(), $3)",
            [$this->newUuid(), $migration, str_repeat('c', 64)],
        );
    }

    private function insertContentPage(bool $deleted): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO content.pages
                 (id, source_post_id, slug, title, published_at, updated_at, deleted_at, checksum, created_at, synced_at)
             VALUES ($1::uuid, $2, $3, 'T', NOW(), NOW(),
                     CASE WHEN $4::boolean THEN NOW() ELSE NULL END, $5, NOW(), NOW())",
            [$this->newUuid(), random_int(1, 2_000_000_000), 'p-' . $this->newUuid(), $deleted ? 't' : 'f', str_repeat('b', 64)],
        );
    }

    private function insertTaxonomyTerm(string $taxonomyType, string $slug, bool $deleted): void
    {
        pg_query_params(
            $this->pgConn,
            "INSERT INTO content.taxonomies
                 (id, source_term_id, taxonomy_type, slug, name, description, parent_id, post_count,
                  deleted_at, checksum, created_at, updated_at, synced_at)
             VALUES ($1::uuid, $2, $3, $4, 'T', '', 0, 0,
                     CASE WHEN $5::boolean THEN NOW() ELSE NULL END, $6, NOW(), NOW(), NOW())",
            [
                $this->newUuid(),
                random_int(1, 2_000_000_000),
                $taxonomyType,
                $slug,
                $deleted ? 't' : 'f',
                str_repeat('b', 64),
            ],
        );
    }

    // =========================================================================
    // Helpers — reads / misc
    // =========================================================================

    private function backendPid(mixed $conn): int
    {
        $r = pg_query($conn, 'SELECT pg_backend_pid() AS pid');
        $row = pg_fetch_assoc($r);
        pg_free_result($r);
        return (int) $row['pid'];
    }

    private function intervalToSeconds(\DateInterval $i): int
    {
        return ($i->days ? $i->days * 86400 : 0) + $i->h * 3600 + $i->i * 60 + $i->s;
    }

    private function newUuid(): string
    {
        $ms      = (int) (microtime(true) * 1000);
        $bytes   = random_bytes(10);
        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));
        $hex     = $tsHex . $b67hex . $b89hex . $tailHex;
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }

    // =========================================================================
    // Connection + schema
    // =========================================================================

    private function dsn(): string
    {
        $host = getenv('HSP_TEST_PGSQL_HOST')     ?: '127.0.0.1';
        $port = getenv('HSP_TEST_PGSQL_PORT')     ?: '5432';
        $user = getenv('HSP_TEST_PGSQL_USER')     ?: 'hsp';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: 'hsp_secret';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: 'hsp';

        return "host={$host} port={$port} user={$user} password={$pass} dbname={$db}";
    }

    private function connectPgsql(): mixed
    {
        $conn = @pg_connect($this->dsn(), PGSQL_CONNECT_FORCE_NEW);

        if ($conn === false) {
            self::markTestSkipped('PostgreSQL not available — skipping OPSC-S2 provider integration tests.');
        }

        return $conn;
    }

    private function createSchema(): void
    {
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
        pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS content CASCADE');
        pg_query($this->pgConn, 'CREATE SCHEMA system');
        pg_query($this->pgConn, 'CREATE SCHEMA content');

        pg_query($this->pgConn, "
            CREATE TABLE system.events (
                id                UUID         NOT NULL PRIMARY KEY,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INT          NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                payload           JSONB        NOT NULL,
                checksum          VARCHAR(64)  NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                correlation_id    UUID         NOT NULL,
                causation_id      UUID         NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.queue_jobs (
                id                    UUID         NOT NULL PRIMARY KEY,
                event_id              UUID         NOT NULL,
                queue_name            VARCHAR(255) NOT NULL,
                status                VARCHAR(50)  NOT NULL,
                attempts              INTEGER      NOT NULL DEFAULT 0,
                available_at          TIMESTAMPTZ  NOT NULL,
                started_at            TIMESTAMPTZ  NULL,
                completed_at          TIMESTAMPTZ  NULL,
                last_error            TEXT         NULL,
                worker_id             UUID         NULL,
                visibility_timeout_at TIMESTAMPTZ  NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.dead_letter_jobs (
                id               UUID        NOT NULL PRIMARY KEY,
                job_id           UUID        NOT NULL,
                event_id         UUID        NOT NULL,
                failure_reason   TEXT        NOT NULL,
                created_at       TIMESTAMPTZ NOT NULL,
                stack_trace      TEXT        NULL,
                attempt_count    INTEGER     NOT NULL DEFAULT 0,
                worker_id        UUID        NULL,
                payload_snapshot JSONB       NOT NULL,
                replayed_at      TIMESTAMPTZ NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.aggregate_versions (
                aggregate_type           VARCHAR(100) NOT NULL,
                aggregate_id             VARCHAR(255) NOT NULL,
                latest_processed_version BIGINT       NOT NULL,
                latest_processed_at      TIMESTAMPTZ  NOT NULL,
                CONSTRAINT pk_agg PRIMARY KEY (aggregate_type, aggregate_id)
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.worker_heartbeats (
                worker_id         UUID        NOT NULL PRIMARY KEY,
                worker_type       TEXT        NOT NULL,
                status            TEXT        NOT NULL,
                last_heartbeat_at TIMESTAMPTZ NOT NULL,
                started_at        TIMESTAMPTZ NOT NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.module_versions (
                id             UUID         NOT NULL PRIMARY KEY,
                module_name    VARCHAR(100) NOT NULL,
                schema_version VARCHAR(50)  NOT NULL,
                applied_at     TIMESTAMPTZ  NOT NULL,
                notes          TEXT         NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE system.schema_versions (
                id             UUID         NOT NULL PRIMARY KEY,
                migration_name VARCHAR(255) NOT NULL,
                schema_context VARCHAR(100) NOT NULL,
                applied_at     TIMESTAMPTZ  NOT NULL,
                rolled_back_at TIMESTAMPTZ  NULL,
                checksum       VARCHAR(64)  NOT NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE content.pages (
                id             UUID         NOT NULL PRIMARY KEY,
                source_post_id BIGINT       NOT NULL,
                slug           VARCHAR(255) NOT NULL,
                title          TEXT         NOT NULL,
                published_at   TIMESTAMPTZ  NOT NULL,
                updated_at     TIMESTAMPTZ  NOT NULL,
                deleted_at     TIMESTAMPTZ  NULL,
                checksum       VARCHAR(64)  NOT NULL,
                created_at     TIMESTAMPTZ  NOT NULL,
                synced_at      TIMESTAMPTZ  NOT NULL
            )
        ");

        pg_query($this->pgConn, "
            CREATE TABLE content.posts (
                id UUID NOT NULL PRIMARY KEY, deleted_at TIMESTAMPTZ NULL
            )
        ");

        // The REAL taxonomy DDL, not a two-column stand-in: the content metrics provider counts
        // per taxonomy_type (DECISION AA), so a hand-rolled table without the discriminator
        // would only prove the copy matches itself.
        ContentSchema::ensureTaxonomySupport($this->pgConn);
    }
}
