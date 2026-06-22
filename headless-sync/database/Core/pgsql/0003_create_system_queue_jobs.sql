-- Migration: 0003_create_system_queue_jobs
-- Authority: ARCHITECTURE_DECISIONS.md v1.3
-- Composition: Doc 3 §21 base DDL + OPEN-4 (v1.1) deltas
--
-- Doc 3 §21 base columns:
--   id, event_id, queue_name, status, attempts, available_at, started_at,
--   completed_at, last_error
--
-- OPEN-4 (v1.1) delta columns:
--   worker_id UUID             — identity of claiming worker (UUIDv7 self-assigned at startup)
--   visibility_timeout_at TIMESTAMPTZ — deadline after which a recovery process requeues
--
-- Timestamps: TIMESTAMPTZ (bare TIMESTAMP prohibited — v1.2 canon)
-- worker_id:  UUID (v1.1 canon)
--
-- Claiming protocol (OPEN-4): SELECT … FOR UPDATE SKIP LOCKED
-- Visibility timeout: config-driven; recovery requeues jobs past visibility_timeout_at
-- event_id: soft reference only — no FK (ADR-013; operational tables require replay flexibility)

CREATE TABLE IF NOT EXISTS system.queue_jobs (
    -- Doc 3 §21 base
    id                    UUID         NOT NULL,
    event_id              UUID         NOT NULL,  -- soft ref to system.events.id — ADR-013
    queue_name            VARCHAR(255) NOT NULL,
    status                VARCHAR(50)  NOT NULL,
    attempts              INTEGER      NOT NULL DEFAULT 0,
    available_at          TIMESTAMPTZ  NOT NULL,
    started_at            TIMESTAMPTZ  NULL,
    completed_at          TIMESTAMPTZ  NULL,
    last_error            TEXT         NULL,

    -- OPEN-4 (v1.1) deltas
    worker_id             UUID         NULL,      -- UUIDv7 self-assigned at worker startup
    visibility_timeout_at TIMESTAMPTZ  NULL,      -- recovery requeues after expiry — OPEN-4

    CONSTRAINT pk_system_queue_jobs PRIMARY KEY (id)
);

-- Claim-path index: workers scan pending/available jobs by queue partition
CREATE INDEX IF NOT EXISTS idx_queue_jobs_claim
    ON system.queue_jobs (queue_name, status, available_at);

-- Recovery index: find jobs with expired visibility timeouts
CREATE INDEX IF NOT EXISTS idx_queue_jobs_visibility_timeout
    ON system.queue_jobs (visibility_timeout_at)
    WHERE visibility_timeout_at IS NOT NULL;
