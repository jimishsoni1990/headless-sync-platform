<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Admin;

use HSP\Core\Contracts\Operations\ConsoleWidget;
use HSP\Core\Operations\Admin\ConsoleAjaxController;
use HSP\Core\Operations\Admin\PlaygroundRequestExecutor;
use HSP\Core\Operations\Registries\ActionRegistry;
use HSP\Core\Operations\Registries\AssetRegistry;
use HSP\Core\Operations\Registries\NavigationRegistry;
use HSP\Core\Operations\Registries\PageRegistry;
use HSP\Core\Operations\Registries\WidgetRegistry;
use HSP\Core\Operations\Services\ConsoleStateStore;
use HSP\Core\Operations\Services\OperationsService;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Core\Operations\UI\DashboardView;
use HSP\Modules\Content\Operations\ContentEndpointProvider;
use HSP\Tests\Support\WpJsonHalt;
use HSP\Tests\Unit\Operations\Fakes\FakeQueueStatusProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConsoleAjaxController — the nonce/capability-protected poll + execute
 * endpoints (DECISION V (a)/(b); ADR-053).
 *
 * The controller is built ONLY from OperationsService + PlaygroundRequestExecutor; no
 * DatabaseConnectionInterface, reader, or concrete provider is reachable through it (ADR-053).
 * wp_send_json_* / check_ajax_referer are stubbed in tests/bootstrap.php so the boundary
 * (nonce + capability + sanitization + routing through OperationsService) is assertable.
 */
final class ConsoleAjaxControllerTest extends TestCase
{
    private OperationsService $operations;
    private ConsoleAjaxController $controller;

    protected function setUp(): void
    {
        $store       = new ConsoleStateStore();
        $coordinator = new RefreshCoordinator($store);
        $coordinator->addProvider(new FakeQueueStatusProvider('queue'));
        // Real content endpoint provider — endpoints selected by stable route key (not index).
        $coordinator->addProvider(new ContentEndpointProvider());

        $this->operations = new OperationsService(
            new PageRegistry(),
            new NavigationRegistry(),
            new WidgetRegistry(),
            new ActionRegistry(),
            new AssetRegistry(),
            $coordinator,
            $store,
        );

        $this->controller = new ConsoleAjaxController(
            $this->operations,
            new PlaygroundRequestExecutor(),
            new DashboardView(),
        );

        // Default: authorized. Reset boundary stubs.
        $GLOBALS['_hsp_stub_current_user_can'] = true;
        $GLOBALS['_hsp_stub_check_ajax_referer'] = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_hsp_stub_current_user_can'],
            $GLOBALS['_hsp_stub_check_ajax_referer'],
            $GLOBALS['_hsp_stub_rest_do_request'],
        );
        $_POST = [];
    }

    public function test_poll_verifies_the_nonce_and_returns_serialized_snapshots(): void
    {
        try {
            $this->controller->handlePoll();
            self::fail('expected wp_send_json_success to halt');
        } catch (WpJsonHalt $halt) {
            self::assertTrue($halt->success);
            self::assertArrayHasKey('snapshots', $halt->payload);
            self::assertArrayHasKey('queue', $halt->payload['snapshots']);
            self::assertSame(3, $halt->payload['snapshots']['queue']['depth']);
        }

        // The nonce was checked against the console nonce action (WPCS — DECISION V (b)).
        self::assertNotEmpty($GLOBALS['_hsp_stub_check_ajax_referer']);
        self::assertSame(ConsoleAjaxController::NONCE_ACTION, $GLOBALS['_hsp_stub_check_ajax_referer'][0][0]);
    }

    public function test_poll_denies_a_request_lacking_capability(): void
    {
        $GLOBALS['_hsp_stub_current_user_can'] = false;

        try {
            $this->controller->handlePoll();
            self::fail('expected wp_send_json_error to halt');
        } catch (WpJsonHalt $halt) {
            self::assertFalse($halt->success);
            self::assertSame(403, $halt->statusCode);
        }
    }

    public function test_execute_runs_a_live_get_through_endpoint_metadata(): void
    {
        $GLOBALS['_hsp_stub_rest_do_request'] = static fn (\WP_REST_Request $r): \WP_REST_Response
            => new \WP_REST_Response(['route' => $r->get_route()], 200);

        // Select GET /pages by its stable route key (order-independent).
        $_POST = ['endpoint' => 'GET /hsp/v1/pages', 'slug' => '', 'query' => 'limit=2'];

        try {
            $this->controller->handleExecute();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertTrue($halt->success);
            self::assertSame(200, $halt->payload['status']);
            self::assertSame('/hsp/v1/pages', $halt->payload['path']);
        }
    }

    public function test_execute_returns_400_for_an_unknown_endpoint_selection(): void
    {
        $_POST = ['endpoint' => 'GET /hsp/v1/does-not-exist'];

        try {
            $this->controller->handleExecute();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertFalse($halt->success);
            self::assertSame(400, $halt->statusCode);
        }
    }

    public function test_execute_sanitizes_the_slug_input(): void
    {
        $captured = null;
        $GLOBALS['_hsp_stub_rest_do_request'] = static function (\WP_REST_Request $r) use (&$captured): \WP_REST_Response {
            $captured = $r->get_route();

            return new \WP_REST_Response(null, 200);
        };

        // A hostile slug with tags; sanitize_text_field strips them at the boundary.
        $_POST = ['endpoint' => 'GET /hsp/v1/pages/{slug}', 'slug' => "he<script>llo", 'query' => ''];

        try {
            $this->controller->handleExecute();
        } catch (WpJsonHalt) {
            // ignore — we only care about what was dispatched.
        }

        self::assertIsString($captured);
        self::assertStringNotContainsString('<script>', $captured);
    }
}
