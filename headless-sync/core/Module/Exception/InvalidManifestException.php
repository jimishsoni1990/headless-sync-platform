<?php

declare(strict_types=1);

namespace HSP\Core\Module\Exception;

use RuntimeException;

/**
 * Thrown when a module.json manifest is malformed, missing required fields,
 * or references a class that does not implement ModuleInterface.
 */
final class InvalidManifestException extends RuntimeException {}
