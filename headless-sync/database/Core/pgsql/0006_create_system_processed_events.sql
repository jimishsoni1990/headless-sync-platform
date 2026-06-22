-- Migration: 0006_create_system_processed_events
-- Authority: ARCHITECTURE_DECISIONS.md v1.3 — OPEN-7 (v1.1), DECISION 3
-- Composition: Doc 3 §4 intent (new infrastructure table) + OPEN-7 full DDL
--
-- Purpose: exact-event idempotency (dedup by event_id). Distinct from
--   system.aggregate_versions, which serves stale-version skipping (OPEN-7 rationale).
--
-- Write-suppress logic (DECISION 3):
--   Compares a freshly-computed projection checksum against the stored content.* checksum
--   — NOT against this table's checksum (which is traceability only).
--   Workers insert here inside the three-operation PG transaction:
--     1. projection upsert
--     2. INSERT INTO system.processed_events (this table)
--     3. UPSERT system.aggregate_versions
--   All three must commit in one PostgreSQL transaction.
--
-- event_id: the preserved outbox id — unique constraint enforces exact-once processing
-- checksum: VARCHAR(64) sha256 (v1.1 canon); traceability — DECISION 3
-- Timestamps: TIMESTAMPTZ (v1.2 canon)

CREATE TABLE IF NOT EXISTS system.processed_events (
    event_id     UUID        NOT NULL,
    checksum     VARCHAR(64) NOT NULL, -- sha256; traceability only — DECISION 3
    processed_at TIMESTAMPTZ NOT NULL,

    CONSTRAINT pk_system_processed_events PRIMARY KEY (event_id)
);
