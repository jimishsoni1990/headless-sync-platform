<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Migrations;

use HSP\Core\Migrations\AbstractSqlMigration;
use HSP\Core\Migrations\Connection\ConnectionInterface;

final class AlignContentEntityTaxonomiesToSourceTermIdMigration extends AbstractSqlMigration
{
    public function __construct(
        private readonly ConnectionInterface $connection
    ) {
    }

    public function getName(): string
    {
        return '0009_align_content_entity_taxonomies_to_source_term_id';
    }

    public function getSchemaContext(): string
    {
        return 'content/pgsql';
    }

    protected function getSqlFilePath(): string
    {
        return __DIR__ . '/0009_align_content_entity_taxonomies_to_source_term_id.sql';
    }

    protected function getConnection(): ConnectionInterface
    {
        return $this->connection;
    }
}
