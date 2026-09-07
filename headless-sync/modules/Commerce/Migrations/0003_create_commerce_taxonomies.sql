-- Migration: 0003_create_commerce_taxonomies
-- Authority: DECISION AG AG-9 (one shared taxonomy projection per owning domain, discriminated
--            by taxonomy_type) extending DECISION AA; AG-11 (column canon; semantic columns do
--            not clone); Doc 3 §16-17 as amended by the §13-18 banner.
--
-- ONE table for every Commerce taxonomy TERM, told apart by taxonomy_type:
--
--     product_cat        product categories        (P2-S3)
--     pa_<slug>          global attribute terms    (P2-S4)
--
-- Doc 3's separate commerce.categories and commerce.attribute_terms tables are superseded:
-- both hold taxonomy terms, and a table per taxonomy would mean a migration, a table, adapter
-- and query-provider logic per taxonomy — and, because taxonomy_id would no longer say which
-- table it points into, either a join table per taxonomy or a discriminator on the join,
-- reopening the frozen P1A-S4 ruling one level down. DECISION AA weighed exactly this and
-- chose the shared table.
--
-- NO UNIQUE (taxonomy_type, slug) — deliberately, and verified rather than assumed.
-- wp_unique_term_slug() enforces slug uniqueness PER TAXONOMY at WRITE time, so duplicates do
-- not normally arise (a repeated leaf under another parent becomes 'shirts-men', then
-- 'shirts-2'). But that guarantee is defeasible: the wp_unique_term_slug_is_bad_slug filter
-- lets a plugin override it, direct database inserts bypass it, and imported or legacy terms
-- may predate it. A UNIQUE constraint would convert such an unusual-but-valid source state
-- into a projection failure that dead-letters — the same harm AG-7 rejected for foreign keys.
-- A lookup index gives the read performance without that failure mode, which is what
-- DECISION AA concluded for content.taxonomies too.
--
-- parent_id is a SOFT reference (AG-7): a child term may legitimately project before its
-- parent under at-least-once, non-FIFO delivery, and an FK would turn that into a DLQ failure.

CREATE TABLE IF NOT EXISTS commerce.taxonomies (
    id             UUID         NOT NULL,

    -- Globally unique across every Commerce taxonomy: WordPress term ids are unique across
    -- taxonomies, which is what makes an unscoped lookup by source id safe.
    source_term_id BIGINT       NOT NULL,

    -- The discriminator. Every read MUST constrain this or use source_term_id — an unscoped
    -- read of a shared taxonomy table is the DECISION AA defect class, which shipped three
    -- times in Phase 1B before a guard test was added.
    taxonomy_type  VARCHAR(50)  NOT NULL,

    slug           VARCHAR(255) NOT NULL,
    name           VARCHAR(255) NOT NULL,
    description    TEXT         NULL,

    -- WordPress parent TERM id (0 = top level). Soft reference, no FK.
    parent_id      BIGINT       NOT NULL DEFAULT 0,
    term_count     INTEGER      NOT NULL DEFAULT 0,

    deleted_at     TIMESTAMPTZ  NULL,
    checksum       VARCHAR(64)  NOT NULL,
    created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    synced_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT pk_commerce_taxonomies           PRIMARY KEY (id),
    CONSTRAINT uq_commerce_taxonomies_source_id UNIQUE (source_term_id)
);

-- The shape every read actually uses: a slug lookup ALWAYS pairs with the taxonomy type, so
-- the composite serves both together. A bare (slug) index would never be used, and a bare
-- (taxonomy_type) index is a strict prefix of this one — the two redundancies DECISION AA had
-- to remove from content.taxonomies in migration 0008. They are simply not created here.
CREATE INDEX IF NOT EXISTS idx_commerce_taxonomies_type_slug
    ON commerce.taxonomies (taxonomy_type, slug)
    WHERE deleted_at IS NULL;

-- Listing a taxonomy in name order.
CREATE INDEX IF NOT EXISTS idx_commerce_taxonomies_type_name
    ON commerce.taxonomies (taxonomy_type, name, id)
    WHERE deleted_at IS NULL;

-- NOT created: (taxonomy_type, parent_id). No delivery query filters, orders or joins terms by
-- parent today — the tree is reconstructed by the consumer from the projected parent_id — so
-- it would be an index nothing reads, paid for on every write. It ships with the first
-- endpoint that walks the tree. DECISION AA made the same call and flagged it rather than
-- creating it silently.
