-- Migration: 0004_create_content_taxonomies
-- Authority: ARCHITECTURE_DECISIONS.md v1.2 — OPEN-3 (v1.2) type canon
-- IMPLEMENTATION_PLAN.md Phase 1A deliverables (content.taxonomies shape)
--
-- OPEN-3 (v1.2): ALL timestamp columns TIMESTAMPTZ. No checksum column specified in
-- the IMPLEMENTATION_PLAN for this table (CanonicalCategory does carry a checksum
-- via getChecksum(); the adapter stores it here as the stored checksum for write-suppress).
-- OPEN-11 (Option A): lossless projection; adapter stores canonical.getChecksum() directly.
--
-- deleted_at: NULL = active; non-NULL = soft-deleted.
-- synced_at:  timestamp of the last successful adapter write.
-- created_at: first-sync instant (adapter insert, not WordPress created date).

CREATE TABLE IF NOT EXISTS content.taxonomies (
    id              UUID         NOT NULL,
    source_term_id  BIGINT       NOT NULL,
    taxonomy_type   VARCHAR(50)  NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    name            VARCHAR(255) NOT NULL,
    description     TEXT         NOT NULL DEFAULT '',
    parent_id       BIGINT       NOT NULL DEFAULT 0,
    post_count      INTEGER      NOT NULL DEFAULT 0,
    deleted_at      TIMESTAMPTZ  NULL,
    checksum        VARCHAR(64)  NOT NULL,
    created_at      TIMESTAMPTZ  NOT NULL,
    updated_at      TIMESTAMPTZ  NOT NULL,
    synced_at       TIMESTAMPTZ  NOT NULL,

    CONSTRAINT pk_content_taxonomies PRIMARY KEY (id),
    CONSTRAINT uq_content_taxonomies_source_term_id UNIQUE (source_term_id)
);

-- Slug-based lookup
CREATE INDEX IF NOT EXISTS idx_content_taxonomies_slug
    ON content.taxonomies (slug);

-- Taxonomy type filtering (categories vs future taxonomy types)
CREATE INDEX IF NOT EXISTS idx_content_taxonomies_type
    ON content.taxonomies (taxonomy_type);
