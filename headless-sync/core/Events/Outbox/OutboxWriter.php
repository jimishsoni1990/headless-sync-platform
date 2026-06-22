<?php

declare(strict_types=1);

namespace HSP\Core\Events\Outbox;

use HSP\Core\Contracts\AggregateVersionCounterInterface;
use HSP\Core\Contracts\EventInterface;
use HSP\Core\Contracts\OutboxWriterInterface;
use HSP\Core\Events\Outbox\Exception\OutboxWriteException;

/**
 * Writes a new event envelope to wp_hsp_outbox immediately after the WordPress commit.
 *
 * DECISION 1: this write happens post-commit, outside any WordPress transaction.
 * The event_id is a UUIDv7 born here; the aggregate_version comes from the atomic
 * counter (DECISION 2 v1.1); the checksum is sha256 of the JSON-encoded payload.
 *
 * Write sequence:
 *   1. Increment aggregate_version counter (atomic MySQL round-trip).
 *   2. Compute checksum over canonical payload JSON.
 *   3. INSERT INTO wp_hsp_outbox … status='pending'.
 *   4. Return the populated OutboxEvent.
 */
final class OutboxWriter implements OutboxWriterInterface
{
    public function __construct(
        private readonly object                          $wpdb,
        private readonly AggregateVersionCounterInterface $versionCounter,
    ) {}

    public function write(
        string             $eventType,
        int                $eventVersion,
        string             $aggregateType,
        string             $aggregateId,
        array              $payload,
        string             $correlationId,
        ?string            $causationId,
        \DateTimeImmutable $sourceUpdatedAt,
    ): EventInterface {
        $id               = $this->uuidv7();
        $aggregateVersion = $this->versionCounter->next($aggregateType, $aggregateId);
        $createdAt        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($payloadJson === false) {
            throw new OutboxWriteException(
                "Failed to JSON-encode payload for event_type={$eventType}"
            );
        }

        $checksum = hash('sha256', $payloadJson);

        $table  = $this->wpdb->prefix . 'hsp_outbox';
        $result = $this->wpdb->insert(
            $table,
            [
                'id'                => $id,
                'event_type'        => $eventType,
                'event_version'     => $eventVersion,
                'aggregate_type'    => $aggregateType,
                'aggregate_id'      => $aggregateId,
                'aggregate_version' => $aggregateVersion,
                'source_updated_at' => $sourceUpdatedAt->format('Y-m-d H:i:s'),
                'checksum'          => $checksum,
                'correlation_id'    => $correlationId,
                'causation_id'      => $causationId,
                'payload'           => $payloadJson,
                'status'            => 'pending',
                'created_at'        => $createdAt->format('Y-m-d H:i:s'),
                'relayed_at'        => null,
            ],
            [
                '%s', '%s', '%d', '%s', '%s',
                '%d', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s',
            ],
        );

        if ($result === false) {
            throw new OutboxWriteException(
                "wp_hsp_outbox insert failed: {$this->wpdb->last_error}"
            );
        }

        return new OutboxEvent(
            id:               $id,
            eventType:        $eventType,
            eventVersion:     $eventVersion,
            aggregateType:    $aggregateType,
            aggregateId:      $aggregateId,
            aggregateVersion: $aggregateVersion,
            payload:          $payload,
            checksum:         $checksum,
            sourceUpdatedAt:  $sourceUpdatedAt,
            createdAt:        $createdAt,
            correlationId:    $correlationId,
            causationId:      $causationId,
        );
    }

    /**
     * Generate a UUIDv7 (time-ordered, random suffix) for the event_id.
     *
     * UUIDv7 is the platform-wide ID canon per ADR-015 (v1.1 canon).
     * Layout: 48-bit Unix epoch ms | version=7 | 12-bit random |
     *         variant=0b10 | 62-bit random.
     */
    private function uuidv7(): string
    {
        $ms    = (int) (microtime(true) * 1000);
        $bytes = random_bytes(10);

        $tsHex  = sprintf('%012x', $ms);
        $rand12 = (ord($bytes[0]) & 0x0f) << 8 | ord($bytes[1]);
        $b67hex = sprintf('%04x', 0x7000 | $rand12);
        $rand14 = (ord($bytes[2]) & 0x3f) << 8 | ord($bytes[3]);
        $b89hex = sprintf('%04x', 0x8000 | $rand14);
        $tailHex = bin2hex(substr($bytes, 4, 6));

        $hex = $tsHex . $b67hex . $b89hex . $tailHex;

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split($hex, 4));
    }
}
