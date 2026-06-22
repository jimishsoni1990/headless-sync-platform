<?php

declare(strict_types=1);

/**
 * Security configuration skeleton.
 * No secrets here — secrets are env-only (Doc 10 §9).
 * No business logic permitted (Doc 2 §5).
 */
return [
    'api_key_header'    => 'X-HSP-API-Key',
    'signing_algorithm' => 'sha256',
    'audit_enabled'     => true,
];
