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

    private function capture(string $eventType, int $productId): void
    {
        try {
            $this->events->provide($eventType, (string) $productId, [
                'source_updated_at' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
                'payload'           => ['product_id' => $productId],
            ]);
        } catch (OutboxWriteException $e) {
            // Never re-thrown: a capture failure must not fatal the editor request. It is
            // logged and surfaced as an admin notice, and reconciliation is the backstop
            // (DECISION 1) — but it is never swallowed silently.
            error_log(sprintf(
                '[HSP] outbox capture FAILED (lost sync until reconciliation) — '
                . 'aggregate_type=product aggregate_id=%d event_type=%s: %s',
                $productId,
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
