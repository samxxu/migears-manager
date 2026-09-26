<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * A logger that keeps what it was given, so a test can assert that the Manager
 * actually received the logger from the Registry.
 */
final class ArrayLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $level . ':' . (string) $message;
    }
}
