-- Migration: 0013_add_replayed_at_to_dead_letter_jobs
-- Authority: ARCHITECTURE_DECISIONS.md v1.16 — DECISION S (Ruling 4), clause (e)
--
-- DECISION S (e): `replayed_at TIMESTAMPTZ NULL` is ABSENT from the OPEN-3 v1.1 DLQ
-- schema (verified against migration 0004, which carries only the four OPEN-3 delta
-- columns). Adding it via THIS forward migration is explicitly authorized within the
-- OPS-S1 migration scope. Migration 0004 must NOT be edited.
--
-- Semantics (DECISION S):
--   NULL      = DLQ row has not yet been replayed (default).
--   non-NULL  = the row was replayed at this instant; a second replay is rejected
--               by the `replayed_at IS NULL` guard in the single-transaction replay.
--   DLQ rows are PERMANENT audit records — never deleted. Replay stamps this column;
--   it does not remove the row.
--
-- Timestamps: TIMESTAMPTZ (bare TIMESTAMP prohibited — v1.2 canon)

ALTER TABLE system.dead_letter_jobs
    ADD COLUMN IF NOT EXISTS replayed_at TIMESTAMPTZ NULL;
