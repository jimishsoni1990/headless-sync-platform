-- Migration: 0001_create_hsp_outbox
-- Authority: ARCHITECTURE_DECISIONS.md v1.3 — OPEN-6 (v1.3) frozen DDL, OPEN-3 (v1.2) type canon
-- Timestamps: DATETIME UTC (MySQL; TIMESTAMPTZ is a PostgreSQL type — v1.2 canon)
-- Checksum:   CHAR(64) (sha256, fixed width)
-- id:         CHAR(36) — the event_id, born here; preserved unchanged on relay to system.events
-- status:     ENUM('pending','relayed') — relay claim path
--
-- Relay fidelity (RelayWorkerStrategy):
--   system.events.id          := wp_hsp_outbox.id          (event_id preserved — do NOT regenerate)
--   system.events.created_at  := wp_hsp_outbox.created_at  (capture time, not relay time)
--   wp_hsp_outbox.relayed_at   set ONLY after PostgreSQL commit succeeds
--
-- source_updated_at: UTC timestamp of the WordPress entity's last edit; populates
--   system.events.source_updated_at (required by OPEN-5 as a first-class column).
-- causation_id: NULL for root events (Doc 8 §19–20).

CREATE TABLE IF NOT EXISTS `{prefix}hsp_outbox` (
    `id`                CHAR(36)                          NOT NULL,
    `event_type`        VARCHAR(255)                      NOT NULL COMMENT 'Fully-qualified <domain>.<aggregate>.<action> — OPEN-1',
    `event_version`     INT                               NOT NULL,
    `aggregate_type`    VARCHAR(100)                      NOT NULL,
    `aggregate_id`      VARCHAR(255)                      NOT NULL,
    `aggregate_version` BIGINT                            NOT NULL,
    `source_updated_at` DATETIME                          NOT NULL COMMENT 'UTC; WordPress entity last-edit time — OPEN-5/OPEN-6 v1.3',
    `checksum`          CHAR(64)                          NOT NULL COMMENT 'sha256; traceability only — DECISION 3',
    `correlation_id`    CHAR(36)                          NOT NULL,
    `causation_id`      CHAR(36)                          NULL     COMMENT 'NULL for root events — Doc 8 §19-20',
    `payload`           JSON                              NOT NULL,
    `status`            ENUM('pending', 'relayed')        NOT NULL DEFAULT 'pending',
    `created_at`        DATETIME                          NOT NULL COMMENT 'UTC; capture time — preserved unchanged on relay',
    `relayed_at`        DATETIME                          NULL     COMMENT 'UTC; set after PG commit succeeds — OPEN-6',
    PRIMARY KEY (`id`),
    INDEX `idx_relay_claim` (`status`, `created_at`) COMMENT 'Relay claim path — OPEN-6 v1.3'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
