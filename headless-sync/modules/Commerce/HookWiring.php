<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

use HSP\Core\Contracts\EventProviderInterface;
use HSP\Modules\Commerce\Events\CommerceEventTypes;

/**
 * Captures WooCommerce product lifecycle into wp_hsp_outbox (Rule 3, DECISION 1).
 *
 * THE HOOK SPLIT, verified against WooCommerce 11.1.0 rather than assumed:
 *
 *   create / update  →  woocommerce_new_product{,_variation} / woocommerce_update_product{,_variation}
 *                       (fired by the data store, carrying the hydrated WC_Product)
 *   delete / trash   →  WordPress's OWN post hooks, filtered by post type
 *
 * WooCommerce DOES emit `woocommerce_delete_product` and `woocommerce_trash_product` — the
 * P2-S2 note claiming otherwise was corrected at the P2-S5 preflight. The names are composed at
 * runtime as `'woocommerce_delete_' . $post_type`, so no literal is greppable, which is what
 * made them look absent.
 *
 * Deletion is nevertheless captured through the WordPress post hooks, now for a better reason
 * than the original one: those fire for EVERY deletion path — `wp_delete_post()` called
 * directly, a WP-CLI delete, another plugin removing the row — none of which reach a WooCommerce
 * data store and none of which would emit the WooCommerce hook. Using the post hooks means a
 * product cannot be deleted without HSP noticing.
 *
 * VARIATIONS follow the same shape and share the delete path: `product_variation` is a post
 * type, so one filtered handler covers both. A variation's own edits arrive on the WooCommerce
 * variation hooks; a variation's effect on its PARENT arrives separately, because
 * `WC_Product_Variable::sync()` calls `$parent->save()` and that fires
 * `woocommerce_update_product` for the parent (verified: class-wc-product-variable.php:710-725).
 * So a price change on one variation correctly re-emits both aggregates, and the first-emit-wins
 * guard collapses the duplicates each of them generates.
 *
 * FIRST-EMIT-WINS GUARD: one product save fires `woocommerce_update_product` AND `save_post`,
 * so without a per-request guard a single edit would write several outbox rows and burn
 * several aggregate versions. Collapsing them is safe precisely because processing is state
 * sync (ADR-044 / DECISION H): the one emitted event reloads the product's final state.
 *
 * Deletion is EXEMPT from the guard and instead BLOCKS later upserts for that id: a
 * create-then-delete inside one request must not leave the projection serving a product that
 * no longer exists. This mirrors the media guard P1B-S1 established.
 *
 * TERM COUNT CAPTURE (FLAG-COMMTERMCOUNT-1). `commerce.taxonomies.term_count` — published as
 * `count` — is `wp_term_taxonomy.count` read back through `get_term()` and projected verbatim,
 * so every source event that moves that number has to reach the taxonomy aggregate. Assigning a
 * term to a product does NOT edit the term: WordPress recounts it through
 * `wp_update_term_count()`, which fires `edited_term_taxonomy` and never `edited_term`.
 * Subscribing to `created_term`/`edited_term` alone therefore froze `term_count` at whatever it
 * was when the term was last *edited*.
 *
 * BOTH Commerce taxonomy families land on the same signal, but only one of them does so for the
 * obvious reason — verified against WooCommerce 11.1.0 rather than assumed:
 *
 *   pa_*         registered with `update_count_callback => _update_post_term_count` (WordPress's
 *                own), object type `product` only — so a variation's selected value never moves
 *                a `pa_*` count; the PARENT product's attribute assignment does.
 *   product_cat  registered with `update_count_callback => _wc_term_recount` (WooCommerce's
 *                own). It computes TWO counts: it delegates to `_update_post_term_count()` for
 *                `wp_term_taxonomy.count`, then writes a SECOND, catalog-visibility-aware count
 *                to `wp_termmeta.product_count_product_cat`. HSP projects the FIRST — the
 *                termmeta count reaches `get_terms()` through the `wc_change_term_counts` filter
 *                and `loadTerm()` uses `get_term()`, which that filter does not touch.
 *
 * So `_update_post_term_count()` fires `edited_term_taxonomy` immediately AFTER the
 * `wp_term_taxonomy.count` UPDATE for both families, on every path that can move the number —
 * relationship add (`wp_set_object_terms`), relationship remove (`wp_remove_object_terms`), and
 * every product status transition (`_update_term_count_on_transition_post_status`, which is what
 * makes draft↔publish↔trash converge). The `$callback = false` recount paths
 * (`_wc_recount_terms_by_product`) update only the termmeta count, which HSP does not project,
 * so they are correctly silent here.
 *
 * The hook carries a term_taxonomy_id, not a term_id, so the term is resolved through the public
 * `get_term_by()` API — no raw table read. Nothing about the count itself is captured: the
 * payload stays `{term_id}` and the worker reloads current WordPress state at process time
 * (DECISION H / ADR-044), so a collapsed or redelivered emit loses nothing.
 *
 * NO `pre_delete_term` SUPPRESSION, unlike Content — and that is a verified divergence, not an
 * omission. `wp_delete_term()` reassigns the term's objects BEFORE deleting it, which recounts,
 * and so fires `edited_term_taxonomy` for the term about to cease to exist. Content had to
 * suppress that because `CategoryUpsertHandler` THROWS when the term has gone, which would
 * dead-letter an ordinary "delete a category that has products". `TermUpsertHandler` no-ops on a
 * vanished term — the module-wide convention every Commerce handler follows — so the stray
 * upsert is harmless and the `.deleted` event that follows carries the truth.
 *
 * UNSUPPORTED PRODUCT TYPES ARE FILTERED HERE (AG-13). A `grouped` or `external` product is
 * normal out-of-scope source, so it is never captured at all — it cannot retry, cannot reach
 * the DLQ, and cannot block reconciliation or bootstrap convergence. The one exception is
 * DELETION, which is always emitted: a product that was supported and has been deleted must
 * tombstone, and at delete time its type may no longer be readable.
 */
final class HookWiring
{
    private const PRODUCT_POST_TYPE = 'product';

    private const VARIATION_POST_TYPE = 'product_variation';

    /** @var array<int, bool> product ids already emitted this request (upsert path) */
    private array $handled = [];

    /** @var array<int, bool> product ids whose deletion has been emitted this request */
    private array $deleted = [];

    /** @var array<string, bool> term keys already emitted this request (upsert path) */
    private array $handledTerms = [];

    /** @var array<string, bool> term keys whose deletion has been emitted this request */
    private array $deletedTerms = [];

    /** @var array<int, bool> variation ids already emitted this request (upsert path) */
    private array $handledVariations = [];

    /** @var array<int, bool> variation ids whose deletion has been emitted this request */
    private array $deletedVariations = [];

    private bool $captureFailed = false;

    public function __construct(private readonly EventProviderInterface $events)
    {
    }

    public function register(): void
    {
        if (! function_exists('add_action')) {
            return;
        }

        // WooCommerce CRUD — create and update.
        add_action('woocommerce_new_product', [$this, 'onProductCreated'], 10, 1);
        add_action('woocommerce_update_product', [$this, 'onProductUpdated'], 10, 1);

        // WordPress post lifecycle — deletion and trashing, which WooCommerce does not hook.
        add_action('wp_trash_post', [$this, 'onTrashPost'], 10, 1);
        add_action('after_delete_post', [$this, 'onAfterDeletePost'], 10, 2);

        // Taxonomy terms (P2-S3). WordPress term hooks, which fire for EVERY taxonomy — the
        // handlers below resolve through CommerceTaxonomies and silently ignore the ones this
        // module does not own.
        add_action('created_term', [$this, 'onCreatedTerm'], 10, 3);
        add_action('edited_term', [$this, 'onEditedTerm'], 10, 3);
        add_action('edited_term_taxonomy', [$this, 'onEditedTermTaxonomy'], 10, 2);
        add_action('delete_term', [$this, 'onDeleteTerm'], 10, 4);

        // Global attribute DEFINITIONS (P2-S4). These are neither posts nor terms — they live
        // in WooCommerce's own table — so they have their own lifecycle hooks and nothing in
        // the post or term families would ever fire for them.
        add_action('woocommerce_attribute_added', [$this, 'onAttributeAdded'], 10, 1);
        add_action('woocommerce_attribute_updated', [$this, 'onAttributeUpdated'], 10, 1);
        add_action('woocommerce_attribute_deleted', [$this, 'onAttributeDeleted'], 10, 1);

        // Variations (P2-S5). Their deletion rides the same post hooks registered above —
        // `product_variation` is a post type — so only the create/update pair is new here.
        add_action('woocommerce_new_product_variation', [$this, 'onVariationCreated'], 10, 1);
        add_action('woocommerce_update_product_variation', [$this, 'onVariationUpdated'], 10, 1);

        // Inventory (P2-S6). WooCommerce resolves the stock OWNER before firing these — verified,
        // wc_update_product_stock() calls get_stock_managed_by_id() and fires against the owner —
        // so a stock change on a parent-managed variation arrives here as the PARENT's event and
        // capture needs no ownership resolution of its own.
        add_action('woocommerce_product_set_stock', [$this, 'onStockChanged'], 10, 1);
        add_action('woocommerce_variation_set_stock', [$this, 'onStockChanged'], 10, 1);
        add_action('woocommerce_product_set_stock_status', [$this, 'onStockStatusChanged'], 10, 1);
        add_action('woocommerce_variation_set_stock_status', [$this, 'onStockStatusChanged'], 10, 1);
    }

    /** @param object|int $product The owner WooCommerce resolved, or its id. */
    public function onStockChanged(mixed $product): void
    {
        $this->captureInventory($this->idOf($product));
    }

    /** The status hooks pass the ID first, unlike the quantity hooks which pass the object. */
    public function onStockStatusChanged(mixed $productId): void
    {
        $this->captureInventory($this->idOf($productId));
    }

    /**
     * Emit an inventory event for one stock owner.
     *
     * Inventory gets its own guard-key namespace, and that is load-bearing rather than tidy: a
     * single product save fires the product hooks AND the stock hooks, and the two are separate
     * aggregates that must each emit exactly once. Sharing the product's map would let whichever
     * fired first suppress the other entirely.
     *
     * OWNERSHIP IS NOT DECIDED HERE. Capture emits for the id it is handed; the handler reloads
     * current state and discovers whether that entity still owns its stock. Deciding at capture
     * would mean a variation that has just stopped being an owner emits nothing — and emitting
     * nothing is what would leave its stale inventory row published forever.
     */
    private function captureInventory(int $ownerId, bool $terminal = false): void
    {
        if ($ownerId <= 0) {
            return;
        }

        $key = 'inv:' . $ownerId;

        if ($terminal) {
            if (isset($this->deletedTerms[$key])) {
                return;
            }

            $this->deletedTerms[$key] = true;
            $this->handledTerms[$key] = true;
        } else {
            if (isset($this->handledTerms[$key]) || isset($this->deletedTerms[$key])) {
                return;
            }

            $this->handledTerms[$key] = true;
        }

        $this->captureEvent(
            $terminal ? CommerceEventTypes::INVENTORY_DELETED : CommerceEventTypes::INVENTORY_UPDATED,
            (string) $ownerId,
            ['owner_id' => $ownerId],
        );
    }

    /** WooCommerce passes an id to some stock hooks and a WC_Product to others. */
    private function idOf(mixed $value): int
    {
        if (is_int($value) || is_string($value)) {
            return (int) $value;
        }

        return is_object($value) && method_exists($value, 'get_id') ? (int) $value->get_id() : 0;
    }

    public function onVariationCreated(int $variationId): void
    {
        $this->captureVariationUpsert($variationId, CommerceEventTypes::VARIATION_CREATED);
    }

    public function onVariationUpdated(int $variationId): void
    {
        $this->captureVariationUpsert($variationId, CommerceEventTypes::VARIATION_UPDATED);
    }

    /**
     * Variations get their own guard-key namespace.
     *
     * Post ids are one sequence shared with products, so a variation and a product cannot
     * actually collide — but keeping the maps separate means the terminal-delete rule for one
     * can never block the other, and it makes the guard readable rather than relying on that
     * coincidence.
     */
    private function captureVariationUpsert(int $variationId, string $eventType): void
    {
        if (
            $variationId <= 0
            || isset($this->handledVariations[$variationId])
            || isset($this->deletedVariations[$variationId])
        ) {
            return;
        }

        if ($this->isRevisionOrAutosave($variationId)) {
            return;
        }

        // As for products, SCOPE IS NOT DECIDED HERE. The old comment on this branch claimed
        // "anything already projected is tombstoned by the handler" — which is exactly what
        // cannot happen when the event is never emitted. A variation whose parent leaves Phase 2
        // scope stayed visible until the next full reconciliation.
        //
        // A parent id of 0 is still refused, because a variation with no parent is structurally
        // unaddressable rather than merely out of scope.
        $parentId = $this->variationParentId($variationId);

        if ($parentId <= 0) {
            return;
        }

        $this->handledVariations[$variationId] = true;

        $this->captureEvent($eventType, (string) $variationId, [
            'variation_id' => $variationId,
            'parent_id'    => $parentId,
        ]);

        // As for products — and here it also covers the AG-14 transition that has no stock hook
        // at all: toggling a variation between self-managed and parent-managed changes who owns
        // the fact without changing any quantity.
        $this->captureInventory($variationId);
    }

    private function captureVariationDelete(int $variationId): void
    {
        if ($variationId <= 0 || isset($this->deletedVariations[$variationId])) {
            return;
        }

        // Terminal: block any later upsert for this id in the same request.
        $this->deletedVariations[$variationId] = true;
        $this->handledVariations[$variationId] = true;

        $this->captureEvent(CommerceEventTypes::VARIATION_DELETED, (string) $variationId, [
            'variation_id' => $variationId,
        ]);
        $this->captureInventory($variationId, terminal: true);
    }

    private function variationParentId(int $variationId): int
    {
        if (! function_exists('wc_get_product')) {
            return 0;
        }

        $variation = wc_get_product($variationId);

        return is_object($variation) && method_exists($variation, 'get_parent_id')
            ? (int) $variation->get_parent_id()
            : 0;
    }

    public function onAttributeAdded(int $attributeId): void
    {
        $this->captureAttribute($attributeId, CommerceEventTypes::ATTRIBUTE_CREATED, false);
    }

    public function onAttributeUpdated(int $attributeId): void
    {
        $this->captureAttribute($attributeId, CommerceEventTypes::ATTRIBUTE_UPDATED, false);
    }

    public function onAttributeDeleted(int $attributeId): void
    {
        $this->captureAttribute($attributeId, CommerceEventTypes::ATTRIBUTE_DELETED, true);
    }

    /**
     * Attributes get their own guard-key namespace: attribute ids, post ids and term ids are
     * three independent sequences, so a shared key space would let unrelated entities suppress
     * one another.
     */
    private function captureAttribute(int $attributeId, string $eventType, bool $terminal): void
    {
        if ($attributeId <= 0) {
            return;
        }

        $key = 'attr:' . $attributeId;

        if ($terminal) {
            if (isset($this->deletedTerms[$key])) {
                return;
            }

            $this->deletedTerms[$key] = true;
            $this->handledTerms[$key] = true;
        } else {
            if (isset($this->handledTerms[$key]) || isset($this->deletedTerms[$key])) {
                return;
            }

            $this->handledTerms[$key] = true;
        }

        $this->captureEvent($eventType, (string) $attributeId, ['attribute_id' => $attributeId]);
    }

    public function onCreatedTerm(int $termId, int $ttId, string $taxonomy): void
    {
        $this->captureTerm($termId, $taxonomy, 'created');
    }

    public function onEditedTerm(int $termId, int $ttId, string $taxonomy): void
    {
        $this->captureTerm($termId, $taxonomy, 'updated');
    }

    /**
     * edited_term_taxonomy: the only source signal a `term_count` change ever produces
     * (FLAG-COMMTERMCOUNT-1). See the term-count section of the class docblock.
     *
     * @param int           $ttId     term_taxonomy_id — NOT a term_id.
     * @param string|object $taxonomy Taxonomy slug. `_update_post_term_count()` passes
     *                                `$taxonomy->name`, but third-party `do_action()` callers
     *                                can pass the WP_Taxonomy object, so both shapes are
     *                                accepted rather than fataling a product save on a
     *                                TypeError inside a hook.
     */
    public function onEditedTermTaxonomy(int $ttId, string|object $taxonomy): void
    {
        $taxonomyName = is_string($taxonomy)
            ? $taxonomy
            : (string) (get_object_vars($taxonomy)['name'] ?? '');

        // Resolve ownership BEFORE touching the database: product_visibility,
        // product_shipping_class, category, post_tag and every other registered taxonomy fire
        // this hook on ordinary traffic.
        if (CommerceTaxonomies::eventFor($taxonomyName, 'updated') === null) {
            return;
        }

        $term = get_term_by('term_taxonomy_id', $ttId);

        if (! $term instanceof \WP_Term) {
            return;
        }

        $this->captureTerm((int) $term->term_id, $taxonomyName, 'updated');
    }

    /** @param mixed $deletedTerm WordPress passes the term object; unused, the id suffices. */
    public function onDeleteTerm(int $termId, int $ttId, string $taxonomy, mixed $deletedTerm = null): void
    {
        $this->captureTerm($termId, $taxonomy, 'deleted');
    }

    public function onProductCreated(int $productId): void
    {
        $this->captureUpsert($productId, CommerceEventTypes::PRODUCT_CREATED);
    }

    public function onProductUpdated(int $productId): void
    {
        $this->captureUpsert($productId, CommerceEventTypes::PRODUCT_UPDATED);
    }

    public function onTrashPost(int $postId): void
    {
        match ($this->postType($postId)) {
            self::PRODUCT_POST_TYPE   => $this->captureDelete($postId),
            self::VARIATION_POST_TYPE => $this->captureVariationDelete($postId),
            default                   => null,
        };
    }

    /**
     * @param \WP_Post|object|null $post The deleted post — WordPress passes it because the row
     *        is already gone by the time this fires, so post_type cannot be looked up.
     */
    public function onAfterDeletePost(int $postId, mixed $post = null): void
    {
        $postType = is_object($post) && isset($post->post_type) ? (string) $post->post_type : '';

        match ($postType) {
            self::PRODUCT_POST_TYPE   => $this->captureDelete($postId),
            self::VARIATION_POST_TYPE => $this->captureVariationDelete($postId),
            default                   => null,
        };
    }

    private function captureUpsert(int $productId, string $eventType): void
    {
        if ($productId <= 0 || isset($this->handled[$productId]) || isset($this->deleted[$productId])) {
            return;
        }

        if ($this->isRevisionOrAutosave($productId)) {
            return;
        }

        // SCOPE IS NOT DECIDED HERE (AG-13). It used to be: an unsupported product type returned
        // early and captured nothing. That was wrong in the one direction that matters — a
        // `variable` product retyped to `grouped` emitted NOTHING, so its already-public
        // projection was never told it had left scope and kept being served. Live testing caught
        // it; the unit tests did not, because they proved the HANDLER tombstones on such an event
        // and separately proved capture drops it, and nobody ran the two together.
        //
        // The handler must decide anyway, and is authoritative: state sync (ADR-044) means the
        // type is re-read at PROCESSING time, and it can differ from the type at capture time. A
        // scope check here can only ever duplicate that decision — and, being the earlier one,
        // silently veto it.
        //
        // AG-13's requirements still hold, because they are about PROCESSING, not capture: the
        // handler tombstones an out-of-scope product and returns successfully, so nothing retries,
        // nothing reaches the DLQ, reconciliation is unaffected, and expected counts still come
        // from the reconciliation source, which excludes unsupported types.
        $this->handled[$productId] = true;

        $this->capture($eventType, $productId);

        // manage_stock, backorders and low_stock_amount change on an ORDINARY save without
        // firing any stock hook, so inventory is refreshed alongside every product upsert. The
        // duplicate this creates when a stock hook also fires is collapsed by the guard, and an
        // unchanged inventory is write-suppressed downstream anyway (DECISION 3).
        $this->captureInventory($productId);
    }

    private function captureDelete(int $productId): void
    {
        if ($productId <= 0 || isset($this->deleted[$productId])) {
            return;
        }

        // Terminal: block any later upsert for this id in the same request.
        $this->deleted[$productId] = true;
        $this->handled[$productId] = true;

        $this->capture(CommerceEventTypes::PRODUCT_DELETED, $productId);
        $this->captureInventory($productId, terminal: true);
    }

    /**
     * Capture a taxonomy term change.
     *
     * Term hooks fire for EVERY taxonomy on the site, so the first thing this does is ask
     * whether the module owns it. An unsupported taxonomy returns silently — other plugins
     * register taxonomies freely and their terms are normal traffic, not an error worth
     * logging on every save.
     *
     * Terms get their own guard key namespace so a product and a term sharing an id cannot
     * suppress one another — WordPress post ids and term ids are separate sequences.
     *
     * UPSERTS ARE NOT COLLAPSED, unlike every other aggregate here (FLAG-COMMTERMCOUNT-1).
     * WordPress can move one term's count MORE THAN ONCE in a single request — a product save
     * that adds a category and the status transition that recounts it, a bulk edit that
     * assigns and unassigns the same attribute term — and first-emit-wins would then pin the
     * term at an intermediate value: state-sync makes a collapsed emit safe only while nothing
     * processes it before the request finishes, and a concurrent cron cycle can. Duplicate
     * emits are exactly what at-least-once is for (Rule 4) and `TermAdapter` suppresses the
     * redundant write by checksum; a lost final state never converges. The TERMINAL delete
     * block stays: a create-then-delete in one request must not leave an upsert behind it.
     */
    private function captureTerm(int $termId, string $taxonomy, string $action): void
    {
        if ($termId <= 0) {
            return;
        }

        $eventType = CommerceTaxonomies::eventFor($taxonomy, $action);

        if ($eventType === null) {
            return;
        }

        $key = 'term:' . $termId;

        if ($action === 'deleted') {
            if (isset($this->deletedTerms[$key])) {
                return;
            }

            // Terminal, exactly as for products: block any later upsert for this term.
            $this->deletedTerms[$key] = true;
            $this->handledTerms[$key] = true;
        } elseif (isset($this->deletedTerms[$key])) {
            return;
        }

        $this->captureEvent($eventType, (string) $termId, ['term_id' => $termId]);
    }

    private function capture(string $eventType, int $productId): void
    {
        $this->captureEvent($eventType, (string) $productId, ['product_id' => $productId]);
    }

    /**
     * The single emit path for every Commerce aggregate.
     *
     * @param array<string,mixed> $payload
     */
    private function captureEvent(string $eventType, string $aggregateId, array $payload): void
    {
        try {
            $this->events->provide($eventType, $aggregateId, [
                'source_updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'payload'           => $payload,
            ]);
        } catch (\Throwable $e) {
            // Never re-thrown: a capture failure must not fatal the editor request. It is
            // logged and surfaced as an admin notice, and reconciliation is the backstop
            // (DECISION 1) — but it is never swallowed silently.
            error_log(sprintf(
                '[HSP] outbox capture FAILED (lost sync until reconciliation) — '
                . 'aggregate_id=%s event_type=%s: %s',
                $aggregateId,
                $eventType,
                $e->getMessage(),
            ));

            $this->flagCaptureFailure();
        }
    }

    private function postType(int $postId): string
    {
        if (! function_exists('get_post_type')) {
            return '';
        }

        $postType = get_post_type($postId);

        return is_string($postType) ? $postType : '';
    }

    private function isRevisionOrAutosave(int $postId): bool
    {
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($postId)) {
            return true;
        }

        return function_exists('wp_is_post_autosave') && wp_is_post_autosave($postId);
    }

    private function flagCaptureFailure(): void
    {
        if ($this->captureFailed || ! function_exists('add_action')) {
            return;
        }

        $this->captureFailed = true;

        add_action('admin_notices', static function (): void {
            if (! function_exists('esc_html__')) {
                return;
            }

            echo '<div class="notice notice-warning"><p>'
                . esc_html__(
                    'HSP could not capture a WooCommerce product change. The product will be '
                    . 'repaired by the next reconciliation pass.',
                    'headless-sync'
                )
                . '</p></div>';
        });
    }
}
