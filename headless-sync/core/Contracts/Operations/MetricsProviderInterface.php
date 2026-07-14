<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Supplies derived-on-demand metric samples for the console (DECISION Q / DECISION V (c)).
 *
 * ZERO new persistence: every sample is computed at read time from existing operational
 * data. No metrics table, no rollups, no time-series store. Concrete implementations
 * (OPSC-S2) derive samples point-in-time; OPSC-S1 is the contract only.
 */
interface MetricsProviderInterface extends OperationsProviderInterface
{
    /**
     * Current derived metric samples.
     *
     * @return MetricSample[]
     */
    public function samples(): array;
}
