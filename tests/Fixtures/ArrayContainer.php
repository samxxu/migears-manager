<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests\Fixtures;

use Psr\Container\ContainerInterface;

/**
 * Test double for the application's container: an array behind PSR-11's two
 * methods. get() throws for an unknown id, as PSR-11 requires.
 */
final class ArrayContainer implements ContainerInterface
{
    /** @param array<string, mixed> $entries */
    public function __construct(private array $entries = [])
    {
    }

    public function set(string $id, mixed $value): void
    {
        $this->entries[$id] = $value;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new NotFoundException("Nothing is registered under '{$id}'");
        }

        return $this->entries[$id];
    }
}
