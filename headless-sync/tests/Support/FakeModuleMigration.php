<?php

declare(strict_types=1);

namespace HSP\Tests\Support;

use HSP\Core\Contracts\MigrationInterface;

/**
 * Minimal MigrationInterface double: it only has to answer getName().
 *
 * MigrationsAppliedCheck derives the module half of its required set from the module registry's
 * declarative getMigrations() (FLAG-P1BS1-1 resolution) and reads nothing but the name off each
 * entry, so tests can stand in these instead of building real migrations over a live DDL
 * connection.
 */
final class FakeModuleMigration implements MigrationInterface
{
    public function __construct(private readonly string $name) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSchemaContext(): string
    {
        return 'content/pgsql';
    }

    public function up(): void {}

    public function down(): void {}

    /**
     * Build the `callable(): list<MigrationInterface>` MigrationsAppliedCheck expects from a plain
     * list of migration names.
     *
     * @return \Closure(): list<MigrationInterface>
     */
    public static function listOf(string ...$names): \Closure
    {
        return static function () use ($names): array {
            $out = [];
            foreach ($names as $name) {
                $out[] = new self($name);
            }

            return $out;
        };
    }

    /**
     * The Content module's declared migration names, as ContentModule::getMigrations() returns
     * them. Fixtures that assert "all required migrations applied" must list these too, or the
     * derived required set would not match what a real install has.
     *
     * @return \Closure(): list<MigrationInterface>
     */
    public static function contentModule(): \Closure
    {
        return self::listOf(
            '0001_create_content_schema',
            '0002_create_content_pages',
            '0003_create_content_posts',
            '0004_create_content_taxonomies',
            '0005_create_content_entity_taxonomies',
            '0006_create_content_media',
        );
    }
}
