<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;

/**
 * Runs the ordered onboarding preflight checks and aggregates their results (ONB-S1b;
 * DECISION W (f)).
 *
 * The hard-blocking checks are injected in display order (four environment checks in ONB-S1b —
 * DECISION W (f) amended v1.22; the migration-state check moved to ONB-S2). The runner executes
 * ALL of them (it never short-circuits on the first failure) so the operator sees the full
 * prerequisite picture at once; {@see allPassed()} is the single gate on progression. Checks
 * never throw for a failed condition (they report a PreflightResult), so the runner does not
 * swallow exceptions
 * — a thrown exception here would be a genuine bug, not an expected failure.
 *
 * Delegate-only (DECISION W (e)): the runner owns no PG handle; DB-touching checks reuse the
 * delivery handle via OnboardingConnectionProbe.
 */
final class PreflightRunner
{
    /** @var list<PreflightCheckInterface> */
    private readonly array $checks;

    public function __construct(PreflightCheckInterface ...$checks)
    {
        $this->checks = array_values($checks);
    }

    /**
     * Run every check in order.
     *
     * @return list<PreflightResult>
     */
    public function run(): array
    {
        $results = [];
        foreach ($this->checks as $check) {
            $results[] = $check->run();
        }

        return $results;
    }

    /**
     * True only when every check passes — the hard gate on onboarding progression
     * (DECISION W (f)). An empty check set is treated as NOT passed (fail closed), so a
     * misconfigured runner can never silently unblock onboarding.
     */
    public function allPassed(): bool
    {
        if ($this->checks === []) {
            return false;
        }

        foreach ($this->run() as $result) {
            if (! $result->passed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Run all checks and return a JSON-friendly summary for the onboarding client.
     *
     * @return array{ok:bool,checks:list<array{key:string,label:string,passed:bool,detail:string,remediation:string}>}
     */
    public function summary(): array
    {
        $results = $this->run();
        $ok      = true;
        $checks  = [];

        foreach ($results as $result) {
            $ok       = $ok && $result->passed;
            $checks[] = $result->toArray();
        }

        // Fail closed on an empty check set (mirrors allPassed()).
        if ($results === []) {
            $ok = false;
        }

        return ['ok' => $ok, 'checks' => $checks];
    }
}
