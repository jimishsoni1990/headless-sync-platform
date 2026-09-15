<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Queries;

use HSP\Core\Contracts\MediaReferenceProviderInterface;
use HSP\Core\Database\DatabaseConnectionInterface;
use HSP\Modules\Content\Resources\MediaReference;

/**
 * Content's implementation of the AG-10 media capability: attachment ids in, media objects out.
 *
 * This is the ONLY code that reads `content.media` on behalf of another module, which is the
 * point of the capability. Commerce holds attachment references it got from WooCommerce and
 * knows nothing about where attachment state lives; it hands the ids here and receives the
 * published shape back. No `Commerce → Content` import, no Commerce SQL naming `content.media`,
 * no duplicate Commerce attachment projection (AG-10).
 *
 * ONE QUERY, WHATEVER THE INPUT SIZE. A product page carrying twenty products with galleries
 * resolves in the same single round trip as one product with one image — the caller collects
 * every reference on the page first and asks once. `source_post_id` carries a UNIQUE index
 * (`uq_content_media_source_post_id`), so the `IN` list is index-backed and needs no new index
 * of its own.
 *
 * ADR-012: constructor injection. DECISION E: the shared delivery connection, no raw pg_* calls
 * and no fifth handle (DECISION L Ruling 0).
 */
final class MediaReferenceProvider implements MediaReferenceProviderInterface
{
    /**
     * The seven published fields plus the key to map them back by. Same columns the entity
     * providers alias as `fm_*` — one media contract, read two ways (MediaReference).
     */
    private const COLUMNS = 'source_post_id, slug, url, alt_text, mime_type, width, height, sizes_jsonb';

    public function __construct(private readonly DatabaseConnectionInterface $db)
    {
    }

    /**
     * @param  list<int> $sourceAttachmentIds
     * @return array<int, array<string,mixed>>
     */
    public function resolveMany(array $sourceAttachmentIds): array
    {
        // Normalised here so callers can pass what they have. A product with no featured image
        // stores 0, not null (WooCommerce's own "none"), and a gallery may legitimately repeat
        // an id the featured image already used — neither is an error, and neither should cost
        // a second lookup. Uniqueness is applied to the QUERY only; the caller's own list keeps
        // its duplicates and its order, because the result is a map it walks itself.
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $sourceAttachmentIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($ids === []) {
            return [];
        }

        $placeholders = [];
        foreach ($ids as $i => $_) {
            $placeholders[] = '$' . ($i + 1);
        }

        // deleted_at IS NULL: a tombstoned attachment resolves to nothing, exactly as the
        // entity featured-image join already treats one. DECISION I deletion semantics reach
        // the capability for free rather than being restated here.
        $rows = $this->db->query(
            'SELECT ' . self::COLUMNS . '
             FROM content.media
             WHERE source_post_id IN (' . implode(', ', $placeholders) . ')
               AND deleted_at IS NULL',
            $ids,
        );

        $resolved = [];

        foreach ($rows as $row) {
            $media = MediaReference::fromRow($row);

            if ($media !== null) {
                $resolved[(int) $row['source_post_id']] = $media;
            }
        }

        return $resolved;
    }
}
