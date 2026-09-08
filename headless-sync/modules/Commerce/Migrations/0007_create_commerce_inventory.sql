-- Migration: 0007_create_commerce_inventory
-- Authority: DECISION AG — AG-8 (inventory owns stock; no stock_status on the product row),
--            AG-14 (inventory ownership is AGGREGATE-AWARE), AG-7 (soft references, no
--            cross-aggregate FK), AG-11 (column canon). Doc 3 §15 as amended by the §13-18
--            banner: its Product-only inventory relationship is superseded.
--
-- Verified against WooCommerce 11.1.0 — see docs/notes/WOOCOMMERCE-SOURCE-VERIFICATION.md §10.
--
-- ONE ROW PER STOCK OWNER, and WooCommerce decides who that is. The preflight found that the
-- source already models this: WC_Product::get_stock_managed_by_id() returns the id of whatever
-- entity actually holds this entity's stock — its own id for a simple product or a variable
-- parent, and the PARENT's id for a variation whose manage_stock is the string 'parent'. So:
--
--     an entity is an inventory owner IFF get_stock_managed_by_id() returns its own id
--
-- A parent-managed variation is therefore NOT an owner and gets NO ROW. That is AG-14's rule
-- stated as a constraint rather than as a convention: symmetry would invent a second copy of one
-- stock fact, and two copies with two checksums and two independent write-suppress decisions can
-- disagree with no rule for which wins. Reading such a variation's stock resolves to its parent's
-- row at READ time — the same resolution WC_Product_Variation::get_stock_quantity() performs,
-- done in SQL rather than stored twice (AG-8).
--
-- NOT two tables. Doc 3's shape and the obvious alternative — commerce.product_inventory plus
-- commerce.variation_inventory — are both rejected by AG-14: every read that wanted "the stock
-- for this thing" would have to know which table to look in before it could ask.
--
-- NO FOREIGN KEY to commerce.products or commerce.product_variations (AG-7). An inventory event
-- may legitimately be processed before the entity it describes, and an FK would turn valid
-- out-of-order synchronisation into a DLQ failure.

CREATE TABLE IF NOT EXISTS commerce.inventory (
    id            UUID         NOT NULL,

    -- WHICH KIND of Commerce aggregate owns this stock: 'product' or 'product_variation'.
    owner_type    VARCHAR(50)  NOT NULL,

    -- The owner's WORDPRESS id — a soft reference, deliberately not a projection UUID, for the
    -- reason migration 0004 records at length: the owner may project after its inventory, and a
    -- UUID reference would match nothing with no way to repair it afterwards.
    owner_id      BIGINT       NOT NULL,

    -- The EFFECTIVE answer to "does this owner track a quantity", not the raw per-product flag.
    -- Verified: WC_Product::managing_stock() returns false for every product when the store
    -- option woocommerce_manage_stock is off, so the per-product value alone would be wrong on
    -- any store that has stock management disabled globally.
    manages_stock BOOLEAN      NOT NULL DEFAULT FALSE,

    -- NULL when no quantity is tracked — which is NOT zero. A store selling made-to-order goods
    -- has unlimited stock, and publishing 0 there would read as sold out.
    stock_quantity INTEGER     NULL,

    -- 'instock' | 'outofstock' | 'onbackorder'. Independent of manages_stock: a product that
    -- tracks no quantity still has a status, and that status is what a catalogue filter needs.
    stock_status  VARCHAR(20)  NOT NULL DEFAULT 'instock',

    -- 'no' | 'notify' | 'yes'.
    backorders    VARCHAR(20)  NOT NULL DEFAULT 'no',

    -- The store's low-stock threshold for this owner, or NULL to use the store default.
    low_stock_amount INTEGER   NULL,

    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    synced_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    deleted_at    TIMESTAMPTZ  NULL,

    checksum      VARCHAR(64)  NOT NULL,

    CONSTRAINT pk_commerce_inventory PRIMARY KEY (id),

    -- One row per owner. Unlike the slug indexes elsewhere in this schema, this UNIQUE is safe
    -- and necessary: it is not a guess about source uniqueness, it is the table's own identity —
    -- (owner_type, owner_id) is what every read and every upsert addresses a row by, and a
    -- duplicate would mean one stock fact with two projections, which is the exact defect AG-8
    -- removed from commerce.products.
    CONSTRAINT uq_commerce_inventory_owner UNIQUE (owner_type, owner_id)
);

-- Stock filtering on a product listing: find the owners in a given status, then join back.
-- Partial on deleted_at because every delivery read excludes tombstones.
CREATE INDEX IF NOT EXISTS idx_commerce_inventory_status
    ON commerce.inventory (stock_status, owner_type, owner_id)
    WHERE deleted_at IS NULL;
