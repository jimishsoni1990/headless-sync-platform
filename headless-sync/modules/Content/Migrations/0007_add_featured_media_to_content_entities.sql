-- Migration: 0007_add_featured_media_to_content_entities
-- Authority: IMPLEMENTATION_PLAN.md §5b P1B-S2 (Featured Images); ADR-013 (soft references,
--            no FK on operational tables); OPEN-3 (v1.2) type canon.
--
-- Adds the featured-image reference to both entity projections.
--
-- SOFT reference (ADR-013): featured_media_id holds the WordPress attachment ID and carries
-- NO foreign key to content.media. Operational tables must tolerate replay in any order — a
-- post can be projected before its attachment, and a deleted attachment must not cascade into
-- the post. Resolution happens at read time via a LEFT JOIN, so a missing or soft-deleted
-- media row simply yields a null featured image rather than a dangling reference.
--
-- 0 = no featured image, matching the existing parent_id / attached_to_id convention in this
-- schema (WordPress post IDs start at 1, so 0 is unambiguous and avoids a nullable column
-- that every reader would have to special-case).
--
-- No index: the join drives from the entity row to content.media by source_post_id, which is
-- already UNIQUE-indexed (uq_content_media_source_post_id). An index on featured_media_id
-- would only help the reverse lookup ("which posts use this image"), which no endpoint serves.

ALTER TABLE content.posts
    ADD COLUMN IF NOT EXISTS featured_media_id BIGINT NOT NULL DEFAULT 0;

ALTER TABLE content.pages
    ADD COLUMN IF NOT EXISTS featured_media_id BIGINT NOT NULL DEFAULT 0;
