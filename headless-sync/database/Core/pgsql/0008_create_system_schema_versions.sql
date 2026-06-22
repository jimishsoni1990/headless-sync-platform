-- Migration: 0008_create_system_schema_versions
-- Authority: ARCHITECTURE_DECISIONS.md v1.4 — OPEN-8 (v1.4)
-- Adds to: Doc 3 §24
--
-- Tracks applied migrations and rollback state for all schema contexts.
-- schema_context uses engine-qualified values: 'core/mysql', 'core/pgsql',
--   'content/pgsql', 'commerce/pgsql', etc. — OPEN-8 (v1.4)
-- checksum: VARCHAR(64) sha256 of migration file at apply time (v1.1 canon)
-- rolled_back_at: NULL = migration is currently applied
-- Timestamps: TIMESTAMPTZ (v1.2 canon)

CREATE TABLE IF NOT EXISTS system.schema_versions (
    id             UUID         NOT NULL,
    migration_name VARCHAR(255) NOT NULL,  -- e.g. '0001_create_hsp_outbox'
    schema_context VARCHAR(100) NOT NULL,  -- engine-qualified: 'core/mysql', 'core/pgsql', 'content/pgsql', etc.
    applied_at     TIMESTAMPTZ  NOT NULL,
    rolled_back_at TIMESTAMPTZ  NULL,      -- NULL = currently applied
    checksum       VARCHAR(64)  NOT NULL,  -- sha256 of migration file at apply time

    CONSTRAINT pk_system_schema_versions PRIMARY KEY (id),
    CONSTRAINT uq_schema_versions_migration UNIQUE (migration_name, schema_context)
);
