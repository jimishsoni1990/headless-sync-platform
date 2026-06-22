-- Migration: 0001_create_system_schema
-- Authority: ARCHITECTURE_DECISIONS.md v1.3 — Doc 3 §4
-- Creates the system schema. Must run before all system.* table migrations.

CREATE SCHEMA IF NOT EXISTS system;
