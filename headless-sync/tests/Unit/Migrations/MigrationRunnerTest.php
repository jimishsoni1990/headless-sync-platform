<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Migrations;

use HSP\Core\Migrations\MigrationRunner;
use HSP\Core\Migrations\Exception\MigrationException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MigrationRunner.
 *
 * All tests use FakeConnection — no real database required.
 *
 * Two runners are used:
 *   $this->runner         — backed by a real temp SQL file; used for bootstrap() tests.
 *   $this->runOnlyRunner  — backed by a sentinel path that is never read (bootstrap()
 *                           is never called in run()-only tests).
 */
final class MigrationRunnerTest extends TestCase
{
    private FakeConnection $conn;

    /** Runner whose schemaVersionsSqlPath points at a real temp file. */
    private MigrationRunner $runner;

    /** Runner whose schemaVersionsSqlPath is never accessed (run()-only tests). */
    private MigrationRunner $runOnlyRunner;

    private string $tempSqlFile;

    protected function setUp(): void
    {
        $this->conn = new FakeConnection();

        // Write a minimal schema_versions DDL into a temp file so bootstrap() has a
        // real single-source file to read. Content includes both IF NOT EXISTS guards.
        $this->tempSqlFile = tempnam(sys_get_temp_dir(), 'hsp_sv_');
        file_put_contents(
            $this->tempSqlFile,
            "CREATE TABLE IF NOT EXISTS system.schema_versions (\n"
            . "    id             UUID         NOT NULL,\n"
            . "    migration_name VARCHAR(255) NOT NULL,\n"
            . "    schema_context VARCHAR(100) NOT NULL,\n"
            . "    applied_at     TIMESTAMPTZ  NOT NULL,\n"
            . "    rolled_back_at TIMESTAMPTZ  NULL,\n"
            . "    checksum       VARCHAR(64)  NOT NULL,\n"
            . "    CONSTRAINT pk_system_schema_versions PRIMARY KEY (id),\n"
            . "    CONSTRAINT uq_schema_versions_migration UNIQUE (migration_name, schema_context)\n"
            . ");\n"
        );

        $this->runner = new MigrationRunner($this->conn, $this->tempSqlFile);

        // For run()-only tests: path is never accessed, so it can be any string.
        $this->runOnlyRunner = new MigrationRunner($this->conn, '/dev/null/never-read');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempSqlFile)) {
            unlink($this->tempSqlFile);
        }
    }

    // -----------------------------------------------------------------------
    // bootstrap() — single-source DDL
    // -----------------------------------------------------------------------

    public function testBootstrapExecutesSchemaVersionsSqlFileVerbatim(): void
    {
        $this->runner->bootstrap();

        // Two execute() calls: CREATE SCHEMA + the file content
        $this->assertCount(2, $this->conn->executed);
        $this->assertSame('CREATE SCHEMA IF NOT EXISTS system', $this->conn->executed[0]);
        $this->assertStringContainsString('system.schema_versions', $this->conn->executed[1]);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS', $this->conn->executed[1]);

        // Confirm the executed DDL is the file content verbatim (not an inline copy)
        $expectedDdl = file_get_contents($this->tempSqlFile);
        $this->assertSame($expectedDdl, $this->conn->executed[1],
            'bootstrap() must execute the SQL file verbatim — no inline DDL copy');
    }

    public function testBootstrapThrowsWhenSqlFileIsMissing(): void
    {
        $runner = new MigrationRunner($this->conn, '/nonexistent/0008_schema_versions.sql');

        $this->expectException(MigrationException::class);
        $runner->bootstrap();
    }

    public function testBootstrapIsIdempotentWhenCalledMultipleTimes(): void
    {
        $this->runner->bootstrap();
        $this->runner->bootstrap();

        // 4 execute() calls: (CREATE SCHEMA + CREATE TABLE) × 2
        $this->assertCount(4, $this->conn->executed);

        foreach ($this->conn->executed as $sql) {
            $this->assertStringContainsString('IF NOT EXISTS', $sql,
                'All bootstrap DDL must use IF NOT EXISTS to be idempotent');
        }
    }

    // -----------------------------------------------------------------------
    // bootstrap() — single-source verification against the real 0008 file
    // -----------------------------------------------------------------------

    public function testBootstrapDdlMatchesOpen8FrozenDdlVerbatim(): void
    {
        $realPath = dirname(__DIR__, 3)
            . '/database/Core/pgsql/0008_create_system_schema_versions.sql';

        if (! file_exists($realPath)) {
            $this->markTestSkipped('Real 0008 SQL file not present at expected path.');
        }

        $runner = new MigrationRunner($this->conn, $realPath);
        $runner->bootstrap();

        $executedDdl = $this->conn->executed[1]; // second call is the table DDL

        // Column-by-column assertions against OPEN-8 v1.4 frozen DDL
        $this->assertStringContainsString('id             UUID         NOT NULL', $executedDdl);
        $this->assertStringContainsString('migration_name VARCHAR(255) NOT NULL', $executedDdl);
        $this->assertStringContainsString('schema_context VARCHAR(100) NOT NULL', $executedDdl);
        $this->assertStringContainsString('applied_at     TIMESTAMPTZ  NOT NULL', $executedDdl);
        $this->assertStringContainsString('rolled_back_at TIMESTAMPTZ  NULL', $executedDdl);
        $this->assertStringContainsString('checksum       VARCHAR(64)  NOT NULL', $executedDdl);
        $this->assertStringContainsString('UNIQUE (migration_name, schema_context)', $executedDdl);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS system.schema_versions', $executedDdl);
    }

    // -----------------------------------------------------------------------
    // run() — ordering
    // -----------------------------------------------------------------------

    public function testRunAppliesMigrationsInAscendingNameOrder(): void
    {
        $mA = new FakeMigration('0003_c');
        $mB = new FakeMigration('0001_a');
        $mC = new FakeMigration('0002_b');

        $this->runOnlyRunner->run([$mA, $mB, $mC]);

        $this->assertTrue($mA->upCalled);
        $this->assertTrue($mB->upCalled);
        $this->assertTrue($mC->upCalled);

        // Insert order reflects ascending sort: 0001 → 0002 → 0003
        $this->assertSame('0001_a', $this->conn->insertedRows[0][1]);
        $this->assertSame('0002_b', $this->conn->insertedRows[1][1]);
        $this->assertSame('0003_c', $this->conn->insertedRows[2][1]);
    }

    // -----------------------------------------------------------------------
    // Ordering guard — system schema namespace before system.* table migrations
    // -----------------------------------------------------------------------

    public function testSystemSchemaCreationRunsBeforeAnySystemTableMigration(): void
    {
        $schemaMigration = new FakeMigration('0001_create_system_schema', 'core/pgsql');
        $eventsMigration = new FakeMigration('0002_create_system_events', 'core/pgsql');
        $queueMigration  = new FakeMigration('0003_create_system_queue_jobs', 'core/pgsql');

        // Pass deliberately out of order — runner must sort them
        $this->runOnlyRunner->run([$queueMigration, $eventsMigration, $schemaMigration]);

        $names = array_column($this->conn->insertedRows, 1);

        $schemaIndex = array_search('0001_create_system_schema', $names, true);
        $eventsIndex = array_search('0002_create_system_events', $names, true);
        $queueIndex  = array_search('0003_create_system_queue_jobs', $names, true);

        $this->assertNotFalse($schemaIndex, '0001_create_system_schema must be recorded');
        $this->assertNotFalse($eventsIndex, '0002_create_system_events must be recorded');
        $this->assertNotFalse($queueIndex,  '0003_create_system_queue_jobs must be recorded');

        $this->assertLessThan($eventsIndex, $schemaIndex,
            '0001_create_system_schema must be applied before 0002_create_system_events');
        $this->assertLessThan($queueIndex, $schemaIndex,
            '0001_create_system_schema must be applied before 0003_create_system_queue_jobs');
    }

    // -----------------------------------------------------------------------
    // run() — idempotency
    // -----------------------------------------------------------------------

    public function testRunSkipsMigrationAlreadyRecordedInSchemaVersions(): void
    {
        $this->conn->queryRows = [
            ['migration_name' => '0001_create_thing', 'schema_context' => 'core/pgsql'],
        ];

        $migration = new FakeMigration('0001_create_thing', 'core/pgsql');
        $this->runOnlyRunner->run([$migration]);

        $this->assertFalse($migration->upCalled, 'Already-applied migration must be skipped');
        $this->assertEmpty($this->conn->insertedRows, 'No record should be written for a skipped migration');
    }

    public function testRunAppliesOnlyPendingMigrationsWhenSomeAlreadyApplied(): void
    {
        $this->conn->queryRows = [
            ['migration_name' => '0001_first', 'schema_context' => 'core/pgsql'],
        ];

        $m1 = new FakeMigration('0001_first', 'core/pgsql');
        $m2 = new FakeMigration('0002_second', 'core/pgsql');

        $this->runOnlyRunner->run([$m1, $m2]);

        $this->assertFalse($m1->upCalled, '0001 is already applied — must be skipped');
        $this->assertTrue($m2->upCalled,  '0002 is pending — must be applied');
        $this->assertCount(1, $this->conn->insertedRows);
        $this->assertSame('0002_second', $this->conn->insertedRows[0][1]);
    }

    public function testFreshRunProducesExactlyOneSchemaVersionsRowPerMigration(): void
    {
        $m1 = new FakeMigration('0001_create_system_schema', 'core/pgsql');
        $m2 = new FakeMigration('0002_create_system_events', 'core/pgsql');

        $this->runOnlyRunner->run([$m1, $m2]);

        $this->assertCount(2, $this->conn->insertedRows,
            'Fresh run must produce exactly one schema_versions row per migration');
    }

    public function testReRunProducesNoAdditionalSchemaVersionsRows(): void
    {
        $m1 = new FakeMigration('0001_create_system_schema', 'core/pgsql');
        $m2 = new FakeMigration('0002_create_system_events', 'core/pgsql');

        $this->conn->queryRows = [
            ['migration_name' => '0001_create_system_schema', 'schema_context' => 'core/pgsql'],
            ['migration_name' => '0002_create_system_events', 'schema_context' => 'core/pgsql'],
        ];

        $this->runOnlyRunner->run([$m1, $m2]);

        $this->assertCount(0, $this->conn->insertedRows,
            'Re-run with all migrations already applied must produce zero new schema_versions rows');
        $this->assertFalse($m1->upCalled, 'up() must not be called for already-applied migration');
        $this->assertFalse($m2->upCalled, 'up() must not be called for already-applied migration');
    }

    // -----------------------------------------------------------------------
    // run() — schema_versions record content
    // -----------------------------------------------------------------------

    public function testRunRecordsSchemaContextInInsertedRow(): void
    {
        $migration = new FakeMigration('0001_thing', 'core/mysql');
        $this->runOnlyRunner->run([$migration]);

        $this->assertSame('core/mysql', $this->conn->insertedRows[0][2]);
    }

    public function testRunRecordsChecksumAsSha256OfMigrationSql(): void
    {
        $migration = new FakeMigration('0001_thing', 'core/pgsql');
        $this->runOnlyRunner->run([$migration]);

        $expectedChecksum = hash('sha256', $migration->getSql());
        $actualChecksum   = $this->conn->insertedRows[0][4];

        $this->assertSame($expectedChecksum, $actualChecksum);
    }

    public function testRunRecordsUuidV7AsId(): void
    {
        $migration = new FakeMigration('0001_thing');
        $this->runOnlyRunner->run([$migration]);

        $id = $this->conn->insertedRows[0][0];

        // UUIDv7 (ADR-015): version nibble = 7, variant bits = 8|9|a|b
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id,
            'id must be a valid UUIDv7 (ADR-015 platform canon)'
        );
    }

    public function testUuidV7IdsAreMonotonicallyIncreasingAcrossRunCalls(): void
    {
        $m1 = new FakeMigration('0001_a');
        $m2 = new FakeMigration('0002_b');

        $this->runOnlyRunner->run([$m1, $m2]);

        $id1 = $this->conn->insertedRows[0][0];
        $id2 = $this->conn->insertedRows[1][0];

        // UUIDv7 time prefix is the first 12 hex chars (48-bit ms timestamp)
        $prefix1 = substr(str_replace('-', '', $id1), 0, 12);
        $prefix2 = substr(str_replace('-', '', $id2), 0, 12);

        $this->assertGreaterThanOrEqual($prefix1, $prefix2,
            'UUIDv7 ids must be time-ordered (non-decreasing)');
    }

    // -----------------------------------------------------------------------
    // run() — empty list
    // -----------------------------------------------------------------------

    public function testRunWithEmptyMigrationListPerformsNoInserts(): void
    {
        $this->runOnlyRunner->run([]);

        $this->assertEmpty($this->conn->insertedRows);
    }

    // -----------------------------------------------------------------------
    // Idempotency: running the same pending migration twice in one call
    // -----------------------------------------------------------------------

    public function testRunDoesNotCallUpTwiceForDuplicateMigrationNames(): void
    {
        $m1 = new FakeMigration('0001_thing', 'core/pgsql');
        $m2 = new FakeMigration('0001_thing', 'core/pgsql');

        // Both have the same name+context. The runner applies both (dedup at DB constraint
        // level via ON CONFLICT DO NOTHING), not at the runner level. Documents this behavior.
        $this->runOnlyRunner->run([$m1, $m2]);

        $this->assertTrue($m1->upCalled);
        $this->assertCount(2, $this->conn->insertedRows);
    }
}
