<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Immutable descriptor of one Operations Console static asset (Doc 12 §4 Asset Registry).
 *
 * The MVP console is server-rendered PHP + minimal vanilla JS with NO node/npm/bundler
 * toolchain and NO shipped JS/TS bundle (DECISION V (a)). The Asset Registry therefore
 * discovers only hand-authored, self-contained static files (a small vanilla `.js` for
 * polling, a `.css`) that the admin pages enqueue in a later session. There is no build
 * step and no `resources/` build config in this or any console session.
 *
 * Fields:
 *   $handle    — stable enqueue handle, unique within the Asset Registry.
 *   $type      — 'script' or 'style'.
 *   $relPath   — path to the static file relative to the plugin root; the enqueue happens
 *                at the wp-admin boundary later — this descriptor records the file only.
 *   $pageSlug  — slug of the ConsolePage the asset is enqueued for (page-scoped enqueue).
 */
final class ConsoleAsset
{
    public const TYPE_SCRIPT = 'script';
    public const TYPE_STYLE  = 'style';

    public function __construct(
        public readonly string $handle,
        public readonly string $type,
        public readonly string $relPath,
        public readonly string $pageSlug,
    ) {}
}
