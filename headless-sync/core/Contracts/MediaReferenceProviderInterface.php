<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Resolves WordPress attachment references into the platform's public media object, in BULK.
 *
 * The AG-10 capability contract, and the whole of it. `content.media` remains the single
 * attachment projection; a module that stores attachment REFERENCES (Commerce products carry
 * `featured_media_id` and `gallery_media_ids`) resolves them through this interface rather than
 * importing a sibling module or querying its table. Core declares the shape and nothing else —
 * it names no implementation, and the Content module is the one that registers one.
 *
 * BULK BY CONTRACT. There is deliberately no `resolveOne()`. A product listing carrying a
 * featured image and a gallery each would otherwise cost one lookup per image, which AG-10
 * prohibits by name; a caller that needs a single reference passes a one-element list. The
 * method is the entire surface: no filtering, no ordering, no SQL fragments, no general
 * "fetch a Content entity" gateway. Keeping it this narrow is what stops the capability from
 * becoming a back door into another module's query layer.
 *
 * OPTIONAL BY CONTRACT. No binding for this interface is guaranteed to exist — Content may be
 * unavailable while another module is active. Consumers accept it as a NULLABLE constructor
 * dependency and degrade; they must never require it to boot, synchronise or serve (AG-10
 * independence clause).
 */
interface MediaReferenceProviderInterface
{
    /**
     * Resolve source attachment ids to public media objects, keyed by the id that was asked for.
     *
     * KEYED BY SOURCE ID, not returned as a list, because the caller owns the order. A gallery
     * is an ordered sequence — `[124, 126, 125]` is not the same gallery as `[124, 125, 126]` —
     * and the database returns rows in whatever order it likes. Handing back a map lets the
     * caller walk its own references and look each one up, so source order survives the round
     * trip without anyone sorting anything.
     *
     * UNRESOLVED IDS ARE ABSENT FROM THE MAP, never present as null. A reference resolves to
     * nothing when the attachment was never projected, was soft-deleted (tombstoned), or simply
     * does not exist — the same three cases the Content featured-image join already collapses
     * (ADR-013 soft references). The caller decides what absence means for its contract.
     *
     * An infrastructure failure is NOT absence: implementations let a database exception
     * propagate rather than returning an empty map, so a broken connection cannot masquerade
     * as a store with no images.
     *
     * The returned media object is the SAME shape Content publishes as `featured_media`:
     * `{slug, url, alt_text, mime_type, width, height, sizes}`. One representation of an
     * attachment platform-wide — the implementation owns it, and no consumer reshapes it.
     *
     * @param  list<int> $sourceAttachmentIds WordPress attachment ids; 0, negatives and
     *                                        duplicates are the caller's convenience, not an
     *                                        error — implementations normalise them away.
     * @return array<int, array<string,mixed>> source attachment id => media object
     */
    public function resolveMany(array $sourceAttachmentIds): array;
}
