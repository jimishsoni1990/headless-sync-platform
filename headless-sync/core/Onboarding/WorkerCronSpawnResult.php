<?php

declare(strict_types=1);

namespace HSP\Core\Onboarding;

/**
 * Immutable outcome of an onboarding WP-Cron spawn attempt (ONB-S2 self-remediation;
 * DECISION W (c); DECISION X (4); ADR-054 Principle 8).
 *
 * JSON-friendly scalars only so the onboarding REST endpoint echoes it straight to the client.
 * `spawned` is true when a non-blocking WP-Cron spawn was issued (or the processing cron was
 * ensured scheduled); `disabled` is true when `DISABLE_WP_CRON` is set (WP-Cron will not fire on
 * page loads and the operator must run it out-of-band). `warning` carries the operator-facing
 * guidance for the `disabled` case (empty otherwise). No supervisor/systemd/daemon wording ever
 * appears — under ADR-054 there is no supervised process (§5).
 */
final class WorkerCronSpawnResult
{
    public function __construct(
        /** True when a non-blocking WP-Cron spawn was issued and the processing cron is scheduled. */
        public readonly bool $spawned,
        /** True when DISABLE_WP_CRON is defined truthy — WP-Cron will not self-fire. */
        public readonly bool $disabled,
        /** Operator guidance shown when WP-Cron is disabled; empty otherwise. */
        public readonly string $warning = '',
    ) {}

    /** @return array{spawned:bool,disabled:bool,warning:string} */
    public function toArray(): array
    {
        return [
            'spawned'  => $this->spawned,
            'disabled' => $this->disabled,
            'warning'  => $this->warning,
        ];
    }
}
