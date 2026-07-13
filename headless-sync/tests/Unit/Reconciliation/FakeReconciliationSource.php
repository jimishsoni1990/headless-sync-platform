<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Reconciliation;

use HSP\Core\Contracts\SourceState;
use HSP\Core\Contracts\WpReconciliationSourceInterface;

/**
 * Controllable fake WpReconciliationSourceInterface for ReconciliationService unit tests.
 *
 * WordPress state is a scripted in-memory map keyed by "{type}:{id}". No WP bootstrap.
 */
final class FakeReconciliationSource implements WpReconciliationSourceInterface
{
    /** @var string[] */
    public array $types = ['page', 'post', 'category'];

    /** @var array<string, list<string>> type → ascending ids */
    public array $ids = [];

    /** @var array<string, SourceState> "type:id" → state */
    public array $state = [];

    /** @var array<string, ?string> "type:id" → current checksum */
    public array $checksums = [];

    /** @var array<string, bool> "type:id" → pending-outbox flag */
    public array $pendingOutbox = [];

    public function getSupportedAggregateTypes(): array
    {
        return $this->types;
    }

    public function listAggregateIds(string $aggregateType, int $afterId, int $limit): array
    {
        $all = $this->ids[$aggregateType] ?? [];
        $out = [];
        foreach ($all as $id) {
            if ((int) $id > $afterId) {
                $out[] = $id;
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    public function getSourceState(string $aggregateType, string $aggregateId): SourceState
    {
        return $this->state["{$aggregateType}:{$aggregateId}"] ?? SourceState::absent();
    }

    public function computeCurrentChecksum(string $aggregateType, string $aggregateId): ?string
    {
        return $this->checksums["{$aggregateType}:{$aggregateId}"] ?? null;
    }

    public function hasPendingOutbox(string $aggregateType, string $aggregateId): bool
    {
        return $this->pendingOutbox["{$aggregateType}:{$aggregateId}"] ?? false;
    }

    // ---- scripting helpers ----

    public function withType(string $type): void
    {
        $this->types = [$type];
    }

    public function addLive(
        string $type,
        string $id,
        bool $public,
        ?\DateTimeImmutable $modifiedAt,
        ?string $checksum = null,
    ): void {
        $this->ids[$type][] = $id;
        $this->state["{$type}:{$id}"] = new SourceState(true, $public, $modifiedAt);
        if ($checksum !== null) {
            $this->checksums["{$type}:{$id}"] = $checksum;
        }
    }
}
