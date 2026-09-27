<?php

declare(strict_types=1);

namespace MiGears\Manager;

/**
 * Minimalist in-process event bus — three operations, no magic.
 *
 *   $bus->on('order.placed', fn (OrderDomain $order) => $this->mailer->send($order));
 *   $bus->emit('order.placed', $order);
 *   $bus->off('order.placed', $listener);
 *
 * Listeners run synchronously, in registration order, inside the emitter's own
 * stack frame. Exceptions propagate: an event bus that swallows failures turns
 * a broken side effect into an invisible one.
 *
 * The payload is whatever the emitter passes — Domain objects, scalars, arrays.
 * There is no event base class, no payload interface and no envelope; the
 * listener signature is the contract.
 *
 * Deliberately absent: wildcards, priorities, queued delivery, retries,
 * persistence, delivery guarantees, cross-process transport. Emitting with no
 * listener registered is a no-op, so emitting is always safe.
 *
 * Internal to this package: never type-hint it, never register it, never pass it.
 * BaseManager owns the single instance — one per process — and is the only way
 * in: emit() for Managers, listen() for the wiring. The class is final: there is
 * nothing to extend and nothing to swap.
 *
 * @internal
 */
final class EventBus
{
    private static ?self $instance = null;

    /**
     * The single bus of this package.
     *
     * @internal used by BaseManager; business code never sees this class
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Drops the shared instance and every listener on it.
     *
     * @internal for tests and for processes that rebuild their wiring
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /** @var array<string, list<callable>> */
    private array $listeners = [];

    /**
     * Registers a listener for an event.
     *
     * An event may have any number of listeners; they are called in the order
     * they were registered. Registering the same callable twice makes it run
     * twice.
     *
     * @param string   $event    Event name, `module.action` (e.g. 'order.placed')
     * @param callable $listener Called with the payload passed to emit()
     */
    public function on(string $event, callable $listener): void
    {
        $this->listeners[$event][] = $listener;
    }

    /**
     * Removes a previously registered listener.
     *
     * Matching is by identity — the callable must be the same instance that was
     * given to on(). Closures are objects, so a closure held in a property (or
     * returned from a factory) removes cleanly; a closure written out twice
     * inline cannot be matched. Only the first matching registration is
     * removed, and the order of the listeners that stay is preserved.
     *
     * Removing something that was never registered is a no-op.
     */
    public function off(string $event, callable $listener): void
    {
        if (!isset($this->listeners[$event])) {
            return;
        }

        $kept = [];
        $removed = false;

        foreach ($this->listeners[$event] as $registered) {
            if (!$removed && $registered === $listener) {
                $removed = true;
                continue;
            }
            $kept[] = $registered;
        }

        if ($kept === []) {
            unset($this->listeners[$event]);
            return;
        }

        $this->listeners[$event] = $kept;
    }

    /**
     * Emits an event to every listener registered for that name.
     *
     * Listeners are called synchronously, in registration order, with the
     * payload positionally. A throwing listener propagates immediately and
     * later listeners are skipped.
     *
     * The listener list is snapshotted when the call starts: listeners added or
     * removed by a listener take effect from the next emit() onwards, never
     * during the dispatch in progress.
     *
     * There is no re-entrancy protection. Emitting the same event from one of
     * its own listeners recurses until the stack runs out — don't.
     *
     * @param mixed ...$payload Values handed to each listener, positionally
     */
    public function emit(string $event, mixed ...$payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener(...$payload);
        }
    }
}
