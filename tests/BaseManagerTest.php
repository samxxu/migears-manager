<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use MiGears\Manager\BaseManager;
use MiGears\Manager\EventBus;
use MiGears\Manager\SideEffectFailedException;
use MiGears\Manager\Tests\Fixtures\ArrayContainer;
use MiGears\Manager\Tests\Fixtures\ArrayLogger;
use MiGears\Manager\Tests\Fixtures\InMemoryOrderDao;
use MiGears\Manager\Tests\Fixtures\OrderDomain;
use MiGears\Manager\Tests\Fixtures\OrderManager;

final class BaseManagerTest extends TestCase
{
    private ArrayContainer $registry;
    private InMemoryOrderDao $orders;
    private OrderManager $manager;

    protected function setUp(): void
    {
        // the bus is process-wide and internal: every test starts with an empty one
        EventBus::reset();

        $this->orders = new InMemoryOrderDao();
        $this->registry = new ArrayContainer([
            InMemoryOrderDao::class => $this->orders,
            LoggerInterface::class => new ArrayLogger(),
        ]);
        $this->manager = new OrderManager($this->registry);
    }

    protected function tearDown(): void
    {
        EventBus::reset();
    }

    public function testManagerWorksWithoutAnyListener(): void
    {
        $order = $this->manager->place('First order');

        $this->assertSame(1, $order->id);
        $this->assertSame('PLACED', $order->status);
        $this->assertSame('PLACED', $this->orders->getByIdOrFail(1)['status']);
    }

    public function testListenerSeesStateThatIsAlreadyPersisted(): void
    {
        $statusWhenNotified = null;

        BaseManager::listen(OrderManager::PLACED, function (OrderDomain $order) use (&$statusWhenNotified): void {
            $statusWhenNotified = $this->orders->getByIdOrFail($order->id)['status'];
        });

        $order = $this->manager->place('First order');

        $this->assertSame('PLACED', $statusWhenNotified);
        $this->assertSame(1, $order->id);
    }

    public function testListenerRegisteredBeforeTheManagerExistsStillReceivesEvents(): void
    {
        // the wiring runs before anything is served: managers may be built after
        // their listeners are subscribed
        $seen = [];
        BaseManager::listen(OrderManager::PLACED, function (OrderDomain $order) use (&$seen): void {
            $seen[] = $order->id;
        });

        (new OrderManager($this->registry))->place('Placed after wiring');

        $this->assertSame([1], $seen);
    }

    public function testEmitForwardsEveryPayloadValueInOrder(): void
    {
        $this->manager->place('First order');

        $received = [];
        BaseManager::listen(
            OrderManager::CANCELLED,
            function (OrderDomain $order, int $actorId) use (&$received): void {
                $received[] = [$order->status, $actorId];
            },
        );

        $this->manager->cancel(1, 42);

        $this->assertSame([['CANCELLED', 42]], $received);
    }

    public function testEventNamesAreScopedToOneEvent(): void
    {
        $calls = [];
        BaseManager::listen(OrderManager::CANCELLED, function () use (&$calls): void {
            $calls[] = 'cancelled';
        });

        $this->manager->place('First order');

        $this->assertSame([], $calls);
    }

    public function testTheBusIsSharedEvenWhenManagersComeFromDifferentRegistries(): void
    {
        $otherOrders = new InMemoryOrderDao();
        $otherManager = new OrderManager(new ArrayContainer([
            InMemoryOrderDao::class => $otherOrders,
            LoggerInterface::class => new ArrayLogger(),
        ]));

        $seen = [];
        BaseManager::listen(OrderManager::PLACED, function (OrderDomain $order) use (&$seen): void {
            $seen[] = $order->id;
        });

        $this->manager->place('From the first manager');
        $otherManager->place('From the second manager');

        $this->assertSame([1, 1], $seen);
        $this->assertSame('From the second manager', $otherOrders->getByIdOrFail(1)['title']);
    }

    public function testTheLoggerArrivesThroughTheRegistry(): void
    {
        $logger = new ArrayLogger();
        $manager = new OrderManager(new ArrayContainer([
            InMemoryOrderDao::class => new InMemoryOrderDao(),
            LoggerInterface::class => $logger,
        ]));

        $manager->logSomething('order placed');

        $this->assertSame(['info:order placed'], $logger->messages);
    }

    public function testAMissingLoggerFailsAtConstruction(): void
    {
        $registry = new ArrayContainer([InMemoryOrderDao::class => new InMemoryOrderDao()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nothing is registered under');

        new OrderManager($registry);
    }

    public function testAMissingDaoFailsAtConstruction(): void
    {
        $registry = new ArrayContainer([LoggerInterface::class => new ArrayLogger()]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(InMemoryOrderDao::class);

        new OrderManager($registry);
    }

    // --- The documented "write, then emit" consequence ---

    public function testAThrowingListenerLeavesTheWriteCommittedAndARetryDuplicatesIt(): void
    {
        // README (Event delivery, point 5): a throwing listener leaves the use
        // case through SideEffectFailedException although the row is already
        // persisted, and the retry an entry layer would naturally make writes it
        // a second time. The answers are idempotency and absorbing what a
        // listener can recover from — not a rollback this package cannot provide.
        $statusWhenNotified = null;
        BaseManager::listen(OrderManager::PLACED, function (OrderDomain $order) use (&$statusWhenNotified): void {
            $statusWhenNotified = $this->orders->getByIdOrFail($order->id)['status'];
            throw new RuntimeException('listener boom');
        });

        try {
            $this->manager->place('First order');
            self::fail('A throwing listener must leave the use case.');
        } catch (SideEffectFailedException $e) {
            self::assertSame(OrderManager::PLACED, $e->event);
            self::assertSame('listener boom', $e->getPrevious()?->getMessage());
            self::assertStringContainsString('a retry may duplicate it', $e->getMessage());
        }

        self::assertSame('PLACED', $statusWhenNotified);
        self::assertSame('PLACED', $this->orders->getByIdOrFail(1)['status']);

        // the entry layer retries the failed request: the row is written again
        try {
            $this->manager->place('First order');
            self::fail('The retry fails the same way.');
        } catch (SideEffectFailedException) {
            // expected
        }

        self::assertSame('PLACED', $this->orders->getByIdOrFail(2)['status']);
    }

    public function testWiringTwiceAccumulatesListeners(): void
    {
        // Wiring must run once per process: a second pass re-subscribes, and a
        // duplicated listener fires once per registration (README: before you run it).
        $calls = 0;
        $listener = function () use (&$calls): void {
            $calls++;
        };

        BaseManager::listen(OrderManager::PLACED, $listener);
        BaseManager::listen(OrderManager::PLACED, $listener);

        $this->manager->place('First order');

        self::assertSame(2, $calls);
    }
}
