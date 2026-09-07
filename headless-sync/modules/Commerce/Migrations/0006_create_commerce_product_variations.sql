-- Migration: 0006_create_commerce_product_variations
-- Authority: DECISION AG — AG-7 (soft references, no cross-aggregate FK), AG-10 (media
--            references only), AG-11 (column canon; semantic columns do not clone),
--            AG-13 (simple + variable only), Requirement C (money). Doc 3 §14 as amended by
--            the §13-18 banner.
--
-- Verified against WooCommerce 11.1.0 — see docs/notes/WOOCOMMERCE-SOURCE-VERIFICATION.md §9.
--
-- A variation is an independently synchronised aggregate, not a nested part of its product: it
-- has its own post id, its own CRUD hooks, its own price and its own lifecycle. Modelling it as
-- a column on commerce.products would make one WordPress fact reachable only by rewriting a
-- different aggregate, which is the coupling AG-7 exists to remove.

CREATE TABLE IF NOT EXISTS commerce.product_variations (
    id                   UUID          NOT NULL,
    source_variation_id  BIGINT        NOT NULL,

    -- The PARENT PRODUCT'S WORDPRESS ID, deliberately not the product projection's UUID.
    --
    -- Same reasoning migration 0004 records for terms, and the same defect if ignored: under
    -- at-least-once, non-FIFO delivery a variation routinely projects before its parent. A UUID
    -- reference would match nothing at insert time, and re-projecting the variation later could
    -- not repair it — the variation's own state has not changed, so its checksum has not moved
    -- and DECISION 3 correctly suppresses the write. Reconciliation compares those same
    -- checksums, so it would not detect the gap either. The link would simply never appear.
    --
    -- Keyed by the source id, this column is a pure function of the VARIATION's own state and is
    -- correct the moment the variation projects, in any arrival order.
    source_parent_id     BIGINT        NOT NULL,

    sku                  VARCHAR(255)  NULL,

    -- WooCommerce composes this from the parent's name plus the attribute summary; it is what a
    -- storefront shows in a variation picker.
    name                 TEXT          NOT NULL,
    description          TEXT          NULL,

    -- WordPress post status. A variation is 'publish' or 'private'; it has no catalog-visibility
    -- taxonomy of its own, because a variation is never listed in the catalog independently —
    -- Requirement B applies to the parent's listing.
    status               VARCHAR(50)   NOT NULL,

    -- Money as NUMERIC with NO imposed (precision, scale) — Requirement C. The store's decimal
    -- configuration is not guaranteed to be two places and HSP must not narrow the source value.
    -- Exactness is enforced at the application boundary: the source value is normalised to a
    -- deterministic decimal STRING before the checksum is built, so 10, 10.0 and 10.00 cannot
    -- churn the digest and re-project forever.
    price                NUMERIC       NULL,
    regular_price        NUMERIC       NULL,
    sale_price           NUMERIC       NULL,

    -- SELECTED ATTRIBUTE VALUES, as WooCommerce reports them: {"pa_colour":"blue","pa_size":""}.
    --
    -- Stored verbatim, empty values included, because an empty value means ANY rather than
    -- absent (verified: wc-product-functions.php:1208). `Blue / Any size` is one variation that
    -- answers for every size, and dropping the empty entry would make it indistinguishable from
    -- a variation with no size dimension at all.
    --
    -- This map is AUTHORITATIVE. commerce.entity_taxonomies additionally carries a link row per
    -- entry that resolves to a term — an "any" entry has none — so the two deliberately do not
    -- hold the same information, and reads that need the full selection use this column.
    attributes           JSONB         NOT NULL DEFAULT '{}',

    -- Soft reference into content.media (AG-10). content.media remains the single attachment
    -- projection; Commerce never duplicates one.
    featured_media_id    BIGINT        NOT NULL DEFAULT 0,

    -- WooCommerce orders a product's variations by menu_order, so a consumer rendering a picker
    -- in the store's own order needs it.
    menu_order           INTEGER       NOT NULL DEFAULT 0,

    created_at           TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    synced_at            TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    deleted_at           TIMESTAMPTZ   NULL,

    checksum             VARCHAR(64)   NOT NULL,

    CONSTRAINT pk_commerce_product_variations           PRIMARY KEY (id),
    CONSTRAINT uq_commerce_product_variations_source_id UNIQUE (source_variation_id)
);

-- DELIBERATELY ABSENT:
--
--   stock / stock_status — commerce.inventory owns stock (AG-8), and AG-14 makes that projection
--     aggregate-aware precisely so a variation can be an inventory owner without a second table
--     or a duplicated column here. P2-S6.
--
--   catalog_visibility / featured — see the status column above.
--
--   slug / permalink — a variation has no permalink base of its own in WooCommerce and is
--     addressed through its parent, so there is no URL to store and none to go stale.
--
--   FOREIGN KEY to commerce.products — AG-7. The parent may legitimately project after the
--     variation, and an FK would turn valid out-of-order synchronisation into a DLQ failure.

-- The dominant read: every variation of one product, in the store's own order. Partial on
-- deleted_at because every delivery read excludes tombstones, which keeps the index to live rows.
CREATE INDEX IF NOT EXISTS idx_commerce_product_variations_parent
    ON commerce.product_variations (source_parent_id, menu_order, id)
    WHERE deleted_at IS NULL;

-- SKU lookup. NOT unique: WooCommerce enforces SKU uniqueness at write time, and the same
-- reasoning as migrations 0003 and 0005 applies — a constraint here would convert an unusual but
-- real source state into a projection failure that dead-letters (AG-7).
CREATE INDEX IF NOT EXISTS idx_commerce_product_variations_sku
    ON commerce.product_variations (sku)
    WHERE deleted_at IS NULL AND sku IS NOT NULL;
