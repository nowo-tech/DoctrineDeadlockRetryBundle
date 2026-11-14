<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Application;

use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Domain\Order;

/**
 * Application service of the DDD host: depends only on its own ports.
 * The whole unit of work (load, apply, save) runs inside the runner, so a replayed attempt starts from scratch;
 * domain events are released only once the runner returned (after commit).
 */
final readonly class PayOrderHandler
{
    /**
     * @param callable(string): void $publish Event publisher (after commit)
     */
    public function __construct(
        private TransactionRunner $transactions,
        private OrderRepository $orders,
        private mixed $publish,
    ) {
    }

    public function __invoke(string $orderId): void
    {
        $order = $this->transactions->run(function () use ($orderId): Order {
            $order = $this->orders->get($orderId);
            $order->pay();
            $this->orders->save($order);

            return $order;
        });

        foreach ($order->releaseEvents() as $event) {
            ($this->publish)($event);
        }
    }
}
