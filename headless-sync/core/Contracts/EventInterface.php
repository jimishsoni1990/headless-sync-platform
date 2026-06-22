<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Represents a fully-enveloped domain event as it flows through the platform.
 *
 * Column mapping (OPEN-5 / OPEN-6 v1.3):
 *   getId()              → system.events.id (= wp_hsp_outbox.id; preserved on relay)
 *   getEventType()       → system.events.event_type (fully-qualified — OPEN-1)
 *   getEventVersion()    → system.events.event_version
 *   getAggregateType()   → system.events.aggregate_type
 *   getAggregateId()     → system.events.aggregate_id
 *   getAggregateVersion()→ system.events.aggregate_version (OPEN-5)
 *   getPayload()         → system.events.payload
 *   getChecksum()        → system.events.checksum (traceability only — DECISION 3)
 *   getSourceUpdatedAt() → system.events.source_updated_at (OPEN-5 / OPEN-6 v1.3)
 *   getCreatedAt()       → system.events.created_at (capture time; preserved on relay)
 *   getCorrelationId()   → system.events.correlation_id (OPEN-5)
 *   getCausationId()     → system.events.causation_id (NULL for root events — Doc 8 §19-20)
 */
interface EventInterface
{
    /** UUIDv7 born at outbox write; preserved unchanged through relay. */
    public function getId(): string;

    /** Fully-qualified <domain>.<aggregate>.<action> — OPEN-1. */
    public function getEventType(): string;

    public function getEventVersion(): int;

    public function getAggregateType(): string;

    public function getAggregateId(): string;

    /** Per-aggregate monotonic counter from wp_hsp_aggregate_counters — DECISION 2. */
    public function getAggregateVersion(): int;

    /** @return array<string, mixed> */
    public function getPayload(): array;

    /** sha256 of canonical payload; traceability only — DECISION 3. */
    public function getChecksum(): string;

    /** UTC timestamp of the WordPress entity's last edit — OPEN-5 / OPEN-6 v1.3. */
    public function getSourceUpdatedAt(): \DateTimeImmutable;

    /** Capture time; preserved from wp_hsp_outbox.created_at on relay — OPEN-6 v1.3. */
    public function getCreatedAt(): \DateTimeImmutable;

    public function getCorrelationId(): string;

    /** NULL for root events (Doc 8 §19-20). */
    public function getCausationId(): ?string;
}
