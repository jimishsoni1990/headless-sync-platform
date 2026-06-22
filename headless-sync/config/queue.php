<?php

declare(strict_types=1);

/**
 * Queue system configuration skeleton.
 * No business logic permitted (Doc 2 §5).
 */
return [
    'default_provider'  => 'database',
    'visibility_timeout' => 300,
    'retry_limit'        => 10,
    'partitions'         => ['content', 'commerce', 'system'],
    'batch_size'         => 10,
];
