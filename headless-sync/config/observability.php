<?php

declare(strict_types=1);

/**
 * Observability configuration skeleton.
 * No business logic permitted (Doc 2 §5).
 */
return [
    'metrics' => [
        'enabled'  => false,
        'interval' => 60,
    ],
    'tracing' => [
        'enabled' => false,
    ],
    'health_checks' => [
        'enabled' => true,
    ],
];
