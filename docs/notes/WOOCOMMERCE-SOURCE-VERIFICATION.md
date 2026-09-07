# WooCommerce Source Verification — Phase 2

> **Non-authoritative.** A record of what was verified against the WooCommerce version actually
> installed in the development site, as DECISION AG's source-fact verification protocol requires.
> Nothing here is promoted to an architectural fact because it is "likely true"; every line below
> was read out of the installed plugin source. Re-verify before relying on it against a different
> WooCommerce version.

**Verified against:** WooCommerce **11.1.0**, at
`C:\Users\jimis\Local Sites\headless-sync-platform\app\public\wp-content\plugins\woocommerce`
**Date:** 2026-09-07 (P2-S2 preflight)

---

## 1. Product and variation CRUD hooks

| Hook | Signature | Source |
|---|---|---|
| `woocommerce_new_product` | `($id, $product)` | `includes/data-stores/class-wc-product-data-store-cpt.php:263` |
| `woocommerce_update_product` | `($id, $product)` | `includes/data-stores/class-wc-product-data-store-cpt.php:398` |
| `woocommerce_new_product_variation` | `($id, $product)` | `includes/data-stores/class-wc-product-variation-data-store-cpt.php:174` |
| `woocommerce_update_product_variation` | `($id, $product)` | `includes/data-stores/class-wc-product-variation-data-store-cpt.php:279` |
| `woocommerce_delete_product_variation` | `($variation_id)` | `includes/data-stores/class-wc-product-variable-data-store-cpt.php:1030` |
| `woocommerce_trash_product_variation` | `($variation_id)` | `includes/data-stores/class-wc-product-variable-data-store-cpt.php:1041` |

### FINDING — there is no `woocommerce_delete_product` or `woocommerce_trash_product` hook

Grepped across `includes/`: **no such `do_action` exists**. Variations have dedicated delete and
trash hooks; **whole products do not**.

Consequence for capture: product **deletion and trashing must be captured through WordPress's own
post hooks** (`wp_trash_post` / `after_delete_post` / `transition_post_status`), filtered to
`post_type === 'product'` — while **creation and update** are captured through the WooCommerce CRUD
hooks above, which fire from the data store and carry the hydrated `WC_Product`.

This asymmetry is exactly the kind of assumption that would have been wrong if inferred by symmetry
with the variation hooks. It also means the Commerce module wires **both** hook families, and needs
the same per-request first-emit-wins guard Content uses: a single product save fires
`woocommerce_update_product` **and** `save_post`, and a delete fires WordPress transitions.

---

## 2. Catalog visibility is NOT `post_status` (Requirement B)

Source: `includes/data-stores/class-wc-product-data-store-cpt.php:567-583`, `read_visibility()`.

Visibility is stored as terms in the **`product_visibility` taxonomy**, not as post status:

| Terms present | `get_catalog_visibility()` |
|---|---|
| neither | `visible` |
| `exclude-from-search` | `catalog` |
| `exclude-from-catalog` | `search` |
| both | `hidden` |

`featured` is a term in the same taxonomy.

Consequence: a product may be `post_status = 'publish'` and still be **excluded from the catalog**.
Public product **list** endpoints must exclude products carrying `exclude-from-catalog` (that is,
visibility `search` or `hidden`), while single-product addressing follows WooCommerce's
direct-access behaviour. Deriving catalog membership from post status alone would publish products
the store owner deliberately hid.

---

## 3. Product types (AG-13)

`get_type()` returns the `ProductType` enum's string values —
`includes/class-wc-product-simple.php:34` returns `ProductType::SIMPLE`,
`includes/class-wc-product-variable.php:58` returns `ProductType::VARIABLE`.

Phase 2 supports `simple` and `variable` only. `grouped`, `external`/affiliate and custom types are
normal out-of-scope source entities, not processing failures.

---

## 4. Permalinks (Requirement A) — classified **Case B**

Source: `includes/wc-core-functions.php:2143`, `wc_get_permalink_structure()`.

The structure is read from the **`woocommerce_permalinks` option** and is fully configurable:

```
product_base    (default 'product')
category_base   (default 'product-category')
tag_base        (default 'product-tag')
attribute_base  (default '')
use_verbose_page_rules
```

So `/product/{slug}` is a **default, not a guarantee**, and hardcoding it would be wrong on any
store that changed the base — which the settings screen invites.

**Classification: Case B — a small store-level configuration projection would be required** to let a
consumer reconstruct public URLs, because the bases live in a WordPress option that the delivery
side cannot read at request time (ADR-040 forbids a WordPress read on the consumer path).

Per Requirement A this is a **STOP-and-flag**, not something to invent inside a build session. It is
raised as **FLAG-COMMPERMA-1**. Phase 2 catalog synchronisation is **not blocked**: no permalink or
path field is being shipped, and P2-S7 will report Product and Product Category permalink
compatibility as **EXPLICITLY DEFERRED WITH FLAG** unless the flag is ruled sooner.

---

## 5. Currency (Requirement C′) — confirms the ruling

Source: `includes/wc-core-functions.php:494`, `get_woocommerce_currency()` returns
`get_option('woocommerce_currency')` through the `woocommerce_currency` filter.

Currency is a **single store-level option**, with no per-product currency anywhere in the standard
model. This confirms Requirement C′: Doc 3's per-product `currency` column stays superseded, and no
currency column is added to `commerce.products` or `commerce.product_variations`.

---

## 6. Still to verify in later sessions

Deliberately not verified yet — each belongs to the session that needs it, per the protocol:

- **P2-S3:** whether `product_cat` permits the same leaf slug under different parents; how
  WordPress/WooCommerce resolves such terms; whether configured category URLs carry ancestor paths.
- **P2-S4:** global attribute-definition storage and its lifecycle hooks (definitions live outside
  posts and terms, so they have no `save_post`/`created_term` hook).
- **P2-S6:** the five AG-14 inventory-ownership questions — simple-product stock, variable-parent
  stock, variation-managed stock, how a variation signals inherited stock, and the hooks for each.
- **HPOS:** only far enough to confirm orders stay irrelevant to Phase 2.
