<?php

declare(strict_types=1);

namespace HSP\Modules\Content\Validation;

/**
 * Thrown by Content validators and extractors when a source entity fails
 * required-field or structural validation (fail-fast strategy, Doc 6 §22).
 *
 * Catching this exception at the handler level routes the job to the retry
 * workflow without silent coercion.
 */
final class ValidationException extends \RuntimeException
{
    /** @param list<string> $violations Human-readable violation messages. */
    public function __construct(
        string $message,
        private readonly array $violations = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return list<string> */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
