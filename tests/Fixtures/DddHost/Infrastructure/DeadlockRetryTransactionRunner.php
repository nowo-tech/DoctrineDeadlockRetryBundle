<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Application\TransactionRunner;
use RuntimeException;

/**
 * Infrastructure adapter of the DDD host: implements the host's TransactionRunner port with the bundle's
 * DeadlockRetryInterface. The entity manager is taken from the registry on every attempt (the bundle resets a
 * closed manager between attempts), and the transaction is opened inside the retried callable.
 */
final readonly class DeadlockRetryTransactionRunner implements TransactionRunner
{
    public function __construct(
        private DeadlockRetryInterface $retry,
        private ManagerRegistry $registry,
        private ?string $entityManagerName = null,
    ) {
    }

    public function run(callable $work): mixed
    {
        return $this->retry->retry(function () use ($work): mixed {
            $manager = $this->registry->getManager($this->entityManagerName);
            if (!$manager instanceof EntityManagerInterface) {
                throw new RuntimeException('The configured manager is not an ORM entity manager.');
            }

            return $manager->wrapInTransaction(static fn (): mixed => $work());
        });
    }
}
