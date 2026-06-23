-- Migration: 0002_create_content_pages
-- Authority: ARCHITECTURE_DECISIONS.md v1.2 — OPEN-3 (v1.2) type canon
-- IMPLEMENTATION_PLAN.md Phase 1A deliverables (content.pages shape)
--
-- OPEN-3 (v1.2): ALL timestamp columns TIMESTAMPTZ; ALL checksum columns VARCHAR(64).
-- Doc 3 §9–11 bare TIMESTAMP is superseded by OPEN-3 v1.2.
--
-- OPEN-11 (Option A): The delivery projection is a lossless reshape of the canonical model.
-- The adapter stores canonical.getChecksum() directly as the content.pages checksum.
-- No derived columns (precomputed URIs, search vectors, denormalized aggregates) in Phase 1A.
--
-- deleted_at: NULL = active; non-NULL = soft-deleted (not present in delivery responses).
-- synced_at:  timestamp of the last successful adapter write.
-- created_at: first-sync instant (adapter insert, not WordPress created date).

CREATE TABLE IF NOT EXISTS content.pages (
    id               UUID         NOT NULL,
    source_post_id   BIGINT       NOT NULL,
    source_entity_type VARCHAR(50) NOT NULL DEFAULT 'page',
    slug             VARCHAR(255) NOT NULL,
    title            TEXT         NOT NULL,
    content          TEXT         NOT NULL,
    status           VARCHAR(50)  NOT NULL,
    parent_id        BIGINT       NOT NULL DEFAULT 0,
    menu_order       INTEGER      NOT NULL DEFAULT 0,
    published_at     TIMESTAMPTZ  NOT NULL,
    updated_at       TIMESTAMPTZ  NOT NULL,
    deleted_at       TIMESTAMPTZ  NULL,
    checksum         VARCHAR(64)  NOT NULL,
    meta_jsonb       JSONB        NOT NULL DEFAULT '{}',
    created_at       TIMESTAMPTZ  NOT NULL,
    synced_at        TIMESTAMPTZ  NOT NULL,

    CONSTRAINT pk_content_pages PRIMARY KEY (id),
    CONSTRAINT uq_content_pages_source_post_id UNIQUE (source_post_id)
);

-- Slug-based lookup (REST API single-page endpoint)
CREATE INDEX IF NOT EXISTS idx_content_pages_slug
    ON content.pages (slug);

-- Status filtering (listing endpoints, soft-delete filtering)
CREATE INDEX IF NOT EXISTS idx_content_pages_status
    ON content.pages (status);

-- Time-ordered listing
CREATE INDEX IF NOT EXISTS idx_content_pages_published_at
    ON content.pages (published_at);

CREATE INDEX IF NOT EXISTS idx_content_pages_updated_at
    ON content.pages (updated_at);

-- JSONB field search
CREATE INDEX IF NOT EXISTS idx_content_pages_meta_jsonb
    ON content.pages USING GIN (meta_jsonb);
