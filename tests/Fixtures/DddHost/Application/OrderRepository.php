<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Application;

use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Domain\Order;

/**
 * The host's repository port, referenced by order id.
 */
interface OrderRepository
{
    public function get(string $id): Order;

    public function save(Order $order): void;
}
