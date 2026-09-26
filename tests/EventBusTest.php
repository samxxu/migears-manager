<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests;

use LogicException;
use PHPUnit\Framework\TestCase;
use MiGears\Manager\EventBus;
use MiGears\Manager\Tests\Fixtures\ListenerSpy;
use MiGears\Manager\Tests\Fixtures\OrderDomain;

final class EventBusTest extends TestCase
{
    private EventBus $bus;

    protected function setUp(): void
    {
        $this->bus = new EventBus();
    }

    protected function tearDown(): void
    {
        EventBus::reset();
    }

    public function testEmitWithoutListenersIsANoop(): void
    {
        $this->expectNotToPerformAssertions();

        $this->bus->emit('order.placed', 42);
    }

    public function testListenerReceivesSinglePayloadValue(): void
    {
        $received = [];

        $this->bus->on('order.placed', function (int $id) use (&$received): void {
            $received[] = $id;
        });

        $this->bus->emit('order.placed', 42);

        $this->assertSame([42], $received);
    }

    public function testListenerReceivesMultiplePayloadValuesPositionally(): void
    {
        $received = [];

        $this->bus->on('payment.captured', function (int $paymentId, int $actorId, string $reference) use (&$received): void {
            $received[] = [$paymentId, $actorId, $reference];
        });

        $this->bus->emit('payment.captured', 7, 3, 'PSP-0001');

        $this->assertSame([[7, 3, 'PSP-0001']], $received);
    }

    public function testListenerReceivesObjectPayload(): void
    {
        $order = new OrderDomain(1, 9, 'First order', 'PLACED');
        $received = null;

        $this->bus->on('order.placed', function (OrderDomain $payload) use (&$received): void {
            $received = $payload;
        });

        $this->bus->emit('order.placed', $order);

        $this->assertSame($order, $received);
    }

    public function testListenersRunInRegistrationOrder(): void
    {
        $calls = [];

        $this->bus->on('e', function () use (&$calls): void {
            $calls[] = 'first';
        });
        $this->bus->on('e', function () use (&$calls): void {
            $calls[] = 'second';
        });
        $this->bus->on('e', function () use (&$calls): void {
            $calls[] = 'third';
        });

        $this->bus->emit('e');

        $this->assertSame(['first', 'second', 'third'], $calls);
    }

    public function testListenersAreScopedToTheirEventName(): void
    {
        $calls = [];

        $this->bus->on('order.placed', function () use (&$calls): void {
            $calls[] = 'placed';
        });
        $this->bus->on('order.cancelled', function () use (&$calls): void {
            $calls[] = 'cancelled';
        });

        $this->bus->emit('order.placed');

        $this->assertSame(['placed'], $calls);
    }

    public function testSameListenerRegisteredTwiceRunsTwice(): void
    {
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };

        $this->bus->on('e', $listener);
        $this->bus->on('e', $listener);

        $this->bus->emit('e');

        $this->assertSame(2, $count);
    }

    public function testOffRemovesTheListener(): void
    {
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };

        $this->bus->on('e', $listener);
        $this->bus->off('e', $listener);

        $this->bus->emit('e');

        $this->assertSame(0, $count);
    }

    public function testOffRemovesOnlyTheFirstMatchingRegistration(): void
    {
        $count = 0;
        $listener = function () use (&$count): void {
            $count++;
        };

        $this->bus->on('e', $listener);
        $this->bus->on('e', $listener);
        $this->bus->off('e', $listener);

        $this->bus->emit('e');

        $this->assertSame(1, $count);
    }

    public function testOffKeepsTheOrderOfRemainingListeners(): void
    {
        $calls = [];
        $removed = function () use (&$calls): void {
            $calls[] = 'removed';
        };
        $first = function () use (&$calls): void {
            $calls[] = 'first';
        };
        $last = function () use (&$calls): void {
            $calls[] = 'last';
        };

        $this->bus->on('e', $first);
        $this->bus->on('e', $removed);
        $this->bus->on('e', $last);
        $this->bus->off('e', $removed);

        $this->bus->emit('e');

        $this->assertSame(['first', 'last'], $calls);
    }

    public function testOffForAnUnknownEventIsANoop(): void
    {
        $this->expectNotToPerformAssertions();

        $this->bus->off('never.registered', static function (): void {
        });
    }

    public function testOffWithAnEquivalentButDistinctClosureKeepsTheListener(): void
    {
        $count = 0;

        $this->bus->on('e', function () use (&$count): void {
            $count++;
        });
        $this->bus->off('e', function () use (&$count): void {
            $count++;
        });

        $this->bus->emit('e');

        $this->assertSame(1, $count);
    }

    public function testListenerMayBeAnArrayCallable(): void
    {
        $spy = new ListenerSpy();

        $this->bus->on('e', [$spy, 'handle']);

        $this->bus->emit('e', 5);

        $this->assertSame([5], $spy->seen);
    }

    public function testListenerMayBeAStaticStringCallable(): void
    {
        ListenerSpy::reset();

        $this->bus->on('e', ListenerSpy::class . '::handleStatic');

        $this->bus->emit('e', 9);

        $this->assertSame([9], ListenerSpy::$staticSeen);
    }

    public function testThrowingListenerPropagatesAndStopsLaterListeners(): void
    {
        $calls = [];

        $this->bus->on('e', function () use (&$calls): void {
            $calls[] = 'first';
            throw new LogicException('listener failed');
        });
        $this->bus->on('e', function () use (&$calls): void {
            $calls[] = 'second';
        });

        try {
            $this->bus->emit('e');
            $this->fail('A listener exception must propagate to the emitter.');
        } catch (LogicException $e) {
            $this->assertSame('listener failed', $e->getMessage());
        }

        $this->assertSame(['first'], $calls);
    }

    public function testListenerRegisteredDuringDispatchRunsOnlyFromTheNextEmit(): void
    {
        $calls = [];
        $bus = $this->bus;

        $bus->on('e', function () use (&$calls, $bus): void {
            $calls[] = 'first';
            $bus->on('e', function () use (&$calls): void {
                $calls[] = 'added-during-dispatch';
            });
        });

        $bus->emit('e');
        $this->assertSame(['first'], $calls);

        $bus->emit('e');
        $this->assertSame(['first', 'first', 'added-during-dispatch'], $calls);
    }

    public function testListenerRemovedDuringDispatchStillRunsInThatDispatch(): void
    {
        $calls = [];
        $bus = $this->bus;
        $second = function () use (&$calls): void {
            $calls[] = 'second';
        };

        $bus->on('e', function () use (&$calls, $bus, $second): void {
            $calls[] = 'first';
            $bus->off('e', $second);
        });
        $bus->on('e', $second);

        $bus->emit('e');
        $this->assertSame(['first', 'second'], $calls);

        $bus->emit('e');
        $this->assertSame(['first', 'second', 'first'], $calls);
    }

    public function testEmittingFromAListenerIsAllowed(): void
    {
        $calls = [];
        $bus = $this->bus;

        $bus->on('outer', function () use (&$calls, $bus): void {
            $calls[] = 'outer';
            $bus->emit('inner', 1);
        });
        $bus->on('inner', function (int $id) use (&$calls): void {
            $calls[] = 'inner:' . $id;
        });

        $bus->emit('outer');

        $this->assertSame(['outer', 'inner:1'], $calls);
    }

    public function testTheSharedInstanceIsStableUntilReset(): void
    {
        EventBus::reset();

        $first = EventBus::instance();
        $this->assertSame($first, EventBus::instance());

        EventBus::reset();
        $this->assertNotSame($first, EventBus::instance());
    }

    public function testResetDropsTheListenersToo(): void
    {
        EventBus::reset();

        $called = 0;
        EventBus::instance()->on('e', function () use (&$called): void {
            $called++;
        });

        EventBus::reset();
        EventBus::instance()->emit('e');

        $this->assertSame(0, $called);
    }
}
