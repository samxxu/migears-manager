<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests\Fixtures;

use RuntimeException;

/**
 * Test double for a DAO: same method names and return shapes as
 * SingleTableDao, backed by an array instead of a PDO connection.
 */
final class InMemoryOrderDao
{
    /** @var array<int, array<string, mixed>> */
    private array $rows = [];

    private int $nextId = 1;

    /** @param array<string, mixed> $row */
    public function insert(array $row): int
    {
        $id = isset($row['id']) ? (int) $row['id'] : $this->nextId;
        $this->nextId = max($this->nextId, $id + 1);

        $row['id'] = $id;
        $this->rows[$id] = $row;

        return $id;
    }

    /** @return array<string, mixed> */
    public function getByIdOrFail(int $id): array
    {
        if (!isset($this->rows[$id])) {
            throw new RuntimeException("order {$id} not found");
        }

        return $this->rows[$id];
    }

    /** @param array<string, mixed> $row */
    public function update(array $row): int
    {
        $id = (int) $row['id'];
        $this->rows[$id] = array_merge($this->getByIdOrFail($id), $row);

        return 1;
    }
}
