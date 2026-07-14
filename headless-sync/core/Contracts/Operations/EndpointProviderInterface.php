<?php

declare(strict_types=1);

namespace HSP\Core\Contracts\Operations;

/**
 * Supplies delivery API endpoint metadata for the API Playground (ADR-050 / Doc 12 §15).
 *
 * Returns read-only EndpointDescriptor metadata for the published hsp/v1 endpoints
 * (DECISION N/F) so the Playground can list and describe them without hardcoding
 * (ADR-050/ADR-052). The Playground that executes live GETs is OPSC-S3; OPSC-S1 is the
 * contract only. ADR-038: no HTTP/framework types cross this contract.
 */
interface EndpointProviderInterface extends OperationsProviderInterface
{
    /**
     * Metadata for every endpoint this provider describes.
     *
     * @return EndpointDescriptor[]
     */
    public function endpoints(): array;
}
