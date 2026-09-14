-- Migration: 0008_add_commerce_products_variation_selection
-- Authority: DECISION AL (variation-selection capability boundary); DECISION AG AG-9 (local and
--            custom attributes remain out of Phase 2 scope — this migration does NOT change
--            that), AG-11 (column canon; semantic columns do not clone); DECISION 3
--            (write-suppress by checksum); DECISION T/U (re-emission is the only repair path).
--
-- Verified against WooCommerce 11.1.0 — see docs/notes/WOOCOMMERCE-SOURCE-VERIFICATION.md §11.
--
-- WHY A COLUMN AND NOT A DERIVATION.
--
-- AG-9 keeps local/custom WooCommerce attributes out of Phase 2, so a variation's published
-- selection carries only its `pa_*` entries. When the dropped attribute was DISPLAY-only that
-- costs nothing. When it was variation-DEFINING, two different variations publish the same
-- selection pattern — and nothing left in the projection can tell that apart from a product that
-- genuinely varies by one dimension. The source fact is destroyed at extraction, upstream of
-- everything a delivery query could read, so it has to be carried.
--
-- This is the smallest possible carrier: ONE boolean on the table that already exists. No
-- selector matrix, no option graph, no capability table, no second projection, and no local
-- attribute data of any kind — the flag says whether the published selector is complete, and
-- deliberately says nothing about what was omitted.

ALTER TABLE commerce.products
    ADD COLUMN IF NOT EXISTS variation_selection_supported BOOLEAN NULL;

-- NO DEFAULT, DELIBERATELY — and this is the part that decides whether the migration is safe.
--
--   DEFAULT true  would declare every already-projected product safely selectable, including
--                 the ones this decision exists because of. The live hoodie would keep handing
--                 consumers the wrong variation, now with a contract promising it was right.
--
--   DEFAULT false would be safe but would also be a LIE about a fact nothing has computed yet,
--                 and it would erase the difference between "classified, unsupported" and
--                 "never classified" — the one distinction an operator needs while a catalogue
--                 converges.
--
-- So existing rows are NULL: UNKNOWN. Delivery resolves that conservatively — a variable product
-- whose capability is unknown publishes `false`, because a consumer must never be told selection
-- is safe on the strength of a value nobody has established (DECISION AL). Consumers therefore
-- never see migration state; they see "not selectable here", which is true while it is unknown.
--
-- NULL is also the permanent, correct value for a NON-VARIABLE product: the question does not
-- apply to a simple product, and the delivery contract omits the field entirely rather than
-- inventing one. The two readings never collide, because the reader always knows `product_type`.
--
-- CONVERGENCE IS THE ORDINARY PATH, NOT A REPAIR SCRIPT. The capability is part of
-- CanonicalProduct's checksum, so every existing product's stored digest no longer matches a
-- freshly computed one. Incremental and full reconciliation recompute exactly that digest
-- (WpCommerceReconciliationSource::computeCurrentChecksum) and report `checksum_drift`, which
-- repairs by re-emission through the normal handler (DECISION T/U) — the same route replay takes
-- and the same route an ordinary product edit takes. There is no backfill UPDATE here and no
-- bespoke repair worker, because a second repair path is exactly what DECISION T/U forbids.
--
-- NO INDEX. Nothing filters, sorts or joins on this column: it is read as part of a row the
-- query already located by slug or by the listing's own cursor. An index would be paid for on
-- every product write and read by nothing — the same call migrations 0003 and 0006 record for
-- the indexes they deliberately did not create. It ships with the first query that predicates
-- on it, if one ever does.

COMMENT ON COLUMN commerce.products.variation_selection_supported IS
    'DECISION AL. TRUE: every attribute this product varies by is a supported global pa_* '
    'taxonomy, so the published Product + Variation data is complete enough to resolve a '
    'shopper''s selection to exactly one variation. FALSE: at least one variation-defining '
    'attribute is outside the supported model (a local/custom WooCommerce attribute), or the '
    'product varies by nothing at all — either way HSP data must not be used to resolve a '
    'variation. NULL: not applicable (non-variable product) or not yet classified (pre-DECISION-AL '
    'row awaiting convergence); delivery publishes FALSE for an unknown variable product.';
