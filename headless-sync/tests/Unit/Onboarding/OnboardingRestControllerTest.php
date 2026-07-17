<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;
use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;
use HSP\Core\Onboarding\OnboardingRestController;
use HSP\Core\Onboarding\OnboardingState;
use HSP\Core\Onboarding\PreflightRunner;
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

    private function controller(bool $allPass): OnboardingRestController
    {
        $runner = new PreflightRunner(
            $this->check('a', true),
            $this->check('b', $allPass),
        );

        return new OnboardingRestController($runner, new OnboardingState());
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
