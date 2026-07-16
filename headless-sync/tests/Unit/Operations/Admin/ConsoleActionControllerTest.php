<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Operations\Admin;

use HSP\Core\Contracts\Operations\ConsoleAction;
use HSP\Core\Observability\StructuredLogger;
use HSP\Core\Operations\Admin\ConsoleActionController;
use HSP\Core\Operations\Registries\ActionRegistry;
use HSP\Core\Operations\Registries\AssetRegistry;
use HSP\Core\Operations\Registries\NavigationRegistry;
use HSP\Core\Operations\Registries\PageRegistry;
use HSP\Core\Operations\Registries\WidgetRegistry;
use HSP\Core\Operations\Services\ConsoleStateStore;
use HSP\Core\Operations\Services\OperationsActionService;
use HSP\Core\Operations\Services\OperationsService;
use HSP\Core\Operations\Services\RefreshCoordinator;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Core\Workers\Strategies\ReconciliationWorkerStrategy;
use HSP\Core\Workers\Strategies\ReplayWorkerStrategy;
use HSP\Tests\Support\WpJsonHalt;
use HSP\Tests\Unit\Operations\Fakes\RecordingReplayEmitter;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReaderConnection;
use HSP\Tests\Unit\Operations\Fakes\ScriptedReconciliationSource;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ConsoleActionController — the OPSC-S4 wp-admin ACTION boundary
 * (DECISION V (b)/(d); ADR-053).
 *
 * The controller is built ONLY from OperationsService (descriptor lookup) + OperationsActionService
 * (delegation) — no DatabaseConnectionInterface / reader / provider is reachable through it. Every
 * request enforces, at the boundary: nonce (check_ajax_referer), the descriptor's capability
 * (current_user_can), CONFIRMATION when the descriptor requires it, and input sanitization. It
 * delegates through OperationsActionService, which routes only to the ratified worker strategies.
 * wp_send_json_* / check_ajax_referer / current_user_can are stubbed in tests/bootstrap.php.
 */
final class ConsoleActionControllerTest extends TestCase
{
    private ActionRegistry $actionRegistry;
    private RecordingReplayEmitter $emitter;
    private ConsoleActionController $controller;

    protected function setUp(): void
    {
        $store       = new ConsoleStateStore();
        $coordinator = new RefreshCoordinator($store);

        $this->actionRegistry = new ActionRegistry();
        $this->actionRegistry->register(new ConsoleAction('replay', 'Replay', 'manage_options', confirmationRequired: true));
        $this->actionRegistry->register(new ConsoleAction('reconcile', 'Reconcile', 'manage_options', confirmationRequired: true));

        $operations = new OperationsService(
            new PageRegistry(),
            new NavigationRegistry(),
            new WidgetRegistry(),
            $this->actionRegistry,
            new AssetRegistry(),
            $coordinator,
            $store,
        );

        // Real action service over a write-spy connection + recording emitter (thin-delegator path).
        $conn          = new ScriptedReaderConnection();
        $this->emitter = new RecordingReplayEmitter();
        $replayService = new ReplayService($conn, [$this->emitter]);
        $reconService  = new ReconciliationService(
            $conn,
            new ScriptedReconciliationSource('post', ['101']),
            $replayService,
        );
        $actions = new OperationsActionService(
            new ReplayWorkerStrategy($replayService),
            new ReconciliationWorkerStrategy($reconService),
            new StructuredLogger(static function (): void {}),
        );

        $this->controller = new ConsoleActionController($operations, $actions);

        $GLOBALS['_hsp_stub_current_user_can']   = true;
        $GLOBALS['_hsp_stub_check_ajax_referer'] = [];
        $_POST = [];
        // The action endpoint is POST-only; default the request method to POST for the happy paths.
        $_SERVER['REQUEST_METHOD'] = 'POST';
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_hsp_stub_current_user_can'],
            $GLOBALS['_hsp_stub_check_ajax_referer'],
            $_SERVER['REQUEST_METHOD'],
        );
        $_POST = [];
    }

    public function test_confirmed_replay_verifies_nonce_and_delegates(): void
    {
        $_POST = [
            'op_action'      => 'replay',
            'confirm'        => '1',
            'mode'           => 'entity',
            'aggregate_type' => 'post',
            'aggregate_id'   => '42',
        ];

        try {
            $this->controller->handleInvoke();
            self::fail('expected wp_send_json_success to halt');
        } catch (WpJsonHalt $halt) {
            self::assertTrue($halt->success);
            self::assertSame('replay', $halt->payload['action']);
            self::assertSame(1, $halt->payload['count']);
        }

        // Nonce was checked against the console nonce action (WPCS — DECISION V (b)).
        self::assertNotEmpty($GLOBALS['_hsp_stub_check_ajax_referer']);
        self::assertSame(ConsoleActionController::NONCE_ACTION, $GLOBALS['_hsp_stub_check_ajax_referer'][0][0]);

        // Delegation reached the sole repair path (re-emission).
        self::assertCount(1, $this->emitter->emitted);
    }

    public function test_action_without_confirmation_is_refused(): void
    {
        // Descriptor requires confirmation; omitting it must be refused BEFORE any delegation.
        $_POST = [
            'op_action'      => 'replay',
            'mode'           => 'entity',
            'aggregate_type' => 'post',
            'aggregate_id'   => '42',
        ];

        try {
            $this->controller->handleInvoke();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertFalse($halt->success);
            self::assertSame(400, $halt->statusCode);
        }

        self::assertCount(0, $this->emitter->emitted); // never delegated
    }

    public function test_non_post_request_is_rejected_before_anything_else(): void
    {
        // A state-changing action must never be driven by a GET (DECISION V (b) hardening).
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [
            'op_action'      => 'replay',
            'confirm'        => '1',
            'mode'           => 'entity',
            'aggregate_type' => 'post',
            'aggregate_id'   => '42',
        ];

        try {
            $this->controller->handleInvoke();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertFalse($halt->success);
            self::assertSame(400, $halt->statusCode);
        }

        // Rejected BEFORE the nonce check and BEFORE any delegation.
        self::assertEmpty($GLOBALS['_hsp_stub_check_ajax_referer'], 'nonce not checked on a non-POST');
        self::assertCount(0, $this->emitter->emitted, 'no delegation on a non-POST');
    }

    public function test_action_denied_without_capability(): void
    {
        $GLOBALS['_hsp_stub_current_user_can'] = false;

        $_POST = ['op_action' => 'reconcile', 'confirm' => '1', 'mode' => 'drift'];

        try {
            $this->controller->handleInvoke();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertFalse($halt->success);
            self::assertSame(403, $halt->statusCode);
        }

        self::assertCount(0, $this->emitter->emitted);
    }

    public function test_unregistered_action_returns_400(): void
    {
        // Flush Queue / Restart Workers are not registered (DECISION V (e)/(f)) → unknown here.
        $_POST = ['op_action' => 'flush_queue', 'confirm' => '1'];

        try {
            $this->controller->handleInvoke();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertFalse($halt->success);
            self::assertSame(400, $halt->statusCode);
        }
    }

    public function test_confirmed_reconcile_drift_delegates_and_repairs(): void
    {
        $_POST = ['op_action' => 'reconcile', 'confirm' => 'true', 'mode' => 'drift'];

        try {
            $this->controller->handleInvoke();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertTrue($halt->success);
            self::assertSame('reconcile', $halt->payload['action']);
            self::assertSame(1, $halt->payload['count']);
        }

        self::assertCount(1, $this->emitter->emitted);
    }

    public function test_invalid_params_return_400_not_500(): void
    {
        // Confirmed + capable, but entity replay missing its aggregate id → service throws
        // InvalidArgumentException, which the boundary maps to a 400.
        $_POST = ['op_action' => 'replay', 'confirm' => '1', 'mode' => 'entity'];

        try {
            $this->controller->handleInvoke();
            self::fail('expected halt');
        } catch (WpJsonHalt $halt) {
            self::assertFalse($halt->success);
            self::assertSame(400, $halt->statusCode);
        }
    }
}
