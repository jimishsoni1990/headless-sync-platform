-- Migration: 0011_add_unique_event_id_to_queue_jobs
-- Authority: DECISION L (v1.12, 2026-06-25) — Dispatcher dedup
--
-- Adds a UNIQUE constraint on system.queue_jobs.event_id so that
-- DatabaseQueueProvider::enqueueIdempotent() can use ON CONFLICT(event_id) DO NOTHING
-- as the idempotent dispatch gate.
--
-- Rationale:
--   The Dispatcher (DispatcherWorkerStrategy) translates relayed system.events rows
--   into system.queue_jobs rows. At-least-once delivery means the same event_id may
--   be dispatched multiple times (e.g. after a Dispatcher crash mid-batch). UNIQUE
--   (event_id) + ON CONFLICT DO NOTHING eliminates duplicates at the database level.
--
--   Completed queue jobs are retained (status UPDATE, not DELETE) so the constraint
--   permanently blocks re-dispatch of already-completed events — the intended invariant.
--
-- Forward only: this is an additive DDL change. No rollback migration is defined;
-- removing the constraint requires a separate forward migration per the migration engine
-- convention.
--
-- Frozen migration 0003_create_system_queue_jobs.sql is NOT modified.

ALTER TABLE system.queue_jobs
    ADD CONSTRAINT uq_queue_jobs_event_id UNIQUE (event_id);
