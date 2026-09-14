-- Migration: 0009_align_content_entity_taxonomies_to_source_term_id
-- Authority: DECISION AJ (ARCHITECTURE_DECISIONS.md v1.42, 2026-09-14) — Content taxonomy
--            relationships key on the source term identity. Resolves Finding 004.
--            AMENDS the frozen FLAG-P1AS4-1 shape (v1.8) and DECISION AA's "Explicitly
--            unchanged" clause (v1.33), which this migration would otherwise contradict.
--
-- THIS IS AN ARCHITECTURE AMENDMENT, NOT A CORRECTION TO A MISTAKE.
--
-- FLAG-P1AS4-1 (2026-06-23) deliberately froze this table as (entity_id UUID, taxonomy_id UUID),
-- and DECISION AA upheld that shape verbatim. The shape was a considered ruling; what it carried,
-- unexamined, was the assumption that a term's projection row would exist by the time a post's
-- relationship was written. Production disproved the assumption, not the reasoning.
--
-- WHAT WENT WRONG. Under at-least-once, non-FIFO delivery a post routinely projects before its
-- terms. On the installation that surfaced this, EVERY post projected before EVERY category:
-- content.post.updated at 07:12:06 and content.category.updated at 07:12:06-07; posts materialised
-- 07:12:09-10, categories 07:12:10. PostAdapter resolved each source term id to a
-- content.taxonomies.id, found nothing, and silently wrote no relationship at all — the documented
-- expectation being that the link would appear "when the category syncs". Nothing implements that:
-- PostAdapter is the only writer of this table and there is no taxonomy-side relationship writer.
--
-- WHY IT WAS PERMANENT, not merely late. A post's category ids are inside its canonical checksum,
-- so once WordPress membership settles the recomputed checksum equals the stored one, DECISION 3
-- correctly suppresses the write, and the relationship rewrite never runs again. Reconciliation
-- compares that same checksum and modified-at, so drift detection saw nothing either. Delivery
-- served post_count 6 from /hsp/v1/categories/etf beside an empty /hsp/v1/posts?category=etf, with
-- ZERO rows in this table platform-wide and no error logged anywhere.
--
-- THE AMENDED IDENTITY. Keyed by source_term_id, a relationship row is a pure function of the
-- OWNING POST's own state: complete the moment the post projects, in any arrival order, and never
-- dependent on content.taxonomies.id having materialised. The full-replace rewrite on every post
-- write is therefore always correct, and the term is joined at read time on source_term_id, which
-- carries uq_content_taxonomies_source_term_id and is globally unique across WordPress taxonomies.
--
--     post event first      -> relationship persisted
--     taxonomy event first  -> relationship persisted
--     ordering changes      -> identical final projection
--     replay                -> identical final projection
--
-- COMMERCE IS SUPPORTING PRECEDENT, NOT RETROACTIVE AUTHORITY. commerce.entity_taxonomies reached
-- this shape first under DECISION AG (AG-7), and 0004_create_commerce_entity_taxonomies recorded in
-- its own header that it "deliberately differs from content.entity_taxonomies, which stores the
-- projection UUID" — an accurate statement of the divergence, and corroborating evidence that the
-- source-keyed design is the one consistent with HSP's convergence guarantees (on the affected
-- installation the Commerce table was fully populated from the same backfill in the same ordering
-- while this one was empty). AG-7 did NOT silently amend Content. Content's UUID relationship
-- identity remained frozen until DECISION AJ.
--
-- STILL A PURE RELATIONSHIP TABLE (FLAG-P1AS4-1 upheld on everything but the identity column,
-- AG-11 on semantic columns not cloning): composite primary key and nothing else. No timestamps,
-- no checksum, no relationship metadata, no surrogate id — a relationship row has no independent
-- state to checksum and is never independently tombstoned. NO FOREIGN KEYS (ADR-013): either side
-- may legitimately arrive later, and the database must not turn valid out-of-order synchronization
-- into a DLQ failure. Category/tag discrimination is unaffected and remains mandatory: WordPress
-- term ids are globally unique across taxonomies, and every read still constrains
-- content.taxonomies.taxonomy_type (DECISION AA).
--
-- EXISTING LINKS ARE PRESERVED, NOT REBUILT. Rows whose UUID still resolves through
-- content.taxonomies are translated in place. Rows pointing at a taxonomy row that no longer
-- exists are dangling by definition and are removed — a source identity is never manufactured for
-- them. No projection row is edited and no membership is invented here: an installation missing
-- relationships converges through the normal event pipeline, because PostAdapter's suppress
-- predicate now compares the persisted relationship SET against the canonical one (AJ-1), so a
-- replay of an affected post detects the wrong relationship state and rewrites it.
--
-- ONE AUTHORITATIVE IDENTITY when this completes: taxonomy_id and its index are dropped in the
-- same migration. No dual-key transitional state is retained.

ALTER TABLE content.entity_taxonomies
    ADD COLUMN IF NOT EXISTS source_term_id BIGINT;

UPDATE content.entity_taxonomies et
   SET source_term_id = t.source_term_id
  FROM content.taxonomies t
 WHERE t.id = et.taxonomy_id
   AND et.source_term_id IS NULL;

DELETE FROM content.entity_taxonomies
 WHERE source_term_id IS NULL;

ALTER TABLE content.entity_taxonomies
    DROP CONSTRAINT IF EXISTS pk_content_entity_taxonomies;

ALTER TABLE content.entity_taxonomies
    DROP COLUMN IF EXISTS taxonomy_id;

ALTER TABLE content.entity_taxonomies
    ALTER COLUMN source_term_id SET NOT NULL;

ALTER TABLE content.entity_taxonomies
    ADD CONSTRAINT pk_content_entity_taxonomies PRIMARY KEY (entity_id, source_term_id);

-- The PK already serves entity -> terms, and is what the adapter's relationship-set read rides.
-- This is the reverse direction, term -> entities, which a category- or tag-filtered listing
-- drives from; including entity_id keeps it index-only rather than forcing a heap lookup per
-- candidate row. Replaces (taxonomy_id, entity_id) from migration 0008, which served exactly the
-- same two access paths — one index out, one index in.
CREATE INDEX IF NOT EXISTS idx_content_entity_taxonomies_term_entity
    ON content.entity_taxonomies (source_term_id, entity_id);

DROP INDEX IF EXISTS content.idx_content_entity_taxonomies_taxonomy_entity;
