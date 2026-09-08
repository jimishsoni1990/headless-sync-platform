<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Reconciliation;

use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\Extractors\AttributeExtractor;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\Extractors\InventoryExtractor;
use HSP\Modules\Commerce\Extractors\VariationExtractor;
use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\Transformers\AttributeTransformer;
use HSP\Modules\Commerce\Transformers\TermTransformer;
use HSP\Modules\Commerce\Transformers\InventoryTransformer;
use HSP\Modules\Commerce\Transformers\VariationTransformer;
use HSP\Modules\Commerce\Transformers\ProductTransformer;
use HSP\Modules\Commerce\WpCommerceLoader;

/**
 * The WordPress-side detection source for Commerce reconciliation (DECISION U).
 *
 * Registered into the core-owned ReconciliationSourceRegistry (DECISION AG AG-2). Before that
 * ruling this contract was a SCALAR container binding, so a second module's source had
 * literally nowhere to go — Commerce could not have been reconciled at all.
 *
 * `computeCurrentChecksum()` reuses the exact loader → extractor → transformer path the upsert
 * handler uses, so a recomputed checksum matches what a fresh projection would store. Any
 * divergence here would report every product as permanently drifted, which is how a
 * reconciliation pass turns into an infinite repair loop.
 *
 * SCOPE (AG-13): a product whose type is not supported is reported as NOT public rather than
 * as absent. That distinction matters — it drives the orphan path to tombstone a projection
 * that has left scope, instead of leaving it visible or treating the product as missing.
 */
final class WpCommerceReconciliationSource implements WpReconciliationSourceInterface
{
    /** @var list<string> */
    private const AGGREGATE_TYPES = [
        'product',
        'product_category',
        'attribute',
        'attribute_term',
        'product_variation',
        'inventory',
    ];

    public function __construct(
        private readonly WpCommerceLoader $loader,
        private readonly ProductExtractor $extractor,
        private readonly ProductTransformer $transformer,
        private readonly TermExtractor $termExtractor,
        private readonly TermTransformer $termTransformer,
        private readonly AttributeExtractor $attributeExtractor,
        private readonly AttributeTransformer $attributeTransformer,
        private readonly VariationExtractor $variationExtractor,
        private readonly VariationTransformer $variationTransformer,
        private readonly InventoryExtractor $inventoryExtractor,
        private readonly InventoryTransformer $inventoryTransformer,
    ) {
    }

    /** @return list<string> */
    public function getSupportedAggregateTypes(): array
    {
        return self::AGGREGATE_TYPES;
    }

    /**
     * The reconciliation corpus for one aggregate — IN-SCOPE entities only.
     *
     * The scope filter is AG-13 compliance and it is load-bearing. Before it, this returned every
     * product regardless of type, so `BackfillProgress` counted `grouped` and `external` products
     * as EXPECTED while the projection correctly excluded them. On the first-run test that left
     * onboarding stuck at 96% — expected 126, projected 122 — and convergence could never be
     * reached on any store containing a single unsupported product. AG-13 says it plainly:
     * unsupported types "must not count as an expected Phase 2 projected Product".
     *
     * Filtering costs nothing in tombstone coverage, which is the reason it looked unsafe. An
     * entity that LEAVES scope still gets tombstoned, because `ReconciliationService::findOrphans()`
     * enumerates the PROJECTION table and asks `getSourceState()` about each row — that direction
     * never consults this corpus. So a `variable` product retyped to `grouped` disappears from the
     * corpus here and is caught from the PostgreSQL side instead, which is where it has to be
     * caught anyway (it might have been retyped while HSP was not running).
     *
     * PAGING: filtering is done INSIDE the pager, not applied to its output. Returning a
     * short-but-non-empty page is fine, but returning an EMPTY page while ids remain would stop
     * `ReconciliationService`'s `do/while ($ids !== [])` loop early — so a store whose last page
     * happened to be entirely `grouped` products would silently truncate the corpus. This loops
     * until it has $limit in-scope ids or the underlying corpus is genuinely exhausted.
     *
     * @return list<string>
     */
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        if ($aggregateType === 'product_category') {
            return $this->asStrings(
                $this->loader->listTermIdsAfter(CommerceTaxonomies::PRODUCT_CAT, $afterId, $limit)
            );
        }

        if ($aggregateType === 'attribute') {
            return $this->asStrings($this->loader->listAttributeIdsAfter($afterId, $limit));
        }

        if ($aggregateType === 'attribute_term') {
            // pa_* taxonomies are dynamic, so the corpus is the union across every attribute
            // taxonomy currently defined. Term ids are globally unique, so the merged set is
            // still safely keyset-paged by id.
            $ids = [];
            foreach ($this->loader->attributeTaxonomyNames() as $taxonomy) {
                foreach ($this->loader->listTermIdsAfter($taxonomy, $afterId, $limit) as $id) {
                    $ids[] = $id;
                }
            }

            sort($ids);

            return $this->asStrings(array_slice($ids, 0, $limit));
        }

        if ($aggregateType === 'product_variation') {
            return $this->asStrings($this->scopedPage(
                fn (int $after, int $take): array => $this->loader->listVariationIdsAfter($after, $take),
                fn (int $id): bool => $this->loader->loadVariation($id) !== null,
                $afterId,
                $limit,
            ));
        }

        if ($aggregateType === 'inventory') {
            // Products and variations page together — they share the wp_posts id sequence — and
            // an id survives only if it is genuinely an inventory OWNER (AG-14). A variation
            // whose stock its parent manages is not one, and counting it would make convergence
            // unreachable in exactly the way the product filter above fixes.
            return $this->asStrings($this->scopedPage(
                fn (int $after, int $take): array => $this->loader->listInventoryOwnerIdsAfter($after, $take),
                fn (int $id): bool => $this->loader->loadInventory($id) !== null,
                $afterId,
                $limit,
            ));
        }

        if ($aggregateType !== 'product') {
            return [];
        }

        return $this->asStrings($this->scopedPage(
            fn (int $after, int $take): array => $this->loader->listProductIdsAfter($after, $take),
            fn (int $id): bool => ProductScope::isSupportedType((string) $this->loader->productType($id)),
            $afterId,
            $limit,
        ));
    }

    /**
     * Page an underlying corpus, keeping only ids that pass $inScope, until $limit is filled or
     * the corpus is exhausted.
     *
     * The inner loop is what makes filtering safe: without it a page consisting entirely of
     * out-of-scope entities would return empty and be read as "corpus exhausted".
     *
     * @param \Closure(int, int): list<int> $page
     * @param \Closure(int): bool           $inScope
     * @return list<int>
     */
    private function scopedPage(\Closure $page, \Closure $inScope, int $afterId, int $limit): array
    {
        $kept   = [];
        $cursor = $afterId;

        // Bounded: each iteration strictly advances the cursor, and stops the moment the
        // underlying corpus returns nothing.
        while (count($kept) < $limit) {
            $raw = $page($cursor, $limit);

            if ($raw === []) {
                break;
            }

            foreach ($raw as $id) {
                $cursor = max($cursor, $id);

                if ($inScope($id)) {
                    $kept[] = $id;

                    if (count($kept) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $kept;
    }

    /**
     * @param list<int> $ids
     * @return list<string>
     */
    private function asStrings(array $ids): array
    {
        return array_map(static fn (int $id): string => (string) $id, $ids);
    }
    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
        if ($aggregateType === 'attribute') {
            $attribute = $this->loader->loadAttribute((int) $aggregateId);

            return new SourceState($attribute !== null, $attribute !== null, null);
        }

        if ($aggregateType === 'product_category' || $aggregateType === 'attribute_term') {
            // Terms carry no modified timestamp in WordPress, so drift detection for them is
            // existence-only at the hourly cadence and checksum-based nightly (DECISION U D2).
            $term = $this->loader->loadTerm((int) $aggregateId);

            return new SourceState($term !== null, $term !== null, null);
        }

        if ($aggregateType === 'inventory') {
            // Not an owner reads as NOT PUBLIC rather than absent, so the orphan path tombstones
            // a row that has stopped being this entity's fact to hold (AG-14). Reporting it
            // absent would say something false about the entity, which still exists.
            $owns = $this->loader->loadInventory((int) $aggregateId) !== null;

            return new SourceState($owns, $owns, null);
        }

        if ($aggregateType === 'product_variation') {
            // EXISTS and PUBLIC are deliberately different questions here. A variation whose
            // parent has left supported scope still exists as a WordPress post — so reporting
            // it absent would be a lie — but it is no longer public, and that is what drives
            // the orphan path to tombstone its projection (AG-13 + DECISION I).
            $raw = $this->loader->loadVariation((int) $aggregateId);

            if ($raw !== null) {
                return new SourceState(true, true, $this->modifiedAt($raw['modified_at'] ?? null));
            }

            return new SourceState(
                $this->loader->variationExists((int) $aggregateId),
                false,
                null,
            );
        }

        if ($aggregateType !== 'product') {
            return new SourceState(false, false, null);
        }

        $raw = $this->loader->loadProduct((int) $aggregateId);

        if ($raw === null) {
            return new SourceState(false, false, null);
        }

        // Exists but out of Phase 2 scope: present, NOT public. The orphan path then tombstones
        // its projection rather than leaving a `grouped` product published as though supported.
        $public = ProductScope::isSupportedType((string) ($raw['product_type'] ?? ''));

        return new SourceState(true, $public, $this->modifiedAt($raw['modified_at'] ?? null));
    }

    /** WordPress GMT strings are UTC but carry no zone marker, so the zone is supplied here. */
    private function modifiedAt(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string
    {
        if ($aggregateType === 'attribute') {
            $attribute = $this->loader->loadAttribute((int) $aggregateId);

            return $attribute === null
                ? null
                : $this->attributeTransformer->transform($this->attributeExtractor->extract($attribute))->getChecksum();
        }

        if ($aggregateType === 'product_category' || $aggregateType === 'attribute_term') {
            $term = $this->loader->loadTerm((int) $aggregateId);

            return $term === null
                ? null
                : $this->termTransformer->transform($this->termExtractor->extract($term))->getChecksum();
        }

        if ($aggregateType === 'product_variation') {
            $raw = $this->loader->loadVariation((int) $aggregateId);

            return $raw === null
                ? null
                : $this->variationTransformer->transform($this->variationExtractor->extract($raw))->getChecksum();
        }

        if ($aggregateType === 'inventory') {
            $raw = $this->loader->loadInventory((int) $aggregateId);

            return $raw === null
                ? null
                : $this->inventoryTransformer->transform($this->inventoryExtractor->extract($raw))->getChecksum();
        }

        if ($aggregateType !== 'product') {
            return null;
        }

        $raw = $this->loader->loadProduct((int) $aggregateId);

        if ($raw === null || ! ProductScope::isSupportedType((string) ($raw['product_type'] ?? ''))) {
            return null;
        }

        return $this->transformer->transform($this->extractor->extract($raw))->getChecksum();
    }

    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool
    {
        global $wpdb;

        if (! isset($wpdb)) {
            return false;
        }

        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hsp_outbox
             WHERE aggregate_type = %s AND aggregate_id = %s AND status = 'pending'",
            $aggregateType,
            $aggregateId,
        );

        return (int) $wpdb->get_var($sql) > 0;
    }
}
