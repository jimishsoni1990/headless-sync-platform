-- Migration: 0005_create_content_entity_taxonomies
-- Authority: ARCHITECTURE_DECISIONS.md v1.2 — OPEN-3 (v1.2) type canon
-- IMPLEMENTATION_PLAN.md Phase 1A deliverables (content.entity_taxonomies shape)
--
-- Architect ruling (2026-06-23, P1A-S4): Pure join table for Phase 1A.
-- Columns: entity_id UUID, taxonomy_id UUID — composite PK only.
-- No timestamps, no checksum, no metadata unless a future ADR explicitly requires
-- relationship attributes.
--
-- entity_id references content.pages.id or content.posts.id (soft reference — ADR-013).
-- taxonomy_id references content.taxonomies.id (soft reference — ADR-013).
-- No FK constraints: operational tables require replay flexibility per ADR-013.

CREATE TABLE IF NOT EXISTS content.entity_taxonomies (
    entity_id   UUID NOT NULL,
    taxonomy_id UUID NOT NULL,

    CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
);

-- Reverse lookup: find all entities for a given taxonomy
CREATE INDEX IF NOT EXISTS idx_content_entity_taxonomies_taxonomy_id
    ON content.entity_taxonomies (taxonomy_id);
