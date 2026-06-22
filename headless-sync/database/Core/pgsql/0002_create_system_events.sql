-- Migration: 0002_create_system_events
-- Authority: ARCHITECTURE_DECISIONS.md v1.3
-- Composition: Doc 3 §20 base DDL + OPEN-5 (v1.1) deltas + OPEN-1 (event_type canon)
--
-- Doc 3 §20 base columns:
--   id, event_type, event_version, aggregate_type, aggregate_id, payload, created_at
--
-- OPEN-5 (v1.1) delta columns (first-class, queryable):
--   aggregate_version, source_updated_at, checksum, correlation_id, causation_id
--
-- OPEN-1: event_type must accept fully-qualified <domain>.<aggregate>.<action> values
--   (e.g. 'content.post.updated'). VARCHAR(255) is sufficient.
--
-- Timestamps: TIMESTAMPTZ (PostgreSQL; bare TIMESTAMP prohibited — v1.2 canon)
-- Checksums:  VARCHAR(64) (sha256 — v1.1 canon); traceability only per DECISION 3
-- UUIDs:      correlation_id, causation_id (v1.1 canon)
--
-- Relay fidelity (RelayWorkerStrategy — OPEN-6 v1.3):
--   id          := wp_hsp_outbox.id          (event_id preserved; do NOT regenerate)
--   created_at  := wp_hsp_outbox.created_at  (capture time, not relay time)
--   causation_id: NULL for root events (Doc 8 §19-20)
--
-- Events are immutable: never updated, never reused (Doc 3 §20).

CREATE TABLE IF NOT EXISTS system.events (
    -- Doc 3 §20 base
    id                UUID         NOT NULL,
    event_type        VARCHAR(255) NOT NULL, -- fully-qualified <domain>.<aggregate>.<action> — OPEN-1
    event_version     INTEGER      NOT NULL,
    aggregate_type    VARCHAR(100) NOT NULL,
    aggregate_id      VARCHAR(255) NOT NULL,
    payload           JSONB        NOT NULL,
    created_at        TIMESTAMPTZ  NOT NULL, -- capture time; preserved from wp_hsp_outbox.created_at

    -- OPEN-5 (v1.1) first-class columns
    aggregate_version BIGINT       NOT NULL,
    source_updated_at TIMESTAMPTZ  NOT NULL,
    checksum          VARCHAR(64)  NOT NULL, -- sha256; traceability only — DECISION 3
    correlation_id    UUID         NOT NULL,
    causation_id      UUID         NULL,     -- NULL for root events

    CONSTRAINT pk_system_events PRIMARY KEY (id)
);

-- OPEN-5: index for stale-skip and aggregate replay queries
CREATE INDEX IF NOT EXISTS idx_events_aggregate
    ON system.events (aggregate_type, aggregate_id);

-- OPEN-5: index for distributed tracing / causation graph queries
CREATE INDEX IF NOT EXISTS idx_events_correlation
    ON system.events (correlation_id);
