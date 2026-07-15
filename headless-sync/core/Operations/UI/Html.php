<?php

declare(strict_types=1);

namespace HSP\Core\Operations\UI;

/**
 * Output-escaping helper for the server-rendered Operations Console (DECISION V (a)/(b)).
 *
 * The console UI is server-rendered PHP; every dynamic value written into a page must be
 * escaped at the point of output (WPCS security requirement at the WP-admin boundary —
 * DECISION V (b)). This helper centralizes that escaping so the renderer classes stay pure,
 * WordPress-free, and unit-testable: when WordPress is present its `esc_html`/`esc_attr`/
 * `esc_url` functions are used (the canonical WP escapers); outside WordPress (unit tests,
 * CLI) a faithful `htmlspecialchars`-based fallback is used so the SAME escaping guarantee
 * holds and the renderers can be asserted without loading WordPress.
 *
 * Renderers depend on this class only — never on WordPress functions directly — so the
 * "escape at output" guarantee is provable in a pure unit test (no WP runtime required).
 */
final class Html
{
    /**
     * Escape a value for HTML text context (element content).
     */
    public static function text(int|float|string|null $value): string
    {
        $string = self::stringify($value);

        if (function_exists('esc_html')) {
            return esc_html($string);
        }

        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a value for an HTML attribute context.
     */
    public static function attr(int|float|string|null $value): string
    {
        $string = self::stringify($value);

        if (function_exists('esc_attr')) {
            return esc_attr($string);
        }

        return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape a URL for an href/src attribute context.
     */
    public static function url(string $value): string
    {
        if (function_exists('esc_url')) {
            return esc_url($value);
        }

        // Fallback: strip control chars, then attribute-escape. Mirrors the intent of
        // esc_url (defence in depth) without requiring WordPress in unit tests.
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function stringify(int|float|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
