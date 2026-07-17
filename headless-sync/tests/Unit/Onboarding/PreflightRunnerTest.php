<?php

declare(strict_types=1);

namespace HSP\Tests\Unit\Onboarding;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;
use HSP\Core\Onboarding\PreflightRunner;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PreflightRunner (ONB-S1b; DECISION W (f)).
 *
 * The runner executes ALL checks (no short-circuit), gates progression on every check passing,
 * and fails closed on an empty check set. Uses in-memory fake checks.
 */
final class PreflightRunnerTest extends TestCase
{
    public function test_runs_every_check_in_order(): void
    {
        $runner = new PreflightRunner(
            $this->check('a', true),
            $this->check('b', false),
            $this->check('c', true),
        );

        $results = $runner->run();

        self::assertSame(['a', 'b', 'c'], array_map(static fn (PreflightResult $r) => $r->key, $results));
    }

    public function test_all_passed_is_true_only_when_every_check_passes(): void
    {
        self::assertTrue((new PreflightRunner($this->check('a', true), $this->check('b', true)))->allPassed());
        self::assertFalse((new PreflightRunner($this->check('a', true), $this->check('b', false)))->allPassed());
    }

    public function test_fails_closed_on_an_empty_check_set(): void
    {
        $runner = new PreflightRunner();

        self::assertFalse($runner->allPassed());
        self::assertFalse($runner->summary()['ok']);
        self::assertSame([], $runner->summary()['checks']);
    }

    public function test_summary_reports_ok_and_json_friendly_checks(): void
    {
        $summary = (new PreflightRunner($this->check('a', true), $this->check('b', false)))->summary();

        self::assertFalse($summary['ok']);
        self::assertCount(2, $summary['checks']);
        self::assertSame('a', $summary['checks'][0]['key']);
        self::assertArrayHasKey('remediation', $summary['checks'][1]);
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
                return new PreflightResult(
                    $this->key,
                    ucfirst($this->key),
                    $this->passed,
                    'detail',
                    $this->passed ? '' : 'fix it',
                );
            }
        };
    }
}
