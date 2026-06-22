<?php

declare(strict_types=1);

namespace HSP\Core\Container;

use Psr\Container\ContainerExceptionInterface;

final class ContainerException extends \RuntimeException implements ContainerExceptionInterface {}
