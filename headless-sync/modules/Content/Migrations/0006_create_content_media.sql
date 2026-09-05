-- Migration: 0006_create_content_media
-- Authority: ARCHITECTURE_DECISIONS.md — OPEN-3 (v1.2) type canon; IMPLEMENTATION_PLAN.md §5b
--            P1B-S1 (Media Synchronization); ADR-013 (soft references, no FK).
--
-- OPEN-3 (v1.2): ALL timestamp columns TIMESTAMPTZ; ALL checksum columns VARCHAR(64).
-- OPEN-11 (Option A): lossless projection; the adapter stores canonical.getChecksum() directly.
--
-- Shape notes (Rule 2 — transformed delivery store, NOT a wp_posts/wp_postmeta replica):
--   url          — the resolved public URL of the original file (WordPress resolves it; the
--                  projection stores the result so the delivery path never asks WordPress).
--   sizes_jsonb  — registered size variants, each already carrying a resolved url + dimensions,
--                  so a consumer never reconstructs a filename (that would couple it to WP
--                  internals — Rule 6).
--   attached_to_id — wp_posts.post_parent, a SOFT reference (ADR-013: no FK on operational
--                  tables; replay must be able to land rows in any order).
--
-- No status column: attachments carry post_status='inherit', so the {publish} public set
-- (OPEN-10) does not apply. Membership is existence — deleted_at IS NULL — exactly as
-- categories are handled.
--
-- deleted_at: NULL = active; non-NULL = soft-deleted.
-- synced_at:  timestamp of the last successful adapter write.
-- created_at: first-sync instant (adapter insert, not the WordPress upload date).

CREATE TABLE IF NOT EXISTS content.media (
    id              UUID         NOT NULL,
    source_post_id  BIGINT       NOT NULL,
    slug            VARCHAR(255) NOT NULL,
    title           TEXT         NOT NULL,
    mime_type       VARCHAR(255) NOT NULL,
    url             TEXT         NOT NULL,
    alt_text        TEXT         NOT NULL DEFAULT '',
    caption         TEXT         NOT NULL DEFAULT '',
    description     TEXT         NOT NULL DEFAULT '',
    width           INTEGER      NOT NULL DEFAULT 0,
    height          INTEGER      NOT NULL DEFAULT 0,
    sizes_jsonb     JSONB        NOT NULL DEFAULT '{}',
    attached_to_id  BIGINT       NOT NULL DEFAULT 0,
    published_at    TIMESTAMPTZ  NOT NULL,
    updated_at      TIMESTAMPTZ  NOT NULL,
    deleted_at      TIMESTAMPTZ  NULL,
    checksum        VARCHAR(64)  NOT NULL,
    meta_jsonb      JSONB        NOT NULL DEFAULT '{}',
    created_at      TIMESTAMPTZ  NOT NULL,
    synced_at       TIMESTAMPTZ  NOT NULL,

    CONSTRAINT pk_content_media PRIMARY KEY (id),
    CONSTRAINT uq_content_media_source_post_id UNIQUE (source_post_id)
);

-- Listing: the query provider orders by (published_at DESC, id DESC) over live rows only.
-- A PARTIAL composite index matching that predicate and sort keeps the listing index-backed
-- at the 100,000+ record target instead of degrading to a sequential scan (PRD §Scalability).
CREATE INDEX IF NOT EXISTS idx_content_media_live_published_at
    ON content.media (published_at DESC, id DESC)
    WHERE deleted_at IS NULL;

-- Single-item lookup by slug, live rows only.
CREATE INDEX IF NOT EXISTS idx_content_media_live_slug
    ON content.media (slug)
    WHERE deleted_at IS NULL;

-- Reconciliation and incremental consumers read by last-modified.
CREATE INDEX IF NOT EXISTS idx_content_media_updated_at
    ON content.media (updated_at);

-- JSONB field search, mirroring content.posts / content.pages.
CREATE INDEX IF NOT EXISTS idx_content_media_meta_jsonb
    ON content.media USING GIN (meta_jsonb);
