<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Migrations;

use HSP\Core\Contracts\MigrationInterface;

/**
 * Stub migration that also reports whether its artifact still exists.
 *
 * Mirrors the real MySQL migrations (CreateHspOutboxMigration / CreateHspAggregateCountersMigration),
 * which expose isSatisfied() so MigrationRunner can tell "the ledger says applied" apart from "the
 * table is actually there" — the two diverge whenever the WordPress database is restored or reset
 * independently of the PostgreSQL ledger.
 */
final class FakeVerifiableMigration implements MigrationInterface
{
    public int $upCalls = 0;

    public function __construct(
        private readonly string $name,
        private bool $satisfied,
        private readonly string $schemaContext = 'core/mysql',
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchemaContext(): string
    {
        return $this->schemaContext;
    }

    public function isSatisfied(): bool
    {
        return $this->satisfied;
    }

    /** Re-creating the artifact makes the migration satisfied, as the real CREATE TABLE does. */
    public function up(): void
    {
        $this->upCalls++;
        $this->satisfied = true;
    }

    public function down(): void {}

    public function getSql(): string
    {
        return "-- fake verifiable sql for {$this->name}";
    }
}
