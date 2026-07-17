<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Preflight;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;
use HSP\Core\Onboarding\OnboardingConnectionProbe;

/**
 * Preflight check 3 (DECISION W (f)): PostgreSQL is reachable (a live connection succeeds).
 *
 * Reachability is proven by a single SELECT round-trip through the EXISTING delivery
 * DatabaseConnectionInterface (via {@see OnboardingConnectionProbe}) — onboarding opens no PG
 * handle of its own (DECISION W (e); DECISION K reuse; no fifth handle — L Ruling 0; no new pg_*
 * wrapper — DECISION E). The probe swallows connection failure and returns false, so this check
 * reports a hard block with remediation rather than throwing.
 */
final class PgReachableCheck implements PreflightCheckInterface
{
    public const KEY = 'pg_reachable';

    public function __construct(
        private readonly OnboardingConnectionProbe $probe,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function run(): PreflightResult
    {
        $reachable = $this->probe->isReachable();

        return new PreflightResult(
            self::KEY,
            'PostgreSQL reachable',
            $reachable,
            $reachable ? 'A live PostgreSQL connection succeeded.' : 'Could not connect to PostgreSQL.',
            $reachable
                ? ''
                : 'Verify the PostgreSQL server is running and the HSP_PG_* credentials are correct '
                    . 'and reachable from this host (network/firewall, host, port, database, user, password).',
        );
    }
}
