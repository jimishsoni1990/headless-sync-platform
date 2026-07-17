<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Preflight;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;

/**
 * Preflight check 1 (DECISION W (f)): the `pgsql` PHP extension is loaded.
 *
 * The whole delivery pipeline talks to PostgreSQL through libpq (pg_* / PostgresDatabaseConnection);
 * without the extension nothing downstream can run. Pure environment inspection — no PG handle.
 */
final class PgsqlExtensionCheck implements PreflightCheckInterface
{
    public const KEY = 'pgsql_extension';

    public function key(): string
    {
        return self::KEY;
    }

    public function run(): PreflightResult
    {
        $loaded = extension_loaded('pgsql');

        return new PreflightResult(
            self::KEY,
            'PostgreSQL PHP extension',
            $loaded,
            $loaded ? 'The pgsql extension is loaded.' : 'The pgsql extension is not loaded.',
            $loaded ? '' : 'Install and enable the PHP pgsql extension (e.g. php-pgsql), then restart PHP-FPM/your web server.',
        );
    }
}
