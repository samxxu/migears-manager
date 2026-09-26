<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests\Fixtures;

/**
 * Listener spy: covers both the array-callable and the static-string callable
 * forms, which the bus stores verbatim.
 */
final class ListenerSpy
{
    /** @var list<int> */
    public array $seen = [];

    /** @var list<int> */
    public static array $staticSeen = [];

    public function handle(int $value): void
    {
        $this->seen[] = $value;
    }

    public static function handleStatic(int $value): void
    {
        self::$staticSeen[] = $value;
    }

    public static function reset(): void
    {
        self::$staticSeen = [];
    }
}
