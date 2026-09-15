<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Resources;

use HSP\Core\Delivery\JsonMap;

/**
 * The one rule for turning a `content.media` row into the platform's public media object.
 *
 * It existed twice before this class did — privately in `PostResource` and again in
 * `PageResource`, character for character — and Finding 011 was about to add a third and fourth
 * copy for Commerce products and galleries. Four copies of one contract is four chances for one
 * of them to drift, which is how the same attachment ends up published with `alt` in one place
 * and `alt_text` in another. Content owns attachment delivery (AG-10), so Content owns the
 * shape, in one readable place, and everything that publishes an attachment reads it from here.
 *
 * `url` is `content.media.url` — the absolute URL the transformer resolved write-side. There is
 * no second URL column to choose between, so there is no selection rule to duplicate either: a
 * consumer of a product image and a consumer of a post's featured image are looking at the same
 * value produced the same way.
 *
 * A COLUMN PREFIX rather than two shapes. The entity query providers LEFT JOIN media and alias
 * the columns `fm_*` to keep them apart from the entity's own; the bulk reference provider
 * selects them unaliased. Same seven fields either way — the prefix is where they were read
 * from, not what they mean.
 */
final class MediaReference
{
    /**
     * Shape one media row, or null when there is nothing to shape.
     *
     * NULL ON A MISSING URL, deliberately, and it is the single test for every no-image case:
     * no image set, the attachment never projected, the attachment soft-deleted, or the LEFT
     * JOIN found nothing. A soft reference (ADR-013) can always dangle, and a media object
     * without a URL is of no use to the consumer that asked for an image — so the contract
     * answers null rather than publishing a husk with empty strings in it.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    public static function fromRow(array $row, string $prefix = ''): ?array
    {
        $url = (string) ($row[$prefix . 'url'] ?? '');

        if ($url === '') {
            return null;
        }

        return [
            'slug'      => (string) ($row[$prefix . 'slug'] ?? ''),
            'url'       => $url,
            'alt_text'  => (string) ($row[$prefix . 'alt_text'] ?? ''),
            'mime_type' => (string) ($row[$prefix . 'mime_type'] ?? ''),
            'width'     => (int) ($row[$prefix . 'width'] ?? 0),
            'height'    => (int) ($row[$prefix . 'height'] ?? 0),
            'sizes'     => JsonMap::decode($row[$prefix . 'sizes_jsonb'] ?? null),
        ];
    }
}
