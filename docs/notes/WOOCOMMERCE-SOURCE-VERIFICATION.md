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

### FINDING — the delete/trash hook names are built DYNAMICALLY, and the P2-S2 note was wrong

**Corrected at the P2-S5 preflight.** The P2-S2 entry recorded that no `woocommerce_delete_product`
hook exists, on the strength of a grep across `includes/` finding no matching `do_action`. The grep
was accurate; the conclusion was not.

`WC_Product_Data_Store_CPT::delete()`
(`includes/data-stores/class-wc-product-data-store-cpt.php:407-432`) composes the hook name at
runtime from the post type:

```php
$post_type = $product->is_type( ProductType::VARIATION ) ? 'product_variation' : 'product';
do_action( 'woocommerce_before_delete_' . $post_type, $id );
do_action( 'woocommerce_delete_' . $post_type, $id );   // or woocommerce_trash_{$post_type}
```

So `woocommerce_delete_product`, `woocommerce_trash_product`, `woocommerce_delete_product_variation`
and `woocommerce_trash_product_variation` **all fire**, and none of them is greppable as a literal.
The two variation hooks listed above are real but come from a *second* site
(`class-wc-product-variable-data-store-cpt.php`, which clears a parent's variations) — finding those
and not the product ones is precisely what made the asymmetry look genuine.

**The capture wiring built on the wrong conclusion is nevertheless correct, and stays.** Deletion and
trashing are captured through WordPress's own post hooks (`wp_trash_post` / `after_delete_post` /
`transition_post_status`) filtered by post type, because those fire for **every** deletion path —
including `wp_delete_post()` called directly, a WP-CLI delete, or another plugin removing the row,
none of which go through the WooCommerce data store and none of which would emit the WooCommerce
hook. Creation and update are captured through the WooCommerce CRUD hooks, which carry the hydrated
`WC_Product`. Same wiring, sounder reason.

It also means the Commerce module wires **both** hook families, and needs the same per-request
first-emit-wins guard Content uses: a single product save fires `woocommerce_update_product` **and**
`save_post`, and a delete fires WordPress transitions.

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

---

## 7. P2-S3 preflight — `product_cat` hierarchy and slug uniqueness

**Verified:** 2026-09-08, against WordPress core (`wp-includes/taxonomy.php`) and WooCommerce
11.1.0 (`includes/class-wc-post-types.php`).

### `product_cat` IS hierarchical

Registered with `'hierarchical' => true`, so categories nest and a `parent` relationship exists.

### But leaf slugs CANNOT repeat under different parents

`wp_unique_term_slug()` (`wp-includes/taxonomy.php:3136`) enforces uniqueness **per taxonomy**,
not per parent:

1. If no term with that slug exists **in this taxonomy**, the slug is used as-is
   (`:3143` — "duplicate slugs are allowed as long as they're in different taxonomies").
2. Otherwise, for a hierarchical taxonomy with a parent, ancestor slugs are appended until the
   result is unique — `shirts` under `men` becomes **`shirts-men`** (`:3151-3168`).
3. If that still collides, a numeric suffix is appended — `shirts-2` (`:3193-3200`).

**This is the OPPOSITE of the WordPress Pages case.** Page `post_name` uniqueness is scoped per
PARENT, which is exactly why two pages could both be `team` and why FLAG-PAGESLUG-1 existed at
all. Terms do not behave that way.

### Consequences for P2-S3

**Addressing:** a bare `product_cat` slug is unambiguous, so `/categories/{slug}`-style flat
addressing is correct and sufficient. The DECISION AD full-ancestor-path model is **not needed
here** — the architect's instruction routed to it only *"if hierarchical leaf slugs can be
ambiguous"*, and verification shows they cannot. Reusing `HierarchicalQueryProviderInterface`
would add a second addressing model for a problem this taxonomy does not have.

**Filtering:** a product-category filter may safely use a bare slug as its canonical key. The
ambiguity that would have forced an exact path (or a term id) does not arise.

**Constraint: index, NOT unique.** Uniqueness is enforced by WordPress at WRITE time, not as a
storage invariant, and it is defeasible — the `wp_unique_term_slug_is_bad_slug` filter
(`:3182`) lets a plugin override the decision, direct database inserts bypass it entirely, and
imported or legacy terms may predate it. A PostgreSQL `UNIQUE (taxonomy_type, slug)` would turn
such a state into a **projection failure that dead-letters**, which is the same class of harm
AG-7 rejected for foreign keys: a database constraint converting unusual-but-valid source state
into a sync failure. So `(taxonomy_type, slug)` gets a **lookup index only** — matching what
DECISION AA already concluded for `content.taxonomies`, where the composite shipped as an index
and the unique constraint was explicitly declined.

**Hierarchy still projects.** `parent_id` is carried so a consumer can reconstruct the tree, and
because WooCommerce category URLs nest (`/product-category/clothing/men/`). Reconstructing those
URLs is permalink work and stays under FLAG-COMMPERMA-1; it is not an addressing question.

---

## 8. P2-S4 preflight — global attribute definitions and `pa_*` terms

**Verified:** 2026-09-08, WooCommerce 11.1.0, `includes/wc-attribute-functions.php`.

### Definitions live in a custom table, not in posts or terms

`{$wpdb->prefix}woocommerce_attribute_taxonomies` (`:69`). This is why AG-9 keeps
`commerce.attributes` as a separate projection: a global attribute definition is not a taxonomy
term, and it has no `save_post` or `created_term` hook because it is neither a post nor a term.

### Lifecycle hooks

| Hook | Signature | Source |
|---|---|---|
| `woocommerce_attribute_added` | `($id, $data)` | `:591` |
| `woocommerce_attribute_updated` | `($id, $data, $old_slug)` | `:615` |
| `woocommerce_attribute_deleted` | `($id, $name, $taxonomy)` | `:778` |

### Public accessor shape

`wc_get_attribute($id)` (`:480-486`) returns an object with `id`, `name` (the human label,
from `attribute_label`), `slug` (the **`pa_`-prefixed taxonomy name**, via
`wc_attribute_taxonomy_name()`), `type`, `order_by`, and `has_archives`. Reading through this
accessor rather than the custom table directly is what the protocol requires.

`wc_attribute_taxonomy_name($name)` is `'pa_' . wc_sanitize_taxonomy_name($name)` (`:143`), so
every global attribute taxonomy is `pa_<slug>`.

### FINDING — `woocommerce_attribute_updated` carries `$old_slug`

WooCommerce passes the PREVIOUS slug because renaming an attribute renames its taxonomy:
`pa_colour` becomes `pa_color`, and every existing term moves with it. A projection keyed on
`taxonomy_type` therefore has stale rows after a rename unless the update path accounts for it.

Two consequences for P2-S4:

1. The attribute-definition aggregate must capture the rename, not just the label change.
2. The `pa_*` TERMS carry the taxonomy name as their discriminator, so a rename changes the
   discriminator of rows the attribute event does not itself touch. Terms are reconcilable, so
   a full reconcile repairs them — but the incremental path will not notice, because term ids
   and term content are unchanged. This is worth stating explicitly rather than discovering
   later; it is the same shape as the DECISION AE unprojected-ancestor limit: a documented
   convergence window, not a defect.

### Attribute terms are DYNAMIC taxonomies

Unlike `product_cat`, there is no fixed list: `pa_*` taxonomies come and go with the
attributes an operator defines. A projection descriptor for attribute terms therefore cannot
name a single discriminator VALUE the way `product_cat` does — it needs prefix matching, which
`ProjectionDescriptor` does not currently express. That is an infrastructure gap P2-S4 must
close in core (AG-3 puts projection read metadata in the descriptor), not something to work
around in the module.

---

## 9. P2-S5 preflight — product variations

Verified against WooCommerce 11.1.0 before the migration was written.

### 9.1 Lifecycle hooks

| Hook | Signature | Source |
|---|---|---|
| `woocommerce_new_product_variation` | `($id, $variation)` | `class-wc-product-variation-data-store-cpt.php:174` |
| `woocommerce_update_product_variation` | `($id, $variation)` | `class-wc-product-variation-data-store-cpt.php:279` |
| `woocommerce_delete_product_variation` | `($variation_id)` | composed at `class-wc-product-data-store-cpt.php:426`, and again at `class-wc-product-variable-data-store-cpt.php:1030` |
| `woocommerce_trash_product_variation` | `($variation_id)` | composed at `class-wc-product-data-store-cpt.php:430`, and again at `class-wc-product-variable-data-store-cpt.php:1041` |

Two separate delete sites, which is why section 1's correction matters: deleting one variation
runs the generic data-store `delete()`, while clearing a parent's variations runs the variable
data store's own loop. Both emit the same hook name. Capture nevertheless keys on the WordPress
post hooks filtered to `post_type === 'product_variation'`, for the same reason as products —
those fire for every deletion path, including ones that never reach a WooCommerce data store.

### 9.2 Selected attribute values are (taxonomy → slug) pairs, not term ids

`WC_Product_Variation::get_attributes()` returns a map whose KEYS are taxonomy names without the
`attribute_` prefix (`pa_colour`) and whose VALUES are term **slugs** (`blue`). The prefixed form
is produced only by `get_variation_attributes()`, which is a presentation helper.

Source: `wc_get_product_variation_attributes()`, `includes/wc-product-functions.php:1194-1240`.

### 9.3 FINDING — an empty value means "any", and is not the same as absent

`wc-product-functions.php:1208`:

```php
$variation_attributes[ $attribute ] = ''; // Add it - 'any' will be assumed.
```

A variation participating in an attribute but matching **every** value of it carries that
taxonomy with an empty string. That is a third state alongside "has this value" and "does not
use this attribute at all", and it is genuinely meaningful: a variation `Blue / Any size` is one
row that answers for every size.

Consequence for the projection: the selected-values map must be stored **as it is**, including
the empty entries, because dropping them loses the distinction between "any size" and "no size
dimension". Link rows are written only for entries that resolve to a term — an "any" entry has no
term to link — so the JSONB map and the join rows deliberately do not carry the same information,
and the map is the authoritative one.

### 9.4 Parent relationship

`get_parent_id()` returns the parent product's post id. The projection stores that **source** id,
not the parent's projection UUID — the same reasoning migration 0004 records for terms: a
variation may legitimately project before its parent under at-least-once, non-FIFO delivery, and
a UUID reference would match nothing with no way to repair it afterwards (the variation's own
state has not changed, so its checksum has not moved and DECISION 3 suppresses the rewrite).

### 9.5 Variations have no independent public URL

WooCommerce addresses a variation through its parent's page (`?attribute_pa_colour=blue`) or by
`variation_id` in the add-to-cart form; there is no permalink base for `product_variation`. So
Requirement A raises nothing new here, and the delivery surface nests variations under the parent
product rather than inventing a slug they do not have.

### 9.6 Not verified here

Variation **stock** is deliberately out of scope for this session and belongs to P2-S6 with the
rest of AG-14 — including how a variation signals that stock is managed at the parent instead.
This session projects a variation's identity, pricing and selected attribute values only.
