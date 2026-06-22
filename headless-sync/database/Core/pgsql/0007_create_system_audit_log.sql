-- Migration: 0007_create_system_audit_log
-- Authority: ARCHITECTURE_DECISIONS.md v1.3 — Doc 3 §23 base only
-- Composition: Doc 3 §23 base DDL; no Implications-table deltas for this table
--
-- Timestamps: TIMESTAMPTZ (bare TIMESTAMP superseded by v1.2 canon)
-- entity_id: soft reference — no FK (ADR-013; operational tables require flexibility)

CREATE TABLE IF NOT EXISTS system.audit_log (
    id          UUID         NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id   UUID         NOT NULL,  -- soft ref — ADR-013
    action      VARCHAR(100) NOT NULL,
    metadata    JSONB        NOT NULL,
    created_at  TIMESTAMPTZ  NOT NULL,

    CONSTRAINT pk_system_audit_log PRIMARY KEY (id)
);

-- Operational query: audit trail per entity
CREATE INDEX IF NOT EXISTS idx_audit_log_entity
    ON system.audit_log (entity_type, entity_id, created_at);
