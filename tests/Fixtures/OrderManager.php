<?php

declare(strict_types=1);

namespace MiGears\Manager\Tests\Fixtures;

use RuntimeException;
use Psr\Container\ContainerInterface;
use MiGears\Manager\BaseManager;

/**
 * A miniature of a real Manager: the constructor takes the registry (a PSR-11
 * container) and nothing else, resolves its own DAO from it, and each method is
 * one complete use case.
 */
final class OrderManager extends BaseManager
{
    public const PLACED = 'order.placed';
    public const CANCELLED = 'order.cancelled';

    private readonly InMemoryOrderDao $orders;

    public function __construct(ContainerInterface $registry)
    {
        parent::__construct($registry);
        $this->orders = $registry->get(InMemoryOrderDao::class);
    }

    public function place(string $title, int $userId = 1): OrderDomain
    {
        if (trim($title) === '') {
            throw new RuntimeException('INVALID_ORDER');
        }

        $id = $this->orders->insert([
            'user_id' => $userId,
            'title' => trim($title),
            'status' => 'PLACED',
        ]);

        $order = OrderDomain::fromArray($this->orders->getByIdOrFail($id));

        $this->emit(self::PLACED, $order);

        return $order;
    }

    public function cancel(int $orderId, int $actorId): OrderDomain
    {
        $order = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

        if ($order->status !== 'PLACED') {
            throw new RuntimeException('ORDER_NOT_CANCELLABLE');
        }

        $this->orders->update(['id' => $orderId, 'status' => 'CANCELLED']);

        $cancelled = OrderDomain::fromArray($this->orders->getByIdOrFail($orderId));

        $this->emit(self::CANCELLED, $cancelled, $actorId);

        return $cancelled;
    }

    /** Exposed for the test that checks the logger arrives through the Registry. */
    public function logSomething(string $message): void
    {
        $this->logger()->info($message);
    }
}
