-- Migration: 0008_align_content_taxonomy_indexes
-- Authority: ARCHITECTURE_DECISIONS.md — DECISION AA (FLAG-TAXSCHEMA-1 ruling, 2026-09-06):
--            one SHARED taxonomy projection per owning domain, told apart by taxonomy_type;
--            "design indexes around the actual delivery query paths".
--
-- No table, column, constraint or row is touched — this migration only reshapes indexes to
-- match the query rule that every taxonomy read carries BOTH the taxonomy type and the term
-- identity.
--
-- content.taxonomies
--   idx_content_taxonomies_slug (slug)          — no query looks a term up by slug alone; every
--                                                 slug lookup (CategoryQueryProvider::findBySlug,
--                                                 PostQueryProvider's ?category=/?tag= filters)
--                                                 pairs it with taxonomy_type.
--   idx_content_taxonomies_type (taxonomy_type) — a strict PREFIX of the composite below, so the
--                                                 composite serves the listing scans too.
--   Both are replaced by ONE composite (taxonomy_type, slug).
--
-- content.entity_taxonomies
--   The PK (entity_id, taxonomy_id) already covers the forward direction ("this entity's terms").
--   The reverse direction ("this term's entities") had a single-column index, which must visit the
--   heap for every candidate row just to read entity_id. Widening it to (taxonomy_id, entity_id)
--   makes BOTH directions index-only — the two orderings DECISION AA names.
--
-- Deliberately NOT created: (taxonomy_type, parent_id). DECISION AA lists it among the expected
-- query paths, but no delivery query filters, orders or joins taxonomies by parent today — the
-- column is projected and served, never predicated on. Adding it now would be an index nothing
-- reads, paid for on every write. It ships with the first endpoint that walks the term tree.

CREATE INDEX IF NOT EXISTS idx_content_taxonomies_type_slug
    ON content.taxonomies (taxonomy_type, slug);

DROP INDEX IF EXISTS content.idx_content_taxonomies_slug;
DROP INDEX IF EXISTS content.idx_content_taxonomies_type;

CREATE INDEX IF NOT EXISTS idx_content_entity_taxonomies_taxonomy_entity
    ON content.entity_taxonomies (taxonomy_id, entity_id);

DROP INDEX IF EXISTS content.idx_content_entity_taxonomies_taxonomy_id;
