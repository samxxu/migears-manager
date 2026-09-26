<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests\Fixtures;

/**
 * Test double for a Domain object — the same shape a real one has:
 * public readonly properties matching database columns, plus fromArray().
 */
final class OrderDomain
{
    public function __construct(
        public readonly int $id,
        public readonly int $user_id,
        public readonly string $title,
        public readonly string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(...$row);
    }
}
