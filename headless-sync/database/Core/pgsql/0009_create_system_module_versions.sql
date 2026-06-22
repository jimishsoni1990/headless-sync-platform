-- Migration: 0009_create_system_module_versions
-- Authority: ARCHITECTURE_DECISIONS.md v1.4 — OPEN-8 (v1.4)
-- Adds to: Doc 3 §24
--
-- Tracks module schema version history (module name, version applied, when).
-- UNIQUE(module_name, schema_version) prevents duplicate version records per module.
-- INDEX(module_name, applied_at DESC) supports efficient current-version lookup.
-- Timestamps: TIMESTAMPTZ (v1.2 canon)

CREATE TABLE IF NOT EXISTS system.module_versions (
    id             UUID         NOT NULL,
    module_name    VARCHAR(100) NOT NULL,  -- e.g. 'content', 'commerce'
    schema_version VARCHAR(50)  NOT NULL,  -- semantic version string, e.g. '1.0.0'
    applied_at     TIMESTAMPTZ  NOT NULL,
    notes          TEXT         NULL,

    CONSTRAINT pk_system_module_versions    PRIMARY KEY (id),
    CONSTRAINT uq_module_versions_name_ver  UNIQUE (module_name, schema_version)
);

-- Current version per module (most recent applied_at wins)
CREATE INDEX IF NOT EXISTS idx_module_versions_module_name
    ON system.module_versions (module_name, applied_at DESC);
