<?php

declare(strict_types=1);

namespace HSP\Core\Database\Exception;

/**
 * Infrastructure exception for the shared runtime PostgreSQL connection layer.
 *
 * Subsystems translate this to their own boundary exception:
 *   QueueException / OutboxWriteException / WorkerException.
 *
 * Authority: DECISION E (v1.5).
 */
class DatabaseException extends \RuntimeException {}
