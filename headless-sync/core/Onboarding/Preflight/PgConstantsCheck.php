<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Preflight;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;

/**
 * Preflight check 2 (DECISION W (f)): the PostgreSQL connection constants are defined.
 *
 * The required HSP_PG_* credentials (DECISION O) must be resolvable before a connection can be
 * opened. The port has a documented default (5432, CredentialResolver), so only the four
 * required keys — HSP_PG_HOST / HSP_PG_DBNAME / HSP_PG_USER / HSP_PG_PASSWORD — are checked here.
 * A key satisfied via getenv() (Docker/CI) counts as defined, matching CredentialResolver's
 * define()→getenv() precedence (DECISION O). No PG handle is opened — this only inspects config.
 */
final class PgConstantsCheck implements PreflightCheckInterface
{
    public const KEY = 'pg_constants';

    /** The DECISION O required PG credentials (port defaults, so it is not required here). */
    private const REQUIRED = ['HSP_PG_HOST', 'HSP_PG_DBNAME', 'HSP_PG_USER', 'HSP_PG_PASSWORD'];

    public function key(): string
    {
        return self::KEY;
    }

    public function run(): PreflightResult
    {
        $missing = [];
        foreach (self::REQUIRED as $name) {
            if (! $this->isSet($name)) {
                $missing[] = $name;
            }
        }

        $passed = $missing === [];

        return new PreflightResult(
            self::KEY,
            'PostgreSQL connection constants',
            $passed,
            $passed
                ? 'All required HSP_PG_* connection constants are defined.'
                : 'Missing PostgreSQL connection constants: ' . implode(', ', $missing) . '.',
            $passed
                ? ''
                : 'Define the missing constants in wp-config.php, e.g. '
                    . "define('HSP_PG_HOST', 'localhost'); define('HSP_PG_DBNAME', '…'); "
                    . "define('HSP_PG_USER', '…'); define('HSP_PG_PASSWORD', '…');",
        );
    }

    /** A constant counts as set if defined() with a non-empty value OR present via getenv(). */
    private function isSet(string $name): bool
    {
        if (defined($name)) {
            $value = constant($name);
            if ($value !== null && $value !== '') {
                return true;
            }
        }

        $env = getenv($name);

        return $env !== false && $env !== '';
    }
}
