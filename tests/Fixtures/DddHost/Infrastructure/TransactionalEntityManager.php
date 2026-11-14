<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Infrastructure;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Test-only double type: doctrine/orm 2.x declares wrapInTransaction() only as a docblock @method on
 * EntityManagerInterface, so PHPUnit cannot configure it there. Same signature as ORM 3.
 */
interface TransactionalEntityManager extends EntityManagerInterface
{
    public function wrapInTransaction(callable $func): mixed;
}
