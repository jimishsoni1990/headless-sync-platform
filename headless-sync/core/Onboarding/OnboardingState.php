<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

use HSP\Core\Contracts\Onboarding\OnboardingStateInterface;

/**
 * The `hsp_onboarding_state` WordPress option round-trip (ONB-S1b; DECISION W (d)).
 *
 * Completion state lives in exactly ONE WP option in MySQL — no schema migration, no new table
 * or column. This class is the single read/write seam for it. Values are constrained to the
 * lifecycle set (PENDING / COMPLETE for the MVP); ONB-S2 owns the backfill transitions.
 *
 * WordPress boundary: get_option / update_option are WP entry points (DECISION V (b)); the
 * option name is a fixed constant and values are validated against the known set before write,
 * so there is no untrusted input reaching the option. No PostgreSQL access (DECISION W (e)).
 *
 * When WordPress option functions are unavailable (unit tests / CLI bootstrap), the state is
 * held in a process-local fallback so callers can still exercise round-trips deterministically.
 */
final class OnboardingState implements OnboardingStateInterface
{
    /** Values this MVP will persist; guards against writing an arbitrary string. */
    private const KNOWN_STATES = [self::PENDING, self::COMPLETE];

    /** Process-local fallback when WP option functions are absent (tests / CLI). */
    private string $fallback = self::PENDING;

    public function current(): string
    {
        if (function_exists('get_option')) {
            $value = get_option(self::OPTION_NAME, self::PENDING);
            $value = is_string($value) && $value !== '' ? $value : self::PENDING;

            return in_array($value, self::KNOWN_STATES, true) ? $value : self::PENDING;
        }

        return $this->fallback;
    }

    public function isComplete(): bool
    {
        return $this->current() === self::COMPLETE;
    }

    public function set(string $state): void
    {
        if (! in_array($state, self::KNOWN_STATES, true)) {
            throw new \InvalidArgumentException(
                "Unknown onboarding state '{$state}'. Expected one of: "
                . implode(', ', self::KNOWN_STATES) . '.'
            );
        }

        if (function_exists('update_option')) {
            update_option(self::OPTION_NAME, $state);

            return;
        }

        $this->fallback = $state;
    }

    public function markComplete(): void
    {
        $this->set(self::COMPLETE);
    }
}
