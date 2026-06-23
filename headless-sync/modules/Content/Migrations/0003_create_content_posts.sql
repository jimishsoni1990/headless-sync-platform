-- Migration: 0003_create_content_posts
-- Authority: ARCHITECTURE_DECISIONS.md v1.2 — OPEN-3 (v1.2) type canon
-- IMPLEMENTATION_PLAN.md Phase 1A deliverables (content.posts shape)
--
-- Same shape as content.pages plus:
--   excerpt TEXT  — post excerpt field absent on pages
--
-- OPEN-3 (v1.2): ALL timestamp columns TIMESTAMPTZ; ALL checksum columns VARCHAR(64).
-- OPEN-11 (Option A): lossless projection; adapter stores canonical.getChecksum() directly.
-- No derived columns in Phase 1A.
--
-- deleted_at: NULL = active; non-NULL = soft-deleted.
-- synced_at:  timestamp of the last successful adapter write.
-- created_at: first-sync instant (adapter insert, not WordPress created date).

CREATE TABLE IF NOT EXISTS content.posts (
    id               UUID         NOT NULL,
    source_post_id   BIGINT       NOT NULL,
    source_entity_type VARCHAR(50) NOT NULL DEFAULT 'post',
    slug             VARCHAR(255) NOT NULL,
    title            TEXT         NOT NULL,
    content          TEXT         NOT NULL,
    excerpt          TEXT         NOT NULL,
    status           VARCHAR(50)  NOT NULL,
    author           VARCHAR(255) NOT NULL,
    published_at     TIMESTAMPTZ  NOT NULL,
    updated_at       TIMESTAMPTZ  NOT NULL,
    deleted_at       TIMESTAMPTZ  NULL,
    checksum         VARCHAR(64)  NOT NULL,
    meta_jsonb       JSONB        NOT NULL DEFAULT '{}',
    created_at       TIMESTAMPTZ  NOT NULL,
    synced_at        TIMESTAMPTZ  NOT NULL,

    CONSTRAINT pk_content_posts PRIMARY KEY (id),
    CONSTRAINT uq_content_posts_source_post_id UNIQUE (source_post_id)
);

-- Slug-based lookup (REST API single-post endpoint)
CREATE INDEX IF NOT EXISTS idx_content_posts_slug
    ON content.posts (slug);

-- Status filtering
CREATE INDEX IF NOT EXISTS idx_content_posts_status
    ON content.posts (status);

-- Time-ordered listing
CREATE INDEX IF NOT EXISTS idx_content_posts_published_at
    ON content.posts (published_at);

CREATE INDEX IF NOT EXISTS idx_content_posts_updated_at
    ON content.posts (updated_at);

-- JSONB field search
CREATE INDEX IF NOT EXISTS idx_content_posts_meta_jsonb
    ON content.posts USING GIN (meta_jsonb);
