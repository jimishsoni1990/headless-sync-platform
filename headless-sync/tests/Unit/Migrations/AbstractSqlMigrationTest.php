<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Migrations;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;
use HSP\Core\Migrations\Exception\MigrationException;
use PHPUnit\Framework\TestCase;

final class AbstractSqlMigrationTest extends TestCase
{
    public function testUpExecutesSqlFileContentsAgainstConnection(): void
    {
        $conn      = new FakeConnection();
        $sqlFile   = tempnam(sys_get_temp_dir(), 'hsp_migration_');
        file_put_contents($sqlFile, 'CREATE TABLE test (id INT);');

        $migration = $this->makeMigration($sqlFile, $conn);
        $migration->up();

        $this->assertCount(1, $conn->executed);
        $this->assertSame('CREATE TABLE test (id INT);', $conn->executed[0]);

        unlink($sqlFile);
    }

    public function testGetSqlReturnsSqlFileContents(): void
    {
        $conn    = new FakeConnection();
        $sqlFile = tempnam(sys_get_temp_dir(), 'hsp_migration_');
        file_put_contents($sqlFile, '-- test sql');

        $migration = $this->makeMigration($sqlFile, $conn);

        $this->assertSame('-- test sql', $migration->getSql());

        unlink($sqlFile);
    }

    public function testGetSqlCachesParsedFile(): void
    {
        $conn    = new FakeConnection();
        $sqlFile = tempnam(sys_get_temp_dir(), 'hsp_migration_');
        file_put_contents($sqlFile, '-- cached');

        $migration = $this->makeMigration($sqlFile, $conn);

        $first  = $migration->getSql();
        $second = $migration->getSql();

        $this->assertSame($first, $second);

        unlink($sqlFile);
    }

    public function testGetSqlThrowsWhenFileDoesNotExist(): void
    {
        $conn      = new FakeConnection();
        $migration = $this->makeMigration('/nonexistent/path/migration.sql', $conn);

        $this->expectException(MigrationException::class);
        $migration->getSql();
    }

    /**
     * Verifies that getSql() returns the raw {prefix} template, not the substituted SQL.
     *
     * The checksum must be identical regardless of the WordPress table-prefix value.
     * If getSql() were called after prefix substitution, installations with different
     * $wpdb->prefix values would record different checksums for the same migration —
     * breaking idempotency checks on re-run.
     */
    public function testGetSqlReturnsRawTemplateNotSubstitutedSql(): void
    {
        $conn    = new FakeConnection();
        $sqlFile = tempnam(sys_get_temp_dir(), 'hsp_migration_');
        file_put_contents($sqlFile, 'CREATE TABLE {prefix}hsp_outbox (id INT);');

        $migration = $this->makeMigration($sqlFile, $conn);

        $sql = $migration->getSql();

        $this->assertStringContainsString('{prefix}', $sql,
            'getSql() must return the raw template — {prefix} must not be substituted');
        $this->assertStringNotContainsString('wp_', $sql,
            'getSql() must not contain a substituted prefix value');

        unlink($sqlFile);
    }

    /**
     * Verifies that the checksum is stable across different table prefix values.
     *
     * Two migration instances pointing at the same SQL file but conceptually used
     * with different $wpdb->prefix values must produce the same getSql() output,
     * and therefore the same sha256 checksum when hashed by MigrationRunner.
     */
    public function testChecksumIsIdenticalAcrossDifferentTablePrefixes(): void
    {
        $sqlFile = tempnam(sys_get_temp_dir(), 'hsp_migration_');
        file_put_contents($sqlFile, 'CREATE TABLE {prefix}hsp_outbox (id INT);');

        $conn1 = new FakeConnection();
        $conn2 = new FakeConnection();

        $m1 = $this->makeMigration($sqlFile, $conn1);
        $m2 = $this->makeMigration($sqlFile, $conn2);

        $checksum1 = hash('sha256', $m1->getSql());
        $checksum2 = hash('sha256', $m2->getSql());

        $this->assertSame($checksum1, $checksum2,
            'sha256 checksum of migration SQL must be identical regardless of table prefix');

        unlink($sqlFile);
    }

    private function makeMigration(string $sqlFilePath, ConnectionInterface $conn): AbstractSqlMigration
    {
        return new class($sqlFilePath, $conn) extends AbstractSqlMigration {
            public function __construct(
                private readonly string $path,
                private readonly ConnectionInterface $c,
            ) {}

            public function getName(): string { return '0001_test'; }
            public function getSchemaContext(): string { return 'core/pgsql'; }
            protected function getSqlFilePath(): string { return $this->path; }
            protected function getConnection(): ConnectionInterface { return $this->c; }
        };
    }
}
