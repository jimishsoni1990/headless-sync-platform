-- Migration: 0004_create_system_dead_letter_jobs
-- Authority: ARCHITECTURE_DECISIONS.md v1.4
-- Composition: Doc 3 §22 base DDL (modified) + OPEN-3 (v1.1) deltas + DECISION A (v1.4)
--
-- Doc 3 §22 base columns:
--   id, job_id, event_id, failure_reason, created_at
--   NOTE: Doc 3 §22 `payload JSONB` is superseded by OPEN-3 `payload_snapshot JSONB` (single column).
--
-- OPEN-3 (v1.1) delta columns:
--   stack_trace TEXT, attempt_count INTEGER, worker_id UUID, payload_snapshot JSONB
--
-- DECISION A (v1.4): payload_snapshot is NOT NULL.
--   If the job payload cannot be parsed to structured JSON at failure time, the raw
--   captured representation must be serialized into JSONB (e.g. {"raw": "<escaped>"})
--   rather than omitted. A NULL payload_snapshot violates this ruling.
--   Rationale: every DLQ entry must be self-contained and replayable without access to
--   any external store.
--
-- Timestamps: TIMESTAMPTZ (bare TIMESTAMP prohibited — v1.2 canon)
-- worker_id:  UUID (v1.1 canon)
-- job_id, event_id: soft references — no FK (ADR-013)

CREATE TABLE IF NOT EXISTS system.dead_letter_jobs (
    -- Doc 3 §22 base (payload superseded by payload_snapshot — OPEN-3 / DECISION A)
    id               UUID        NOT NULL,
    job_id           UUID        NOT NULL,  -- soft ref to system.queue_jobs.id — ADR-013
    event_id         UUID        NOT NULL,  -- soft ref to system.events.id — ADR-013
    failure_reason   TEXT        NOT NULL,
    created_at       TIMESTAMPTZ NOT NULL,

    -- OPEN-3 (v1.1) deltas
    stack_trace      TEXT        NULL,
    attempt_count    INTEGER     NOT NULL DEFAULT 0,
    worker_id        UUID        NULL,      -- UUIDv7 of worker that made final attempt
    payload_snapshot JSONB       NOT NULL,  -- job payload at terminal failure; NOT NULL — DECISION A (v1.4)

    CONSTRAINT pk_system_dead_letter_jobs PRIMARY KEY (id)
);

-- Operational query: look up DLQ entries by originating event
CREATE INDEX IF NOT EXISTS idx_dlq_event_id
    ON system.dead_letter_jobs (event_id);

-- Operational query: look up DLQ entries by originating job
CREATE INDEX IF NOT EXISTS idx_dlq_job_id
    ON system.dead_letter_jobs (job_id);
