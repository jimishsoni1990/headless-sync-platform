-- Migration: 0012_create_system_worker_heartbeats
-- Authority: ARCHITECTURE_DECISIONS.md v1.16 — DECISION P (Ruling 1)
--
-- DECISION P: worker heartbeats persist to a SINGLE current-state table — one row
-- per worker, upserted per tick. There is NO history table.
--
-- Frozen DDL (DECISION P):
--   worker_id         UUID        NOT NULL  -- UUIDv7, self-assigned at worker startup (ADR-015)
--   worker_type       TEXT        NOT NULL  -- e.g. 'event', 'dispatcher', 'maintenance', 'relay'
--   status            TEXT        NOT NULL  -- e.g. 'running', 'idle', 'processing', 'shutdown'
--   last_heartbeat_at TIMESTAMPTZ NOT NULL  -- updated every tick; crash detection reads this
--   started_at        TIMESTAMPTZ NOT NULL  -- worker process start time
--   PRIMARY KEY (worker_id)
--
-- Timestamps: TIMESTAMPTZ (bare TIMESTAMP prohibited — v1.2 canon)
-- worker_id:  UUID (v1.1 canon)
--
-- A monitor detects a crashed worker by last_heartbeat_at age (Doc 8 §15).
-- Migration authorized for OPS-S1 by DECISION P (v1.16).

CREATE TABLE IF NOT EXISTS system.worker_heartbeats (
    worker_id         UUID        NOT NULL,
    worker_type       TEXT        NOT NULL,
    status            TEXT        NOT NULL,
    last_heartbeat_at TIMESTAMPTZ NOT NULL,
    started_at        TIMESTAMPTZ NOT NULL,

    CONSTRAINT pk_system_worker_heartbeats PRIMARY KEY (worker_id)
);

-- Crash-detection query: monitors scan for workers whose last_heartbeat_at is stale.
CREATE INDEX IF NOT EXISTS idx_worker_heartbeats_last_heartbeat_at
    ON system.worker_heartbeats (last_heartbeat_at);
