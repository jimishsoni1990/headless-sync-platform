<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Serializes content.media projection rows to the /hsp/v1/media response contract.
 *
 * Authority: Doc 9 §11 — serialization, formatting, contract shaping, response
 * consistency. No business logic. ADR-040 — no internal columns leaked (id UUID,
 * source_post_id, checksum, synced_at, created_at are not exposed).
 * ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Contract fields exposed:
 *   slug, title, mime_type, url, alt_text, caption, description, width, height,
 *   sizes, attached_to_id, published_at, updated_at, meta
 *
 * sizes_jsonb already holds resolved absolute URLs (the transformer did that work write-side),
 * so serialization is a decode — the read path assembles nothing (Rule 2).
 * Timestamps are returned as ISO-8601 strings (UTC).
 */
final class MediaResource implements ResourceInterface
{
    public function toArray(array $row): array
    {
        return [
            'slug'           => $row['slug'],
            'title'          => $row['title'],
            'mime_type'      => $row['mime_type'],
            'url'            => $row['url'],
            'alt_text'       => $row['alt_text'] ?? '',
            'caption'        => $row['caption'] ?? '',
            'description'    => $row['description'] ?? '',
            'width'          => isset($row['width']) ? (int) $row['width'] : 0,
            'height'         => isset($row['height']) ? (int) $row['height'] : 0,
            'sizes'          => $this->decodeJson($row['sizes_jsonb'] ?? null),
            'attached_to_id' => isset($row['attached_to_id']) ? (int) $row['attached_to_id'] : 0,
            'published_at'   => $this->normaliseTimestamp($row['published_at'] ?? null),
            'updated_at'     => $this->normaliseTimestamp($row['updated_at'] ?? null),
            'meta'           => $this->decodeJson($row['meta_jsonb'] ?? null),
        ];
    }

    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map($this->toArray(...), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }

    /** Normalise a DB timestamp string to ISO-8601 UTC (or null). */
    private function normaliseTimestamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
                ->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, associative: true);
        return is_array($decoded) ? $decoded : [];
    }
}
