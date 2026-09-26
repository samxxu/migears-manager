<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests\Fixtures;

use RuntimeException;
use Psr\Container\NotFoundExceptionInterface;

/**
 * The exception a PSR-11 container must throw for an unknown id: it has to be a
 * NotFoundExceptionInterface, and in practice it is also a RuntimeException so
 * that a caller can catch it either way.
 */
final class NotFoundException extends RuntimeException implements NotFoundExceptionInterface
{
}
