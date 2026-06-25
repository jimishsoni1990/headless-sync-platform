<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Resources;

use HSP\Core\Contracts\ResourceInterface;

/**
 * Serializes content.posts projection rows to the /hsp/v1/posts response contract.
 *
 * Authority: Doc 9 §11 — serialization only; no business logic. ADR-040 — no
 * internal columns leaked (id UUID, source_post_id, checksum, synced_at, created_at,
 * meta_jsonb internals not exposed). ADR-038 — transport-agnostic; no WP_REST_* types.
 *
 * Contract fields exposed:
 *   slug, title, content, excerpt, status, author, published_at, updated_at, meta
 *
 * meta_jsonb is decoded from the JSON string the DB driver returns; exposed as 'meta'.
 * Timestamps are returned as ISO-8601 strings (UTC).
 */
final class PostResource implements ResourceInterface
{
    public function toArray(array $row): array
    {
        return [
            'slug'        => $row['slug'],
            'title'       => $row['title'],
            'content'     => $row['content'],
            'excerpt'     => $row['excerpt'] ?? '',
            'status'      => $row['status'],
            'author'      => $row['author'] ?? '',
            'published_at' => $this->normaliseTimestamp($row['published_at'] ?? null),
            'updated_at'  => $this->normaliseTimestamp($row['updated_at'] ?? null),
            'meta'        => $this->decodeMeta($row['meta_jsonb'] ?? null),
        ];
    }

    public function toCollection(array $rows, ?string $nextCursor): array
    {
        return [
            'data'        => array_values(array_map($this->toArray(...), $rows)),
            'next_cursor' => $nextCursor,
        ];
    }

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
    private function decodeMeta(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, associative: true);
        return is_array($decoded) ? $decoded : [];
    }
}
