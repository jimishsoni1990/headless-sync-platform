-- Migration: 0001_create_commerce_schema
-- Authority: DECISION AG (Phase 2 ratification); Doc 3 §12 as amended by the §13–18 banner.
--
-- The Commerce domain's delivery schema. Mirrors the content schema's shape: one PostgreSQL
-- schema per owning domain, so a module's projections are namespaced and a second module
-- cannot collide with the first.

CREATE SCHEMA IF NOT EXISTS commerce;
