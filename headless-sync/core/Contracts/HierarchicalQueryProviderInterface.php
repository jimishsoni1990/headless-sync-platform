<?php

declare(strict_types=1);

namespace HSP\Core\Contracts;

/**
 * Capability contract for resources whose public identity is a HIERARCHICAL PATH rather than a
 * bare slug (DECISION AD, resolving FLAG-PAGESLUG-1).
 *
 * WordPress enforces slug uniqueness for hierarchical post types WITHIN a parent, not globally
 * (`wp_unique_post_slug()` scopes by `post_parent`), so `/about/team` and `/services/team` are
 * both legal and both project a row whose slug is `team`. A bare-slug lookup cannot tell them
 * apart; the full ancestor path can, and it is also the address a headless front end already
 * holds, which is the point — generated page URLs should match the WordPress permalink structure.
 *
 * This is an ADDITIVE capability, deliberately NOT folded into
 * {@see QueryProviderInterface::findBySlug()}: posts, categories, tags and media are flat, their
 * slugs are unique within their own projection, and bare-slug lookup remains the correct and
 * complete semantic for them. Only hierarchical resources declare this interface, and a provider
 * that declares it also implements {@see QueryProviderInterface} — path lookup is an extra way in,
 * never a replacement for the generic one.
 *
 * Core owns this contract; modules own the implementations (Rule 5).
 */
interface HierarchicalQueryProviderInterface
{
    /**
     * Return the single projection row whose FULL ancestor path equals $path, or null.
     *
     * $path is `/`-separated, without leading or trailing separators, each segment an
     * already-sanitized slug — e.g. `about`, `about/team`, `company/about/team`. A one-segment
     * path therefore addresses a TOP-LEVEL resource and nothing else.
     *
     * Implementations MUST:
     *   - Match the path EXACTLY. A request whose ancestry does not match resolves to null even
     *     when some other resource carries the same leaf slug — no leaf fallback lives here
     *     (the deprecated v1 fallback, where one exists, belongs at the REST boundary).
     *   - Apply the public-set predicate (`status = 'publish' AND deleted_at IS NULL`) to the
     *     REQUESTED resource only. Ancestors are structural path components, not content being
     *     served, so an ancestor's own visibility must not gate a published descendant.
     *   - Resolve at read time from projected parent relationships. No stored/derived path
     *     column, no path cache, no new persistence.
     *   - Resolve in a single query — no N+1 walk — and be cycle- and depth-safe, so corrupt
     *     hierarchy data cannot produce an unbounded traversal.
     *   - Never query WordPress (ADR-040).
     *
     * @return array<string,mixed>|null
     */
    public function findByPath(string $path): ?array;
}
