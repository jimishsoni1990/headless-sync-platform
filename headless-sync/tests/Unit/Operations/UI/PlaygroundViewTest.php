<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\UI;

use HSP\Core\Contracts\Operations\EndpointDescriptor;
use HSP\Core\Operations\UI\PlaygroundView;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PlaygroundView — a PURE renderer of the API Playground (Doc 12 §15; ADR-050).
 *
 * Renders the Endpoint Explorer + Request Builder + Response Viewer from EndpointDescriptor
 * metadata (no hardcoded routes — ADR-050/ADR-052). No live GET happens here (ADR-053); the
 * view only records the ajax URL/nonce/action the minimal vanilla JS uses.
 */
final class PlaygroundViewTest extends TestCase
{
    private PlaygroundView $view;

    protected function setUp(): void
    {
        $this->view = new PlaygroundView();
    }

    /** @return EndpointDescriptor[] */
    private function endpoints(): array
    {
        return [
            new EndpointDescriptor('GET', '/posts', 'hsp/v1', 'Content', 'List posts.'),
            new EndpointDescriptor('GET', '/posts/{slug}', 'hsp/v1', 'Content', 'One post.'),
        ];
    }

    public function test_lists_endpoints_from_metadata_in_the_explorer(): void
    {
        $html = $this->view->render($this->endpoints(), 'http://x/wp-admin/admin-ajax.php', 'nonce-x', 'hsp_ops_execute');

        self::assertStringContainsString('Endpoint Explorer', $html);
        self::assertStringContainsString('/hsp/v1/posts', $html);
        self::assertStringContainsString('/hsp/v1/posts/{slug}', $html);
        self::assertStringContainsString('List posts.', $html);
    }

    public function test_builder_offers_each_endpoint_as_an_option_by_index(): void
    {
        $html = $this->view->render($this->endpoints(), 'http://x', 'n', 'a');

        self::assertStringContainsString('Request Builder', $html);
        self::assertStringContainsString('<option value="0">', $html);
        self::assertStringContainsString('<option value="1">', $html);
        self::assertStringContainsString('hsp-ops-execute', $html);
    }

    public function test_records_ajax_wiring_as_escaped_data_attributes(): void
    {
        $html = $this->view->render($this->endpoints(), 'http://x/wp-admin/admin-ajax.php', 'nonce-x', 'hsp_ops_execute');

        self::assertStringContainsString('data-ajax-url="http://x/wp-admin/admin-ajax.php"', $html);
        self::assertStringContainsString('data-nonce="nonce-x"', $html);
        self::assertStringContainsString('data-action="hsp_ops_execute"', $html);
        self::assertStringContainsString('hsp-ops-response', $html);
    }

    public function test_escapes_hostile_endpoint_metadata(): void
    {
        $endpoints = [new EndpointDescriptor('GET', '/x', 'hsp/v1', 'Content', '<img src=x onerror=1>')];

        $html = $this->view->render($endpoints, 'http://x', 'n', 'a');

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    public function test_empty_endpoint_list_renders_a_placeholder(): void
    {
        $html = $this->view->render([], 'http://x', 'n', 'a');

        self::assertStringContainsString('No endpoints registered.', $html);
    }
}
