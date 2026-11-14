<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Infrastructure;

use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Application\OrderRepository;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Domain\Order;

/**
 * Rehydrates the order from committed state on every load, so nothing of a rolled-back attempt survives.
 */
final class InMemoryOrderRepository implements OrderRepository
{
    public int $loads = 0;

    public ?Order $lastSaved = null;

    public function get(string $id): Order
    {
        ++$this->loads;

        return new Order($id);
    }

    public function save(Order $order): void
    {
        $this->lastSaved = $order;
    }
}
