-- Migration: 0004_create_commerce_entity_taxonomies
-- Authority: DECISION AG AG-9; the P1A-S4 architect ruling on entity↔term join tables, upheld
--            verbatim by DECISION AA.
--
-- The generic entity↔term join for the Commerce domain: which products carry which terms,
-- across every Commerce taxonomy.
--
-- A PURE JOIN TABLE — composite primary key and nothing else. No timestamps, no checksum, no
-- metadata, no surrogate id. That shape is the frozen P1A-S4 ruling, upheld rather than amended
-- by DECISION AA, and it is why AG-11 says semantic columns must not be cloned: a join table
-- has no independent state to checksum and is never independently tombstoned, so adding those
-- columns for symmetry with the entity tables would be decoration paid for on every write.
--
-- Membership is rewritten wholesale by the owning entity's adapter, which is why the join needs
-- no soft-delete column: a removed relationship is a row that is no longer inserted.
--
-- NO FOREIGN KEYS (AG-7). Both sides may legitimately project after the join row under
-- at-least-once, non-FIFO delivery — a product's terms can arrive before the term itself — and
-- an FK would turn that valid ordering into a DLQ failure.

CREATE TABLE IF NOT EXISTS commerce.entity_taxonomies (
    entity_id   UUID NOT NULL,
    taxonomy_id UUID NOT NULL,

    CONSTRAINT pk_commerce_entity_taxonomies PRIMARY KEY (entity_id, taxonomy_id)
);

-- The primary key already serves entity → terms. This covers the reverse direction, term →
-- entities, which is what a category-filtered product listing drives from; including entity_id
-- keeps that direction index-only rather than forcing a heap lookup per row.
CREATE INDEX IF NOT EXISTS idx_commerce_entity_taxonomies_taxonomy_entity
    ON commerce.entity_taxonomies (taxonomy_id, entity_id);
