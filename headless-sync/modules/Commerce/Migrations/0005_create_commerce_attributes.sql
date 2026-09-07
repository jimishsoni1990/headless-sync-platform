-- Migration: 0005_create_commerce_attributes
-- Authority: DECISION AG AG-9 (global attribute DEFINITIONS are not taxonomy terms and keep
--            their own projection), AG-11 (column canon; semantic columns do not clone).
--
-- WooCommerce global attribute definitions. Verified against 11.1.0: they live in the custom
-- table {$wpdb->prefix}woocommerce_attribute_taxonomies — neither posts nor terms — and carry
-- domain semantics a term does not have: a display label, a value type, an ordering rule, and
-- whether the taxonomy has public archives. That is why AG-9 keeps this table while superseding
-- Doc 3's commerce.attribute_terms, whose rows ARE terms and belong in commerce.taxonomies.
--
--     commerce.attributes         defines  pa_colour, pa_size, …
--     commerce.taxonomies         holds    the terms of those taxonomies
--     commerce.entity_taxonomies  links    products to those terms
--
-- `slug` stores the FULL taxonomy name including the `pa_` prefix, exactly as
-- wc_get_attribute() reports it — so it joins directly against
-- commerce.taxonomies.taxonomy_type with no string surgery at read time.
--
-- No FK to commerce.taxonomies (AG-7): terms and definitions project independently and either
-- may arrive first.

CREATE TABLE IF NOT EXISTS commerce.attributes (
    id                   UUID         NOT NULL,
    source_attribute_id  BIGINT       NOT NULL,

    -- Full taxonomy name, e.g. 'pa_colour'.
    slug                 VARCHAR(255) NOT NULL,

    -- Human label, e.g. 'Colour'.
    name                 VARCHAR(255) NOT NULL,

    -- WooCommerce attribute type: 'select', 'text', …
    type                 VARCHAR(50)  NOT NULL DEFAULT 'select',

    -- Term ordering rule: 'menu_order', 'name', 'name_num', 'id'.
    order_by             VARCHAR(50)  NOT NULL DEFAULT 'menu_order',

    -- Whether WooCommerce publishes archive pages for this attribute's taxonomy.
    has_archives         BOOLEAN      NOT NULL DEFAULT FALSE,

    deleted_at           TIMESTAMPTZ  NULL,
    checksum             VARCHAR(64)  NOT NULL,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    synced_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CONSTRAINT pk_commerce_attributes            PRIMARY KEY (id),
    CONSTRAINT uq_commerce_attributes_source_id  UNIQUE (source_attribute_id)
);

-- Lookup by taxonomy name — how a consumer goes from a term's taxonomy_type back to the
-- attribute that defines it. NOT unique: WooCommerce enforces slug uniqueness at write time,
-- and the same reasoning as migration 0003 applies — a constraint here would turn an unusual
-- but valid source state into a projection failure that dead-letters (AG-7).
CREATE INDEX IF NOT EXISTS idx_commerce_attributes_slug
    ON commerce.attributes (slug)
    WHERE deleted_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_commerce_attributes_name
    ON commerce.attributes (name, id)
    WHERE deleted_at IS NULL;
