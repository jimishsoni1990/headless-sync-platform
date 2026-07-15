<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Diagnostics;

use HSP\Core\Operations\Diagnostics\OperationsQueryReader;
use HSP\Core\Operations\Diagnostics\SystemInformation;
use HSP\Core\Operations\Diagnostics\SystemInformationProvider;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use PHPUnit\Framework\TestCase;

final class SystemInformationProviderTest extends TestCase
{
    public function test_snapshot_combines_env_facts_and_db_reads(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('SHOW server_version', [['server_version' => '16.3']])
            ->on('COUNT(*) AS c FROM system.schema_versions', [['c' => '13']])
            ->on('SELECT migration_name', [['migration_name' => '0013_add_replayed_at_to_dead_letter_jobs']])
            ->on('FROM   system.module_versions', [['module_name' => 'content', 'schema_version' => '1.0.0']]);

        $provider = new SystemInformationProvider(
            new OperationsQueryReader($conn),
            platformVersion: '0.1.0',
            wordpressVersion: '6.5.2',
            queueProvider: 'database',
        );

        $info = $provider->snapshot();

        self::assertInstanceOf(SystemInformation::class, $info);
        self::assertSame('0.1.0', $info->platformVersion);
        self::assertSame(PHP_VERSION, $info->phpVersion);
        self::assertSame('6.5.2', $info->wordpressVersion);
        self::assertSame('16.3', $info->postgresVersion);
        self::assertSame('database', $info->queueProvider);
        self::assertSame(13, $info->appliedMigrationCount);
        self::assertSame('0013_add_replayed_at_to_dead_letter_jobs', $info->latestMigration);
        self::assertSame(['content' => '1.0.0'], $info->moduleVersions);
    }

    public function test_null_wordpress_version_is_carried_through(): void
    {
        $conn = (new ScriptedReaderConnection())
            ->on('SHOW server_version', [['server_version' => '16.3']])
            ->on('schema_versions', [['c' => '0']])
            ->on('module_versions', []);

        $info = (new SystemInformationProvider(
            new OperationsQueryReader($conn),
            '0.1.0',
            null,
            'database',
        ))->snapshot();

        self::assertNull($info->wordpressVersion);
        self::assertNull($info->latestMigration);
        self::assertSame([], $info->moduleVersions);
    }
}
