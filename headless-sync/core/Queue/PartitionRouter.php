<?php

declare(strict_types=1);

namespace HSP\Core\Queue;

use HSP\Core\Contracts\PartitionRouterInterface;

/**
 * Explicit domain → queue-partition routing (DECISION AG AG-4).
 *
 * No DDL change: `commerce` already exists in config/queue.php, in
 * DatabaseQueueProvider::VALID_PARTITIONS and in the maintenance sweep, and
 * system.queue_jobs.queue_name carries no database-level constraint. What was missing was
 * anything able to PUT a job there — the dispatcher enqueued the literal 'content'.
 */
final class PartitionRouter implements PartitionRouterInterface
{
    /** @var array<string, string> domain → partition */
    private array $byDomain = [];

    /**
     * @param list<string> $validPartitions Partitions the queue provider will accept. Routing a
     *        domain to a partition the provider rejects would fail at enqueue time, deep inside
     *        a cron cycle; catching it at registration turns that into a boot-time error.
     */
    public function __construct(private readonly array $validPartitions)
    {
    }

    public function register(string $domain, string $partition): void
    {
        if ($domain === '') {
            throw new \InvalidArgumentException('PartitionRouter: domain must not be empty.');
        }

        if (! in_array($partition, $this->validPartitions, true)) {
            throw new \InvalidArgumentException(
                "PartitionRouter: '{$partition}' is not a valid queue partition. Valid: "
                . implode(', ', $this->validPartitions) . '.'
            );
        }

        // Re-registering the SAME mapping is harmless and idempotent; registering a different
        // partition for a domain already routed is a conflict and must fail loudly. Note the
        // reverse is deliberately allowed: several domains may share one partition.
        if (isset($this->byDomain[$domain]) && $this->byDomain[$domain] !== $partition) {
            throw new \LogicException(
                "Conflicting partition routing for domain '{$domain}': already routed to"
                . " '{$this->byDomain[$domain]}', attempted '{$partition}'."
            );
        }

        $this->byDomain[$domain] = $partition;
    }

    public function partitionForEventType(string $eventType): string
    {
        $domain = strstr($eventType, '.', true);

        if ($domain === false || $domain === '') {
            throw new \InvalidArgumentException(
                "PartitionRouter: '{$eventType}' is not a fully-qualified event type"
                . ' (<domain>.<aggregate>.<action>), so its partition cannot be resolved.'
            );
        }

        return $this->partitionForDomain($domain);
    }

    public function partitionForDomain(string $domain): string
    {
        if (! isset($this->byDomain[$domain])) {
            throw new \InvalidArgumentException(
                "PartitionRouter: no partition registered for domain '{$domain}'."
                . ' An unroutable event must surface here rather than be dropped or parked'
                . " in another domain's queue."
            );
        }

        return $this->byDomain[$domain];
    }

    public function hasDomain(string $domain): bool
    {
        return isset($this->byDomain[$domain]);
    }

    public function activePartitions(): array
    {
        return array_values(array_unique(array_values($this->byDomain)));
    }
}
