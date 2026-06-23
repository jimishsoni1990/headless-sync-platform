-- Migration: 0001_create_content_schema
-- Authority: ARCHITECTURE_DECISIONS.md v1.2 — Doc 3 §9, OPEN-3 (v1.2) type canon
-- Creates the content schema. Must run before all content.* table migrations.

CREATE SCHEMA IF NOT EXISTS content;
