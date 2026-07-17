<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;
use HSP\Core\Onboarding\Backfill\BackfillGate;
use HSP\Core\Onboarding\Backfill\BackfillProgress;
use HSP\Core\Onboarding\Backfill\BackfillReader;
use HSP\Core\Onboarding\Backfill\BackfillService;
use HSP\Core\Onboarding\OnboardingConnectionProbe;
use HSP\Core\Onboarding\OnboardingRestController;
use HSP\Core\Onboarding\OnboardingState;
use HSP\Core\Onboarding\Preflight\MigrationsAppliedCheck;
use HSP\Core\Onboarding\PreflightRunner;
use HSP\Core\Reconciliation\ReconciliationService;
use HSP\Core\Replay\ReplayService;
use HSP\Tests\Unit\Content\Adapters\FakeDbConnection;
use HSP\Tests\Unit\Onboarding\Backfill\ScriptedConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconConnection;
use HSP\Tests\Unit\Reconciliation\FakeReconciliationSource;
use HSP\Tests\Unit\Replay\FakeReplayEmitter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OnboardingRestController — the WPCS-guarded JSON boundary the React app calls
 * (ONB-S1b; DECISION W (a)/(d)/(f); DECISION V (b)).
 *
 * Proves nonce verification, capability enforcement, the read-only preflight endpoint, and the
 * completion-flag round-trip GUARDED on preflight passing. wp_verify_nonce / current_user_can /
 * get_option / update_option are stubbed in tests/bootstrap.php.
 */
final class OnboardingRestControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_hsp_stub_options']      = [];
        $GLOBALS['_hsp_stub_valid_nonce']  = true;
        $GLOBALS['_hsp_stub_current_user_can'] = true;
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['_hsp_stub_options'],
            $GLOBALS['_hsp_stub_valid_nonce'],
            $GLOBALS['_hsp_stub_current_user_can'],
        );
    }

    public function test_preflight_returns_state_and_checks_when_authorized(): void
    {
        $controller = $this->controller(allPass: false);

        $response = $controller->handlePreflight($this->request());

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        self::assertSame(OnboardingStateInterface::PENDING, $data['state']);
        self::assertFalse($data['ok']);
        self::assertNotEmpty($data['checks']);
    }

    public function test_rejects_a_missing_or_invalid_nonce_with_401(): void
    {
        $GLOBALS['_hsp_stub_valid_nonce'] = false;

        $response = $this->controller(allPass: true)->handlePreflight($this->request());

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame(401, $response->data['status']);
    }

    public function test_rejects_an_insufficient_capability_with_403(): void
    {
        $GLOBALS['_hsp_stub_current_user_can'] = false;

        $response = $this->controller(allPass: true)->handleComplete($this->request());

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame(403, $response->data['status']);
    }

    public function test_complete_is_blocked_with_409_until_preflight_passes(): void
    {
        $controller = $this->controller(allPass: false);

        $response = $controller->handleComplete($this->request());

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame(409, $response->data['status']);
        // The completion flag must NOT have been written.
        self::assertArrayNotHasKey(OnboardingStateInterface::OPTION_NAME, $GLOBALS['_hsp_stub_options']);
    }

    public function test_complete_flips_the_option_to_complete_when_preflight_passes(): void
    {
        $controller = $this->controller(allPass: true);

        $response = $controller->handleComplete($this->request());

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        self::assertSame(OnboardingStateInterface::COMPLETE, $response->get_data()['state']);
        self::assertSame(
            OnboardingStateInterface::COMPLETE,
            $GLOBALS['_hsp_stub_options'][OnboardingStateInterface::OPTION_NAME],
        );
    }

    public function test_backfill_is_blocked_with_409_when_the_gate_is_not_ready(): void
    {
        // Stale heartbeat → gate blocks → 409 carrying the gate summary; nothing re-emitted.
        $controller = $this->backfillController(heartbeatAge: 999.0, migrations: self::ALL, inFlight: 0);

        $response = $controller->handleBackfill($this->request());

        self::assertInstanceOf(\WP_Error::class, $response);
        self::assertSame(409, $response->data['status']);
        self::assertArrayHasKey('gate', $response->data);
        self::assertFalse($response->data['gate']['ready']);
        // Not marked complete.
        self::assertArrayNotHasKey(OnboardingStateInterface::OPTION_NAME, $GLOBALS['_hsp_stub_options']);
    }

    public function test_progress_flips_to_complete_on_convergence(): void
    {
        // Empty corpus + zero in-flight → converged → completion flag flips → redirect to Operations.
        $controller = $this->backfillController(heartbeatAge: 5.0, migrations: self::ALL, inFlight: 0);

        $response = $controller->handleBackfillProgress($this->request());

        self::assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        self::assertTrue($data['complete']);
        self::assertSame('operations', $data['redirect']);
        self::assertSame(
            OnboardingStateInterface::COMPLETE,
            $GLOBALS['_hsp_stub_options'][OnboardingStateInterface::OPTION_NAME],
        );
    }

    public function test_progress_does_not_flip_complete_while_events_are_in_flight(): void
    {
        $controller = $this->backfillController(heartbeatAge: 5.0, migrations: self::ALL, inFlight: 2);

        $response = $controller->handleBackfillProgress($this->request());

        $data = $response->get_data();
        self::assertFalse($data['complete']);
        self::assertNull($data['redirect']);
        self::assertArrayNotHasKey(OnboardingStateInterface::OPTION_NAME, $GLOBALS['_hsp_stub_options']);
    }

    private const ALL = [
        '0002_create_system_events', '0003_create_system_queue_jobs',
        '0005_create_system_aggregate_versions', '0006_create_system_processed_events',
        '0008_create_system_schema_versions',
        '0002_create_content_pages', '0003_create_content_posts', '0004_create_content_taxonomies',
    ];

    /**
     * Build a controller whose backfill gate + progress read a scripted connection (empty corpus).
     *
     * @param list<string> $migrations
     */
    private function backfillController(float $heartbeatAge, array $migrations, int $inFlight): OnboardingRestController
    {
        // Read connection: heartbeat age, zero projection rows, scripted in-flight count.
        $conn = (new ScriptedConnection())
            ->on('system.worker_heartbeats', [['age' => $heartbeatAge]])
            ->on('FROM content.posts', [['c' => 0]])
            ->on('FROM content.pages', [['c' => 0]])
            ->on('FROM content.taxonomies', [['c' => 0]])
            ->on('behind', [['c' => $inFlight]]);

        $reader = new BackfillReader(fn (): ScriptedConnection => $conn);

        $migRows = array_map(static fn (string $n) => ['migration_name' => $n], $migrations);
        $probe   = new OnboardingConnectionProbe(
            fn (): ScriptedConnection => (new ScriptedConnection())->on('system.schema_versions', $migRows),
        );
        $gate = new BackfillGate($reader, new MigrationsAppliedCheck($probe), 60);

        $reconciliation = new ReconciliationService(
            new FakeReconConnection(),
            new FakeReconciliationSource(),
            new ReplayService(new FakeDbConnection(), [new FakeReplayEmitter()]),
        );

        // Empty WP source → expected_total 0.
        $progress = new BackfillProgress(new FakeReconciliationSource(), $reader);

        return new OnboardingRestController(
            new PreflightRunner($this->check('a', true)),
            new OnboardingState(),
            new BackfillService($gate, $reconciliation),
            $progress,
        );
    }

    private function controller(bool $allPass): OnboardingRestController
    {
        $runner = new PreflightRunner(
            $this->check('a', true),
            $this->check('b', $allPass),
        );

        return new OnboardingRestController(
            $runner,
            new OnboardingState(),
            $this->backfillService(),
            $this->backfillProgress(),
        );
    }

    /** Backfill service over fakes — constructible for the preflight/complete tests (not invoked). */
    private function backfillService(): BackfillService
    {
        $reader = new BackfillReader(fn (): ScriptedConnection => new ScriptedConnection());
        $probe  = new OnboardingConnectionProbe(fn (): ScriptedConnection => new ScriptedConnection());
        $gate   = new BackfillGate($reader, new MigrationsAppliedCheck($probe), 60);

        $reconciliation = new ReconciliationService(
            new FakeReconConnection(),
            new FakeReconciliationSource(),
            new ReplayService(new FakeDbConnection(), [new FakeReplayEmitter()]),
        );

        return new BackfillService($gate, $reconciliation);
    }

    private function backfillProgress(): BackfillProgress
    {
        return new BackfillProgress(
            new FakeReconciliationSource(),
            new BackfillReader(fn (): ScriptedConnection => new ScriptedConnection()),
        );
    }

    private function request(string $nonce = 'nonce-wp_rest'): \WP_REST_Request
    {
        $request = new \WP_REST_Request(['x' => 'y']);
        $request->set_header('X-WP-Nonce', $nonce);

        return $request;
    }

    private function check(string $key, bool $passed): PreflightCheckInterface
    {
        return new class ($key, $passed) implements PreflightCheckInterface {
            public function __construct(private string $key, private bool $passed) {}

            public function key(): string
            {
                return $this->key;
            }

            public function run(): PreflightResult
            {
                return new PreflightResult($this->key, $this->key, $this->passed, 'd', $this->passed ? '' : 'fix');
            }
        };
    }
}
