<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Reconciliation;

use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;
use HSP\Modules\Commerce\Extractors\ProductExtractor;
use HSP\Modules\Commerce\CommerceTaxonomies;
use HSP\Modules\Commerce\Extractors\TermExtractor;
use HSP\Modules\Commerce\ProductScope;
use HSP\Modules\Commerce\Transformers\TermTransformer;
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
    private const AGGREGATE_TYPES = ['product', 'product_category'];

    public function __construct(
        private readonly WpCommerceLoader $loader,
        private readonly ProductExtractor $extractor,
        private readonly ProductTransformer $transformer,
        private readonly TermExtractor $termExtractor,
        private readonly TermTransformer $termTransformer,
    ) {
    }

    /** @return list<string> */
    public function getSupportedAggregateTypes(): array
    {
        return self::AGGREGATE_TYPES;
    }

    /** @return list<string> */
    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        if ($aggregateType === 'product_category') {
            return array_map(
                static fn (int $id): string => (string) $id,
                $this->loader->listTermIdsAfter(CommerceTaxonomies::PRODUCT_CAT, $afterId, $limit),
            );
        }

        if ($aggregateType !== 'product') {
            return [];
        }

        return array_map(
            static fn (int $id): string => (string) $id,
            $this->loader->listProductIdsAfter($afterId, $limit),
        );
    }

    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
        if ($aggregateType === 'product_category') {
            // Terms carry no modified timestamp in WordPress, so drift detection for them is
            // existence-only at the hourly cadence and checksum-based nightly (DECISION U D2).
            $term = $this->loader->loadTerm((int) $aggregateId);

            return new SourceState($term !== null, $term !== null, null);
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

        $modifiedAt = null;
        $modified   = $raw['modified_at'] ?? null;
        if (is_string($modified) && $modified !== '' && $modified !== '0000-00-00 00:00:00') {
            try {
                $modifiedAt = new \DateTimeImmutable($modified, new \DateTimeZone('UTC'));
            } catch (\Exception) {
                $modifiedAt = null;
            }
        }

        return new SourceState(true, $public, $modifiedAt);
    }

    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string
    {
        if ($aggregateType === 'product_category') {
            $term = $this->loader->loadTerm((int) $aggregateId);

            return $term === null
                ? null
                : $this->termTransformer->transform($this->termExtractor->extract($term))->getChecksum();
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
