<?php

declare(strict_types=1);

namespace Preflow\Core\Container\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
}
