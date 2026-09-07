-- Migration: 0004_create_commerce_entity_taxonomies
-- Authority: DECISION AG AG-7 (soft references between independently projected aggregates),
--            AG-9; the P1A-S4 architect ruling on entity↔term join tables, upheld by DECISION AA.
--
-- The generic entity↔term join for the Commerce domain: which products carry which terms,
-- across every Commerce taxonomy.
--
-- A PURE JOIN TABLE — composite primary key and nothing else. No timestamps, no checksum, no
-- metadata, no surrogate id. That shape is the frozen P1A-S4 ruling, and it is why AG-11 says
-- semantic columns must not be cloned: a join row has no independent state to checksum and is
-- never independently tombstoned.
--
-- THE TERM IS REFERENCED BY ITS SOURCE ID, NOT ITS PROJECTION UUID — and that choice is
-- load-bearing rather than cosmetic.
--
-- Referencing commerce.taxonomies.id would make the link depend on the TERM having projected
-- first. Under at-least-once, non-FIFO delivery it often has not: a product routinely projects
-- before its categories. The insert would then silently match nothing, and — this is the part
-- that makes it a real defect rather than a delay — re-projecting the product later CANNOT
-- repair it, because the product's own state has not changed, so its canonical checksum has
-- not moved and DECISION 3 correctly suppresses the write. Reconciliation compares those same
-- checksums, so it would not detect the gap either. The link would simply never appear.
--
-- Keyed by source_term_id, a link row is a pure function of the PRODUCT's own state. It is
-- complete the moment the product projects, in any arrival order, and the full-replace rewrite
-- on every product write is therefore always correct. The term row is then joined at read time
-- on source_term_id, which is globally unique across WordPress taxonomies.
--
-- This deliberately differs from content.entity_taxonomies, which stores the projection UUID.
-- Commerce owns its own domain model (AG-9), and the ordering hazard above is exactly what
-- AG-7 exists to remove.
--
-- NO FOREIGN KEYS (AG-7), for the same reason: both sides may legitimately arrive later.

CREATE TABLE IF NOT EXISTS commerce.entity_taxonomies (
    -- The owning entity's PROJECTION id. Safe as a UUID because the adapter writes this row
    -- inside the same transaction that upserts the entity — the entity always exists.
    entity_id      UUID   NOT NULL,

    -- The term's WORDPRESS id. Deliberately not the projection id; see above.
    source_term_id BIGINT NOT NULL,

    CONSTRAINT pk_commerce_entity_taxonomies PRIMARY KEY (entity_id, source_term_id)
);

-- The primary key already serves entity → terms. This covers the reverse direction, term →
-- entities, which a category-filtered product listing drives from; including entity_id keeps
-- that direction index-only rather than forcing a heap lookup per row.
CREATE INDEX IF NOT EXISTS idx_commerce_entity_taxonomies_term_entity
    ON commerce.entity_taxonomies (source_term_id, entity_id);
