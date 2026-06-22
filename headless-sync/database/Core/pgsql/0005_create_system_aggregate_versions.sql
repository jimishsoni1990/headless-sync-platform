-- Migration: 0005_create_system_aggregate_versions
-- Authority: ARCHITECTURE_DECISIONS.md v1.3 — OPEN-2
-- Composition: Doc 3 §4 intent (new infrastructure table) + OPEN-2 full DDL
--
-- Purpose: enables stale-event skipping at the worker level without a full scan of
--   system.processed_events. Workers upsert here inside the three-operation PG transaction
--   (DECISION 3): projection upsert + system.processed_events insert + this upsert.
--
-- Timestamps: TIMESTAMPTZ (v1.2 canon)
-- This table is distinct from wp_hsp_aggregate_counters (MySQL source counter):
--   wp_hsp_aggregate_counters tracks versions assigned at capture time (WordPress side).
--   system.aggregate_versions tracks the latest version successfully processed (PG side).

CREATE TABLE IF NOT EXISTS system.aggregate_versions (
    aggregate_type           VARCHAR(100) NOT NULL,
    aggregate_id             VARCHAR(255) NOT NULL,
    latest_processed_version BIGINT       NOT NULL,
    latest_processed_at      TIMESTAMPTZ  NOT NULL,

    CONSTRAINT pk_system_aggregate_versions PRIMARY KEY (aggregate_type, aggregate_id)
);
