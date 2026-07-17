<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Onboarding;

/**
 * The durable onboarding completion signal (ONB-S1b; DECISION W (d)).
 *
 * Completion state is a SINGLE WordPress option `hsp_onboarding_state` stored in MySQL (the WP
 * options table) — it is NOT a schema migration and adds no table/column. Values track the
 * onboarding lifecycle; the terminal value is COMPLETE. This is the only piece of onboarding
 * state that is persisted (progress is derived on demand — DECISION Q, ONB-S2).
 *
 * Nav gating (DECISION W (f)) reads {@see isComplete()}: until it returns true the Operations +
 * API Playground admin pages are not registered, and onboarding is the only HSP admin surface.
 */
interface OnboardingStateInterface
{
    /** The WordPress option name (MySQL, WP options table). No schema change. */
    public const OPTION_NAME = 'hsp_onboarding_state';

    /** Fresh install / not yet begun — the default when the option is absent. */
    public const PENDING = 'pending';

    /** Terminal state — onboarding finished; the console un-gates. Set in ONB-S2 on backfill convergence. */
    public const COMPLETE = 'complete';

    /** Current lifecycle value (defaults to PENDING when the option has never been written). */
    public function current(): string;

    /** True only when the current value is COMPLETE — the nav-gating signal (DECISION W (f)). */
    public function isComplete(): bool;

    /** Persist a new lifecycle value to the WP option. */
    public function set(string $state): void;

    /** Convenience: mark onboarding complete (sets the option to COMPLETE). */
    public function markComplete(): void;
}
