<?php

declare(strict_types=1);

namespace HSP\Modules\Commerce\Validation;

/**
 * Raised when an extracted Commerce source entity is structurally unusable.
 *
 * Distinct from an unsupported product type, which is normal out-of-scope source under
 * AG-13 and is filtered at capture rather than raised here.
 */
final class ValidationException extends \RuntimeException
{
}
