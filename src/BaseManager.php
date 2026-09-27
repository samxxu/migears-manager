<?php

declare(strict_types=1);

namespace MiGears\Manager;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base class for Manager classes.
 *
 * A Manager owns one business module — its tables, the reads and writes over
 * them, and every operation that changes their state — and each of its public
 * methods is one complete use case. It receives what it needs through the
 * Registry rather than through a long parameter list, and resolves it once, in
 * the constructor:
 *
 *   final class OrderManager extends BaseManager
 *   {
 *       private readonly OrderDao $orders;
 *
 *       public function __construct(ContainerInterface $registry)
 *       {
 *           parent::__construct($registry);               // the logger comes from here
 *           $this->orders = $registry->get(OrderDao::class);
 *       }
 *
 *       public function place(array $form, int $actorId): OrderDomain
 *       {
 *           ...
 *           $this->emit(OrderEvents::PLACED, $order);
 *
 *           return $order;
 *       }
 *   }
 *
 * The registry is a PSR-11 container — `Psr\Container\ContainerInterface`. The
 * interface lives in psr/container exactly so that this package and the web
 * package can both speak it without depending on each other, and PSR-11 already
 * guarantees the behaviour wanted here: get() throws NotFoundExceptionInterface
 * for an unknown id. Two rules are ours rather than PSR-11's — only Managers
 * depend on it, and it holds configured objects only.
 *
 * What the base class gives a Manager: the logger, and emit(). Nothing else —
 * no CRUD, no query helpers, no container accessor, no magic.
 *
 * The event bus behind emit() is internal to this package: it never appears in
 * a constructor, in a registration or in business code. Listeners are
 * subscribed once, at initialization, by the wiring that builds the Managers
 * (see listen()). The documented exceptions are the test suite, which builds the
 * bus directly and resets it between cases because a process-level bus outlives
 * a test case, and a long-running host that has to rebuild its wiring (README:
 * Long-running runtimes).
 */
abstract class BaseManager
{
    public const VERSION = '2.0.0';

    private readonly LoggerInterface $logger;

    public function __construct(ContainerInterface $registry)
    {
        // resolved here, so a missing logger fails at assembly time rather than
        // at the first log call somewhere inside a request
        $this->logger = $registry->get(LoggerInterface::class);
    }

    /**
     * Subscribes a listener for an event.
     *
     * Called by the composition root — the wiring that builds the Managers,
     * once, at initialization — and by nothing else. A listener registered
     * later, or from inside a Manager, makes the system's behaviour depend on
     * which code path happened to run first.
     *
     * @param string   $event    Event name, `module.action` (e.g. 'order.placed')
     * @param callable $listener Called with the payload passed to emit()
     */
    final public static function listen(string $event, callable $listener): void
    {
        EventBus::instance()->on($event, $listener);
    }

    /**
     * Emits a domain event.
     *
     * Call it after the write succeeded, never before: an event states a fact
     * that is already stored. Hand listeners the freshly re-read state, so a
     * synchronous listener can never act on a value that was only intended.
     *
     * A listener that throws is wrapped in SideEffectFailedException, which
     * carries the event name and the original exception. By the time emit()
     * runs the write is committed, so an entry layer has to be able to tell
     * this apart from a write that never happened before it decides to retry.
     */
    final protected function emit(string $event, mixed ...$payload): void
    {
        try {
            EventBus::instance()->emit($event, ...$payload);
        } catch (Throwable $e) {
            throw new SideEffectFailedException($event, $e);
        }
    }

    /**
     * The logger every Manager is given.
     *
     * Every Manager has one, resolved from the Registry at construction; it is
     * never null and never optional.
     */
    final protected function logger(): LoggerInterface
    {
        return $this->logger;
    }
}
