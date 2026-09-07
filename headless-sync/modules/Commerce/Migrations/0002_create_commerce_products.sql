-- Migration: 0002_create_commerce_products
-- Authority: DECISION AG — AG-7 (soft references, no cross-aggregate FK), AG-8 (inventory owns
--            stock), AG-10 (media references only), AG-11 (column canon; semantic columns do not
--            clone), AG-13 (simple + variable only), Requirement C (money), Requirement C' (no
--            per-product currency). Doc 3 §13 as amended by the §13–18 banner.
--
-- Column canon (OPEN-3/4/5/7): every timestamp TIMESTAMPTZ, checksum VARCHAR(64) sha256,
-- identity UUID.
--
-- DELIBERATELY ABSENT, each for a stated reason:
--
--   stock_status / stock quantity — commerce.inventory owns stock (AG-8). Doc 3 §13 placed
--     stock_status here AND on commerce.inventory, which would give one WordPress fact two
--     projections, two checksums and two independent write-suppress decisions that can disagree,
--     with no rule for which wins. Product reads JOIN inventory at read time instead (P2-S6).
--
--   currency — store-level configuration, not a per-product fact (Requirement C'). Verified
--     against WooCommerce 11.1.0: get_woocommerce_currency() reads a single option.
--
--   permalink / path / uri — no derived URL column. The permalink base is configurable
--     (woocommerce_permalinks option), so a stored path would go stale on a settings change with
--     no event to repair it. Raised as FLAG-COMMPERMA-1; no URL field ships in Phase 2.
--
--   FOREIGN KEYS — none to content.media and none (later) to commerce.inventory or
--     commerce.product_variations. Under at-least-once, non-FIFO delivery a child may legitimately
--     project before its parent, and an FK would turn valid out-of-order synchronisation into a
--     DLQ failure (AG-7). References are soft, exactly as content.posts.featured_media_id is.

CREATE TABLE IF NOT EXISTS commerce.products (
    id                 UUID          NOT NULL,
    source_product_id  BIGINT        NOT NULL,

    sku                VARCHAR(255)  NULL,
    slug               VARCHAR(255)  NOT NULL,
    name               TEXT          NOT NULL,
    description        TEXT          NULL,
    short_description  TEXT          NULL,

    -- WordPress post status. NOT the catalog membership signal on its own — see below.
    status             VARCHAR(50)   NOT NULL,

    -- 'simple' | 'variable' (AG-13). Unsupported WooCommerce types are never projected here.
    product_type       VARCHAR(50)   NOT NULL,

    -- WooCommerce catalog visibility, verified against WC 11.1.0 read_visibility():
    -- 'visible' | 'catalog' | 'search' | 'hidden', derived from the product_visibility taxonomy.
    -- A published product can still be excluded from the catalog, so listings filter on THIS,
    -- not on status alone (Requirement B).
    catalog_visibility VARCHAR(20)   NOT NULL DEFAULT 'visible',
    featured           BOOLEAN       NOT NULL DEFAULT FALSE,

    -- Money: NUMERIC with no imposed precision or scale (Requirement C). WooCommerce store
    -- decimal configuration is not guaranteed to be two places, so narrowing the source value
    -- here would lose information. Exactness is enforced at the application boundary, which
    -- normalises to a deterministic decimal STRING before the checksum is computed.
    price              NUMERIC       NULL,
    regular_price      NUMERIC       NULL,
    sale_price         NUMERIC       NULL,

    -- Soft references to WordPress attachment ids (AG-10). content.media remains the single
    -- attachment projection; Commerce stores references and never duplicates the projection.
    -- 0 means "none set", matching the content.posts.featured_media_id convention.
    featured_media_id  BIGINT        NOT NULL DEFAULT 0,
    gallery_media_ids  JSONB         NOT NULL DEFAULT '[]',

    published_at       TIMESTAMPTZ   NULL,
    created_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    updated_at         TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    synced_at          TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
    deleted_at         TIMESTAMPTZ   NULL,

    checksum           VARCHAR(64)   NOT NULL,
    meta_jsonb         JSONB         NOT NULL DEFAULT '{}',

    CONSTRAINT pk_commerce_products             PRIMARY KEY (id),
    CONSTRAINT uq_commerce_products_source_id   UNIQUE (source_product_id)
);

-- Listing predicate and sort, matched exactly: the catalog listing is
-- (deleted_at IS NULL) ORDER BY published_at DESC, id DESC. A partial index keeps
-- soft-deleted rows out of the index entirely.
CREATE INDEX IF NOT EXISTS idx_commerce_products_live_published_at
    ON commerce.products (published_at DESC, id DESC)
    WHERE deleted_at IS NULL;

-- Single-item lookup by slug.
CREATE INDEX IF NOT EXISTS idx_commerce_products_live_slug
    ON commerce.products (slug)
    WHERE deleted_at IS NULL;

-- SKU lookup — a first-class commerce access path, and the filter P2-S5 variations reuse.
CREATE INDEX IF NOT EXISTS idx_commerce_products_live_sku
    ON commerce.products (sku)
    WHERE deleted_at IS NULL AND sku IS NOT NULL;

-- Catalog-visibility filtering rides the listing (Requirement B), so it is indexed with the
-- sort rather than alone.
CREATE INDEX IF NOT EXISTS idx_commerce_products_visibility
    ON commerce.products (catalog_visibility, published_at DESC, id DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_commerce_products_updated_at
    ON commerce.products (updated_at);

CREATE INDEX IF NOT EXISTS idx_commerce_products_meta
    ON commerce.products USING GIN (meta_jsonb);
