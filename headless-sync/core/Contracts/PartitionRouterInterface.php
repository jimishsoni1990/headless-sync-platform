<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Resolves which queue partition an event belongs to (DECISION AG AG-4).
 *
 * DECISION L (v1.12) hardcoded the dispatcher to the `content` partition and deferred
 * routing "to a future ADR when a second domain is introduced". AG-4 is that ruling.
 *
 * The routing key is the DOMAIN — the first segment of the OPEN-1 event name
 * `<domain>.<aggregate>.<action>`, which EventRegistry already validates. Routing lives
 * behind this one seam rather than being re-derived with string parsing scattered through
 * the dispatcher, the event worker and the dead-letter repository.
 *
 * Two different domains MAY map to the same physical partition — a future topology could
 * route several low-volume domains to one shared queue. What must be unique is the mapping
 * FROM a domain: one domain cannot carry two conflicting routing registrations.
 */
interface PartitionRouterInterface
{
    /**
     * Register the partition a domain's events are enqueued into.
     *
     * @throws \LogicException      if this domain is already registered to a different partition.
     * @throws \InvalidArgumentException if the partition is not one the queue provider accepts.
     */
    public function register(string $domain, string $partition): void;

    /**
     * The partition for a fully-qualified event type, e.g. 'commerce.product.created'.
     *
     * @throws \InvalidArgumentException if the event type is malformed, or its domain has no
     *         registered partition. An unroutable event must not be silently dropped or
     *         quietly parked in another domain's queue.
     */
    public function partitionForEventType(string $eventType): string;

    /** The partition for a bare domain key, e.g. 'commerce'. */
    public function partitionForDomain(string $domain): string;

    public function hasDomain(string $domain): bool;

    /**
     * Every partition that currently has at least one domain routed to it, in registration
     * order and de-duplicated. This is what the projection stage drains.
     *
     * @return list<string>
     */
    public function activePartitions(): array;
}
