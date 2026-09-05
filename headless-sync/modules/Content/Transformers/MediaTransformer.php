<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Transformers;

use HSP\Core\Contracts\CanonicalModelInterface;
use HSP\Core\Contracts\TransformerInterface;
use HSP\Modules\Content\CanonicalModels\CanonicalMedia;
use HSP\Modules\Content\SourceModels\MediaSourceModel;

/**
 * Transforms a MediaSourceModel into a CanonicalMedia.
 *
 * Pure function: no side effects, no I/O, no DB, no WordPress global calls,
 * no statics, no randomness. Same input always produces same output (Doc 6 §24).
 *
 * The one real transformation is size-variant URL resolution: WordPress stores each
 * variant as a bare filename relative to the original's directory, and resolving that
 * here (write side, once) is what keeps consumers from reconstructing upload paths
 * themselves (Rule 6) and keeps the read path free of assembly work (Rule 2).
 */
final class MediaTransformer implements TransformerInterface
{
    /**
     * @param MediaSourceModel     $source  Source model from MediaExtractor
     * @param array<string, mixed> $context Event envelope metadata (unused by pure transform)
     */
    public function transform(object $source, array $context = []): CanonicalModelInterface
    {
        assert($source instanceof MediaSourceModel);

        return new CanonicalMedia(
            postId:       $source->postId,
            slug:         $source->slug,
            title:        trim($source->title),
            mimeType:     $source->mimeType,
            url:          $source->url,
            altText:      trim($source->altText),
            caption:      $source->caption,
            description:  $source->description,
            width:        $source->width,
            height:       $source->height,
            sizes:        $this->resolveSizeUrls($source->sizes, $source->url),
            attachedToId: $source->attachedToId,
            publishedAt:  $source->publishedAt,
            modifiedAt:   $source->modifiedAt,
            meta:         $source->meta,
        );
    }

    public function getCanonicalModelClass(): string
    {
        return CanonicalMedia::class;
    }

    /**
     * Resolve each size variant's filename against the original's directory.
     *
     * WordPress writes every generated size beside the original, so the variant URL is the
     * original URL with its basename replaced. With no original URL to resolve against
     * (a non-image, or an attachment whose file is missing) the variants are dropped rather
     * than published with a broken or half-built URL.
     *
     * @param array<string, array{file:string,width:int,height:int,mime_type:string}> $sizes
     * @return array<string, array{url:string,width:int,height:int,mime_type:string}>
     */
    private function resolveSizeUrls(array $sizes, string $originalUrl): array
    {
        if ($sizes === [] || $originalUrl === '') {
            return [];
        }

        $slash = strrpos($originalUrl, '/');
        if ($slash === false) {
            return [];
        }

        $base = substr($originalUrl, 0, $slash + 1);
        $out  = [];

        foreach ($sizes as $name => $size) {
            $out[$name] = [
                'url'       => $base . $size['file'],
                'width'     => $size['width'],
                'height'    => $size['height'],
                'mime_type' => $size['mime_type'],
            ];
        }

        return $out;
    }
}
