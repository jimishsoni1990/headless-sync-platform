<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\UI;

use HSP\Core\Operations\UI\Html;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Html output-escaping helper (DECISION V (b) — escape at output).
 *
 * WordPress's esc_* functions are stubbed in tests/bootstrap.php with htmlspecialchars-based
 * fallbacks, so these assertions hold both in and out of a WP runtime — the guarantee the
 * renderers rely on to stay WordPress-free and testable.
 */
final class HtmlTest extends TestCase
{
    public function test_text_escapes_html_special_characters(): void
    {
        $out = Html::text('<script>alert("x")</script>');

        self::assertStringNotContainsString('<script>', $out);
        self::assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_attr_escapes_quotes(): void
    {
        $out = Html::attr('a" onmouseover="evil()');

        // The double-quote that would break out of an attribute is entity-encoded.
        self::assertStringNotContainsString('"', $out);
        self::assertStringContainsString('&quot;', $out);
    }

    public function test_url_strips_control_characters_and_escapes(): void
    {
        $out = Html::url("javascript:alert(1)\x00");

        self::assertStringNotContainsString("\x00", $out);
    }

    public function test_null_and_numeric_values_are_stringified_safely(): void
    {
        self::assertSame('', Html::text(null));
        self::assertSame('42', Html::text(42));
        self::assertSame('3.5', Html::text(3.5));
    }
}
