<?php

declare(strict_types=1);

namespace HSP\Tests\Integration\Events\Outbox;

use HSP\Core\Events\Outbox\Connection\MysqliOutboxConnection;
use HSP\Core\Events\Outbox\Connection\OutboxConnectionInterface;
use HSP\Core\Events\Outbox\Connection\PgsqlOutboxConnection;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;
use HSP\Core\Workers\Strategies\RelayWorkerStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Relay end-to-end integration test: MySQL wp_hsp_outbox → PostgreSQL system.events.
 *
 * Proves all four P0-S4 DoD items against live databases:
 *   1. Happy path: pending row → tick() → exactly one system.events row, outbox 'relayed'.
 *   2. Idempotent re-relay: tick() twice over the same row → exactly ONE system.events row.
 *   3. Crash safety: PG insert commits, MySQL txn rolls back → outbox stays 'pending',
 *      PG row exists. Second tick() → still ONE system.events row, outbox now 'relayed'.
 *   4. SKIP LOCKED concurrency: two workers share a pending set, no row relayed twice.
 *
 * Authority: OPEN-6 v1.3 (relay fidelity), OPEN-4 (SKIP LOCKED), DECISION 1 (no cross-DB txn).
 *
 * Environment variables (test self-skips only if a DB is genuinely absent):
 *   HSP_TEST_MYSQL_HOST / PORT / USER / PASSWORD / DATABASE
 *   HSP_TEST_PGSQL_HOST / PORT / USER / PASSWORD / DATABASE
 */
final class RelayEndToEndTest extends TestCase
{
    private ?\mysqli        $mysqli    = null;
    private mixed           $pgConn    = null;
    private string          $prefix    = 'test_relay_';
    private string          $outbox;

    // -------------------------------------------------------------------------
    // setUp / tearDown
    // -------------------------------------------------------------------------

    protected function setUp(): void
    {
        $this->outbox = $this->prefix . 'hsp_outbox';

        $this->mysqli  = $this->connectMysql();
        $this->pgConn  = $this->connectPgsql();

        $this->createMysqlSchema();
        $this->createPgsqlSchema();
    }

    protected function tearDown(): void
    {
        if ($this->mysqli !== null) {
            $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
            $this->mysqli->close();
        }
        if ($this->pgConn !== null) {
            pg_query($this->pgConn, 'DROP TABLE IF EXISTS system.relay_test_events');
            pg_query($this->pgConn, 'DROP SCHEMA IF EXISTS system CASCADE');
            pg_close($this->pgConn);
        }
    }

    // -------------------------------------------------------------------------
    // Test 1 — Happy path
    // -------------------------------------------------------------------------

    public function test_pending_row_is_relayed_and_marked_relayed(): void
    {
        $id = $this->insertOutboxRow();

        $relay = $this->makeRelay();
        $result = $relay->tick();

        self::assertTrue($result, 'tick() must return true when a row is processed');

        // system.events must contain exactly one row with the correct id.
        $pgRows = $this->querySystemEvents();
        self::assertCount(1, $pgRows, 'Exactly one row must exist in system.events');
        self::assertSame($id, $pgRows[0]['id'], 'event_id must be preserved (OPEN-6 v1.3)');
        self::assertSame('content.post.created', $pgRows[0]['event_type']);

        // Outbox row must be marked 'relayed' with a relayed_at timestamp.
        $outboxRow = $this->queryOutboxRow($id);
        self::assertSame('relayed', $outboxRow['status']);
        self::assertNotNull($outboxRow['relayed_at'], 'relayed_at must be set');

        // created_at must be preserved from the outbox row (capture time, not relay time — OPEN-6 v1.3).
        // The outbox stores DATETIME UTC; PG stores TIMESTAMPTZ. Compare the date portion.
        $expectedDate = gmdate('Y-m-d');
        self::assertStringContainsString($expectedDate, $pgRows[0]['created_at']);
    }

    public function test_tick_returns_false_when_queue_empty(): void
    {
        self::assertFalse($this->makeRelay()->tick());
    }

    // -------------------------------------------------------------------------
    // Test 2 — Idempotent re-relay
    // -------------------------------------------------------------------------

    public function test_double_tick_produces_exactly_one_system_events_row(): void
    {
        $id = $this->insertOutboxRow();

        $relay = $this->makeRelay();

        // First tick relays the row and marks it 'relayed'.
        $relay->tick();

        // Reset the row to 'pending' to force a second relay of the same event_id.
        // This simulates an at-least-once re-delivery (e.g. the first MySQL COMMIT
        // succeeded but the caller crashed before recording completion).
        $this->resetOutboxRowToPending($id);

        // Second tick: PG already has the row — ON CONFLICT DO NOTHING must fire.
        $relay->tick();

        $pgRows = $this->querySystemEvents();
        self::assertCount(1, $pgRows, 'ON CONFLICT DO NOTHING must prevent duplicate — exactly one row');
        self::assertSame($id, $pgRows[0]['id']);
    }

    // -------------------------------------------------------------------------
    // Test 3 — Crash safety: PG commits, MySQL rolls back
    // -------------------------------------------------------------------------

    public function test_crash_after_pg_insert_leaves_outbox_pending_and_pg_row_intact(): void
    {
        $id = $this->insertOutboxRow();

        // Wrap the MySQL connection with a saboteur that throws on COMMIT,
        // simulating a crash after the PG insert has already committed.
        $mysqlConn = new MysqliOutboxConnection($this->mysqli);
        $saboteur  = new CommitSaboteurMysqlConnection($mysqlConn);

        $relay = new RelayWorkerStrategy($saboteur, $this->makePgsqlConn(), $this->prefix, 10);

        try {
            $relay->tick();
            self::fail('Expected OutboxWriteException from saboteur was not thrown');
        } catch (OutboxWriteException) {
            // Expected — saboteur fired on COMMIT.
        }

        // Outbox row must still be 'pending' (MySQL txn rolled back).
        $outboxRow = $this->queryOutboxRow($id);
        self::assertSame(
            'pending',
            $outboxRow['status'],
            'MySQL rollback must leave outbox row as pending — no partial mark',
        );
        self::assertNull($outboxRow['relayed_at']);

        // PG row must already exist (PG insert committed independently — DECISION 1).
        $pgRows = $this->querySystemEvents();
        self::assertCount(1, $pgRows, 'PG row must exist after PG insert committed');
        self::assertSame($id, $pgRows[0]['id']);

        // Recovery: run tick() again with a healthy relay.
        $recovery = $this->makeRelay();
        $recovery->tick();

        // After recovery: still exactly ONE PG row (ON CONFLICT DO NOTHING held).
        $pgRowsAfter = $this->querySystemEvents();
        self::assertCount(1, $pgRowsAfter, 'Re-relay must not duplicate the PG row');

        // Outbox row must now be 'relayed'.
        $outboxAfter = $this->queryOutboxRow($id);
        self::assertSame('relayed', $outboxAfter['status']);
        self::assertNotNull($outboxAfter['relayed_at']);
    }

    // -------------------------------------------------------------------------
    // Test 4 — SKIP LOCKED concurrency
    // -------------------------------------------------------------------------

    public function test_two_concurrent_ticks_do_not_duplicate_system_events_rows(): void
    {
        // Insert 4 pending rows.
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->insertOutboxRow("content.post.created", "post", (string) ($i + 1));
        }

        // Worker A opens a transaction and locks all 4 rows with FOR UPDATE.
        // While A's transaction is open, Worker B's tick() should find no pending
        // rows (all locked by A) and return false.
        //
        // We simulate this by: beginning a long-running MySQL txn on a second
        // connection that holds locks, then running tick() on a third connection.

        $lockConn = new \mysqli(
            getenv('HSP_TEST_MYSQL_HOST') ?: '127.0.0.1',
            getenv('HSP_TEST_MYSQL_USER'),
            getenv('HSP_TEST_MYSQL_PASSWORD') ?: '',
            getenv('HSP_TEST_MYSQL_DATABASE'),
            (int) (getenv('HSP_TEST_MYSQL_PORT') ?: 3306),
        );
        $lockConn->set_charset('utf8mb4');

        try {
            // Lock all pending rows in a transaction that we hold open.
            $lockConn->begin_transaction();
            $lockConn->query(
                "SELECT `id` FROM `{$this->outbox}`
                 WHERE `status` = 'pending'
                 FOR UPDATE"
            );

            // Worker B tick() on a fresh connection — SKIP LOCKED means it skips
            // all locked rows and returns false (no rows available).
            $workerB = $this->makeRelay();
            $resultB = $workerB->tick();

            self::assertFalse(
                $resultB,
                'SKIP LOCKED: Worker B must find no rows while Worker A holds all locks',
            );

            $pgRowsWhileLocked = $this->querySystemEvents();
            self::assertCount(
                0,
                $pgRowsWhileLocked,
                'No system.events rows must exist while Worker A holds locks and B skipped',
            );

        } finally {
            $lockConn->rollback();
            $lockConn->close();
        }

        // After Worker A releases locks (rollback above), Worker B can claim rows.
        $workerC = $this->makeRelay();
        $workerC->tick();

        $pgRowsAfter = $this->querySystemEvents();
        self::assertCount(4, $pgRowsAfter, 'All 4 rows must be relayed after lock is released');

        // No duplicates.
        $relayedIds = array_column($pgRowsAfter, 'id');
        self::assertCount(4, array_unique($relayedIds), 'All relayed event_ids must be unique');
        sort($relayedIds);
        sort($ids);
        self::assertSame($ids, $relayedIds, 'Relayed ids must match inserted ids exactly');
    }

    // -------------------------------------------------------------------------
    // Infrastructure helpers
    // -------------------------------------------------------------------------

    private function makeRelay(): RelayWorkerStrategy
    {
        return new RelayWorkerStrategy(
            new MysqliOutboxConnection($this->mysqli),
            $this->makePgsqlConn(),
            $this->prefix,
            100,
        );
    }

    private function makePgsqlConn(): PgsqlOutboxConnection
    {
        return new PgsqlOutboxConnection($this->pgConn);
    }

    /**
     * Insert one row into the test outbox. Returns the event_id.
     */
    private function insertOutboxRow(
        string $eventType     = 'content.post.created',
        string $aggregateType = 'post',
        string $aggregateId   = '42',
    ): string {
        $id            = $this->uuidv7();
        $correlationId = $this->uuidv7();
        $now           = gmdate('Y-m-d H:i:s');
        $checksum      = hash('sha256', '{"title":"Hello"}');
        $payload       = '{"title":"Hello"}';

        $stmt = $this->mysqli->prepare(
            "INSERT INTO `{$this->outbox}`
                 (`id`, `event_type`, `event_version`, `aggregate_type`, `aggregate_id`,
                  `aggregate_version`, `source_updated_at`, `checksum`, `correlation_id`,
                  `causation_id`, `payload`, `status`, `created_at`, `relayed_at`)
             VALUES (?, ?, 1, ?, ?, 1, '2026-01-15 10:00:00', ?, ?, NULL, ?, 'pending', ?, NULL)"
        );

        $stmt->bind_param('ssssssss',
            $id, $eventType, $aggregateType, $aggregateId,
            $checksum, $correlationId, $payload, $now,
        );
        $stmt->execute();
        $stmt->close();

        return $id;
    }

    /** @return array<string, mixed> */
    private function queryOutboxRow(string $id): array
    {
        $stmt = $this->mysqli->prepare(
            "SELECT `status`, `relayed_at` FROM `{$this->outbox}` WHERE `id` = ?"
        );
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row    = $result->fetch_assoc();
        $stmt->close();

        return $row ?? [];
    }

    private function resetOutboxRowToPending(string $id): void
    {
        $stmt = $this->mysqli->prepare(
            "UPDATE `{$this->outbox}` SET `status` = 'pending', `relayed_at` = NULL WHERE `id` = ?"
        );
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $stmt->close();
    }

    /** @return array<int, array<string, mixed>> */
    private function querySystemEvents(): array
    {
        $result = pg_query($this->pgConn, 'SELECT id, event_type, created_at FROM system.events ORDER BY created_at');
        if ($result === false) {
            return [];
        }
        $rows = pg_fetch_all($result) ?: [];
        pg_free_result($result);
        return $rows;
    }

    // -------------------------------------------------------------------------
    // Schema setup
    // -------------------------------------------------------------------------

    private function createMysqlSchema(): void
    {
        // Identical to frozen OPEN-6 v1.3 DDL with a test-specific table name.
        $this->mysqli->query("DROP TABLE IF EXISTS `{$this->outbox}`");
        $this->mysqli->query(
            "CREATE TABLE `{$this->outbox}` (
                `id`                CHAR(36)                   NOT NULL,
                `event_type`        VARCHAR(255)               NOT NULL,
                `event_version`     INT                        NOT NULL,
                `aggregate_type`    VARCHAR(100)               NOT NULL,
                `aggregate_id`      VARCHAR(255)               NOT NULL,
                `aggregate_version` BIGINT                     NOT NULL,
                `source_updated_at` DATETIME                   NOT NULL,
                `checksum`          CHAR(64)                   NOT NULL,
                `correlation_id`    CHAR(36)                   NOT NULL,
                `causation_id`      CHAR(36)                   NULL,
                `payload`           JSON                       NOT NULL,
                `status`            ENUM('pending','relayed')  NOT NULL DEFAULT 'pending',
                `created_at`        DATETIME                   NOT NULL,
                `relayed_at`        DATETIME                   NULL,
                PRIMARY KEY (`id`),
                INDEX `idx_relay_claim` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    private function createPgsqlSchema(): void
    {
        pg_query($this->pgConn, 'CREATE SCHEMA IF NOT EXISTS system');

        // Mirror the frozen OPEN-6 / OPEN-5 DDL exactly.
        pg_query($this->pgConn, 'DROP TABLE IF EXISTS system.events');
        pg_query($this->pgConn,
            "CREATE TABLE system.events (
                id                UUID         NOT NULL,
                event_type        VARCHAR(255) NOT NULL,
                event_version     INTEGER      NOT NULL,
                aggregate_type    VARCHAR(100) NOT NULL,
                aggregate_id      VARCHAR(255) NOT NULL,
                payload           JSONB        NOT NULL,
                created_at        TIMESTAMPTZ  NOT NULL,
                aggregate_version BIGINT       NOT NULL,
                source_updated_at TIMESTAMPTZ  NOT NULL,
                checksum          VARCHAR(64)  NOT NULL,
                correlation_id    UUID         NOT NULL,
                causation_id      UUID         NULL,
                CONSTRAINT pk_system_events PRIMARY KEY (id)
            )"
        );
    }

    // -------------------------------------------------------------------------
    // DB connection helpers
    // -------------------------------------------------------------------------

    private function connectMysql(): \mysqli
    {
        $host = getenv('HSP_TEST_MYSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_MYSQL_PORT') ?: 3306);
        $user = getenv('HSP_TEST_MYSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_MYSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_MYSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            $this->markTestSkipped('MySQL env vars not set (HSP_TEST_MYSQL_USER, HSP_TEST_MYSQL_DATABASE).');
        }

        $mysqli = new \mysqli($host, $user, $pass, $db, $port);

        if ($mysqli->connect_errno) {
            $this->markTestSkipped("MySQL connect failed: {$mysqli->connect_error}");
        }

        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function connectPgsql(): mixed
    {
        $host = getenv('HSP_TEST_PGSQL_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('HSP_TEST_PGSQL_PORT') ?: 5432);
        $user = getenv('HSP_TEST_PGSQL_USER') ?: '';
        $pass = getenv('HSP_TEST_PGSQL_PASSWORD') ?: '';
        $db   = getenv('HSP_TEST_PGSQL_DATABASE') ?: '';

        if ($user === '' || $db === '') {
            $this->markTestSkipped('PostgreSQL env vars not set (HSP_TEST_PGSQL_USER, HSP_TEST_PGSQL_DATABASE).');
        }

        $dsn  = "host={$host} port={$port} dbname={$db} user={$user} password={$pass}";
        $conn = pg_connect($dsn);

        if ($conn === false) {
            $this->markTestSkipped("PostgreSQL connect failed with DSN: host={$host} port={$port} dbname={$db}");
        }

        return $conn;
    }

    // -------------------------------------------------------------------------
    // UUIDv7 — ADR-015 canon
    // -------------------------------------------------------------------------

    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex   = sprintf('%012x', $ms);
        $rand12  = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex  = sprintf('%04x', 0x7000 | $rand12);
        $rand14  = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex  = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tailHex;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}

// =============================================================================
// CommitSaboteurMysqlConnection
// =============================================================================

/**
 * Decorates OutboxConnectionInterface and throws on commit() to simulate a process
 * crash that occurs after the PostgreSQL insert has committed but before the MySQL
 * transaction commits — the exact crash scenario for test 3.
 *
 * All methods except commit() delegate to the real connection; commit() calls
 * rollback() on the real connection first (so the MySQL txn is cleanly aborted)
 * then throws OutboxWriteException to simulate the crash.
 */
final class CommitSaboteurMysqlConnection implements OutboxConnectionInterface
{
    public function __construct(private readonly OutboxConnectionInterface $inner) {}

    public function execute(string $sql, array $params = []): int
    {
        return $this->inner->execute($sql, $params);
    }

    public function query(string $sql, array $params = []): array
    {
        return $this->inner->query($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        // Simulate crash: roll back the MySQL txn instead of committing.
        $this->inner->rollback();
        throw new OutboxWriteException('Simulated process crash before MySQL COMMIT');
    }

    public function rollback(): void
    {
        $this->inner->rollback();
    }
}
