<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Migrations;

use HSP\Core\Contracts\MigrationInterface;

/**
 * Configurable stub migration for unit tests.
 */
final class FakeMigration implements MigrationInterface
{
    public bool $upCalled = false;

    public function __construct(
        private readonly string $name,
        private readonly string $schemaContext = 'core/pgsql',
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchemaContext(): string
    {
        return $this->schemaContext;
    }

    public function up(): void
    {
        $this->upCalled = true;
    }

    public function down(): void {}

    public function getSql(): string
    {
        return "-- fake sql for {$this->name}";
    }
}
