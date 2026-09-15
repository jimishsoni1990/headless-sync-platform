<?php

declare(strict_types=1);

namespace HSP\Modules\Content;

/**
 * The post statuses the public delivery API serves (OPEN-10).
 *
 * ONE semantic source for the `?status=` filter's allowed value set, shared by the two places
 * that must agree about it: `ContentRestRegistrar` (which rejects anything outside the set at
 * the WordPress boundary) and `ContentEndpointProvider` (which publishes it as the parameter's
 * OpenAPI `enum`). The set used to live as a private constant inside the REST registrar, so the
 * published contract could only describe it in prose — the drift FLAG-RESTARGDRIFT-1 is about.
 *
 * A transport-agnostic metadata provider must not import the WordPress-boundary registrar to
 * read a list of strings (ADR-038), hence a module-owned holder rather than a public constant on
 * one of them. Mirrors `HSP\Modules\Commerce\ProductScope`, which already plays this role for
 * the Commerce `?type=` filter.
 *
 * Module-owned by design (Rule 5): `core/` never learns what a Content status is.
 */
final class PublicStatus
{
    private function __construct()
    {
    }

    public const PUBLISH = 'publish';

    /**
     * The complete public set.
     *
     * Only `publish`. Everything else — draft, pending, private, future, trash — is not part of
     * the published set, and `attachment`'s `inherit` is outside it too, which is why the media,
     * category and tag listings offer no status filter at all rather than a filter that cannot
     * match.
     *
     * @var list<string>
     */
    public const SET = [self::PUBLISH];
}
