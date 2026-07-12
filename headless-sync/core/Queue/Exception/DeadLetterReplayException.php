<?php

declare(strict_types=1);

namespace HSP\Core\Queue\Exception;

/**
 * Raised when a DLQ replay cannot proceed (row missing, already replayed) or the
 * single-transaction replay lifecycle fails. Distinct from QueueException so callers
 * (WP-CLI) can surface replay-specific diagnostics.
 *
 * Authority: DECISION S (v1.16).
 */
final class DeadLetterReplayException extends \RuntimeException
{
}
