-- Migration: 0002_create_hsp_aggregate_counters
-- Authority: ARCHITECTURE_DECISIONS.md v1.3 — DECISION 2 (v1.1)
-- Supersedes: postmeta/termmeta _hsp_version storage (DECISION 2 v1.0 — removed)
--
-- Purpose: per-aggregate monotonic version counter, atomically incremented in one round-trip.
-- No timestamp columns (not required by any ruling).
--
-- Atomic increment + read pattern (DECISION 2 v1.1):
--
--   INSERT INTO `{prefix}hsp_aggregate_counters`
--       (`aggregate_type`, `aggregate_id`, `version`)
--   VALUES (?, ?, 1)
--   ON DUPLICATE KEY UPDATE `version` = LAST_INSERT_ID(`version` + 1);
--   -- then: SELECT LAST_INSERT_ID();
--
-- The returned value is the `aggregate_version` written to the outbox row and relayed into
-- system.events. Application-layer read-modify-write is PROHIBITED — it cannot guarantee
-- uniqueness under concurrent saves (DECISION 2 rationale).

CREATE TABLE IF NOT EXISTS `{prefix}hsp_aggregate_counters` (
    `aggregate_type` VARCHAR(100) NOT NULL,
    `aggregate_id`   VARCHAR(255) NOT NULL,
    `version`        BIGINT       NOT NULL,
    PRIMARY KEY (`aggregate_type`, `aggregate_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
