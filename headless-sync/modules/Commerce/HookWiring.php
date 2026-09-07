<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce;

use HSP\Core\Contracts\EventProviderInterface;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;
use HSP\Modules\Commerce\Events\CommerceEventTypes;

/**
 * Captures WooCommerce product lifecycle into wp_hsp_outbox (Rule 3, DECISION 1).
 *
 * THE HOOK ASYMMETRY, verified against WooCommerce 11.1.0 rather than assumed:
 *
 *   create / update  →  woocommerce_new_product / woocommerce_update_product
 *                       (fired by the product data store, carrying the hydrated WC_Product)
 *   delete / trash   →  WordPress's OWN post hooks, filtered to post_type 'product'
 *
 * There is NO `woocommerce_delete_product` or `woocommerce_trash_product` hook — grepped
 * across the plugin's includes/. Variations have dedicated delete and trash hooks; whole
 * products do not. Inferring one by symmetry with the variation hooks would have produced a
 * module that silently never tombstones a deleted product.
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
 * UNSUPPORTED PRODUCT TYPES ARE FILTERED HERE (AG-13). A `grouped` or `external` product is
 * normal out-of-scope source, so it is never captured at all — it cannot retry, cannot reach
 * the DLQ, and cannot block reconciliation or bootstrap convergence. The one exception is
 * DELETION, which is always emitted: a product that was supported and has been deleted must
 * tombstone, and at delete time its type may no longer be readable.
 */
final class HookWiring
{
    private const PRODUCT_POST_TYPE = 'product';

    /** @var array<int, bool> product ids already emitted this request (upsert path) */
    private array $handled = [];

    /** @var array<int, bool> product ids whose deletion has been emitted this request */
    private array $deleted = [];

    /** @var array<string, bool> term keys already emitted this request (upsert path) */
    private array $handledTerms = [];

    /** @var array<string, bool> term keys whose deletion has been emitted this request */
    private array $deletedTerms = [];

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
        add_action('delete_term', [$this, 'onDeleteTerm'], 10, 4);

        // Global attribute DEFINITIONS (P2-S4). These are neither posts nor terms — they live
        // in WooCommerce's own table — so they have their own lifecycle hooks and nothing in
        // the post or term families would ever fire for them.
        add_action('woocommerce_attribute_added', [$this, 'onAttributeAdded'], 10, 1);
        add_action('woocommerce_attribute_updated', [$this, 'onAttributeUpdated'], 10, 1);
        add_action('woocommerce_attribute_deleted', [$this, 'onAttributeDeleted'], 10, 1);
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
        if (! $this->isProduct($postId)) {
            return;
        }

        $this->captureDelete($postId);
    }

    /**
     * @param \WP_Post|object|null $post The deleted post — WordPress passes it because the row
     *        is already gone by the time this fires, so post_type cannot be looked up.
     */
    public function onAfterDeletePost(int $postId, mixed $post = null): void
    {
        $postType = is_object($post) && isset($post->post_type) ? (string) $post->post_type : '';

        if ($postType !== self::PRODUCT_POST_TYPE) {
            return;
        }

        $this->captureDelete($postId);
    }

    private function captureUpsert(int $productId, string $eventType): void
    {
        if ($productId <= 0 || isset($this->handled[$productId]) || isset($this->deleted[$productId])) {
            return;
        }

        if ($this->isRevisionOrAutosave($productId)) {
            return;
        }

        // Out-of-scope types are not captured — normal source, not a failure (AG-13).
        if (! ProductScope::isSupportedType($this->productType($productId))) {
            return;
        }

        $this->handled[$productId] = true;

        $this->capture($eventType, $productId);
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
        } else {
            if (isset($this->handledTerms[$key]) || isset($this->deletedTerms[$key])) {
                return;
            }

            $this->handledTerms[$key] = true;
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
        } catch (OutboxWriteException $e) {
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

    private function isProduct(int $postId): bool
    {
        if (! function_exists('get_post_type')) {
            return false;
        }

        return get_post_type($postId) === self::PRODUCT_POST_TYPE;
    }

    private function productType(int $postId): string
    {
        if (! function_exists('wc_get_product')) {
            return '';
        }

        $product = wc_get_product($postId);

        return is_object($product) && method_exists($product, 'get_type')
            ? (string) $product->get_type()
            : '';
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
