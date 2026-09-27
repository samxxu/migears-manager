<?php

declare(strict_types=1);

namespace MiGears\Manager;

use RuntimeException;
use Throwable;

/**
 * Thrown when a listener fails after the use case has already committed its write.
 *
 * emit() runs after the write, never before, so a listener that throws cannot
 * undo anything: the row is stored and the request failed anyway. Wrapping the
 * listener's own exception is what lets an entry layer tell that case from a
 * write that never happened — the row exists, so an automatic retry would write
 * it a second time. The answers stay where they were, outside this package:
 * make the use case idempotent, and let listeners absorb only the failures they
 * can genuinely recover from.
 *
 * It is a RuntimeException, so a caller that caught the listener's exception by
 * that name keeps working, and the original is available as previous(). The bus
 * itself wraps nothing: this is the Manager layer adding meaning to a failure it
 * can already see.
 */
final class SideEffectFailedException extends RuntimeException
{
    /**
     * @param string $event The event whose listener failed
     */
    public function __construct(
        public readonly string $event,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'A listener for "%s" failed after the write was committed, so a retry may duplicate it: %s',
                $event,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
