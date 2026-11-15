<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\AbstractManagerRegistry;
use Doctrine\Persistence\Proxy;

/**
 * Minimal ManagerRegistry over one connection and one entity manager; resetManager() builds a new manager on the
 * same connection, as DoctrineBundle does in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class TestManagerRegistry extends AbstractManagerRegistry
{
    private EntityManagerInterface $manager;

    public int $resets = 0;

    /**
     * @param Closure(Connection): EntityManagerInterface $managerFactory
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly Closure $managerFactory,
    ) {
        parent::__construct('test', ['default' => 'connection'], ['default' => 'manager'], 'default', 'default', Proxy::class);
        $this->manager = ($this->managerFactory)($this->connection);
    }

    protected function getService(string $name): object
    {
        return $name === 'connection' ? $this->connection : $this->manager;
    }

    protected function resetService(string $name): void
    {
        if ($name === 'manager') {
            ++$this->resets;
            $this->manager = ($this->managerFactory)($this->connection);
        }
    }
}
