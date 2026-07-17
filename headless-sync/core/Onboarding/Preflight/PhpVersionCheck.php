<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding\Preflight;

use HSP\Core\Contracts\Onboarding\PreflightCheckInterface;
use HSP\Core\Contracts\Onboarding\PreflightResult;

/**
 * Preflight check 4 (DECISION W (f)): the PHP version meets the platform minimum.
 *
 * The minimum is injected (default 8.1, matching the plugin header "Requires PHP") so the single
 * source of truth stays at the wiring boundary rather than being hardcoded here. Pure environment
 * inspection — no PG handle.
 */
final class PhpVersionCheck implements PreflightCheckInterface
{
    public const KEY = 'php_version';

    /** Platform minimum PHP version (plugin header "Requires PHP: 8.1"). */
    public const DEFAULT_MINIMUM = '8.1';

    public function __construct(
        private readonly string $minimum = self::DEFAULT_MINIMUM,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function run(): PreflightResult
    {
        $current = PHP_VERSION;
        $passed  = version_compare($current, $this->minimum, '>=');

        return new PreflightResult(
            self::KEY,
            'PHP version',
            $passed,
            sprintf('PHP %s (minimum %s).', $current, $this->minimum),
            $passed
                ? ''
                : sprintf('Upgrade PHP to %s or newer (current: %s).', $this->minimum, $current),
        );
    }
}
