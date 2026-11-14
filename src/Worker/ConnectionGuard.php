<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Worker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\Connection\ConnectionRepairer;
use Psr\Log\LoggerInterface;

use function in_array;

/**
 * Repairs Doctrine state a request left behind, for long-running workers (FrankenPHP worker mode with kernel reset
 * false, RoadRunner, Messenger consumers): a connection still in a transaction, or with a nesting level above 0, is
 * rolled back when a single level is open, and closed otherwise; closed entity managers are reset.
 *
 * Every repair logs one warning with the connection name, the nesting level and the action, never SQL or parameters.
 * The guard holds no state between calls.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConnectionGuard
{
    private readonly ConnectionRepairer $repairer;

    /**
     * @param list<string> $connections Connection names to guard (empty = every connection of the registry)
     * @param bool $resetClosedManagers Whether closed entity managers are reset too
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $connections = [],
        private readonly bool $resetClosedManagers = true,
        ?ConnectionRepairer $repairer = null,
    ) {
        $this->repairer = $repairer ?? new ConnectionRepairer();
    }

    /**
     * @param string $phase Where the guard runs (kernel.request, kernel.terminate, …), for the log
     *
     * @return int Number of connections and managers repaired
     */
    public function repair(string $phase): int
    {
        $repaired = 0;

        foreach ($this->registry->getConnections() as $name => $connection) {
            if (!$connection instanceof Connection || ($this->connections !== [] && !in_array($name, $this->connections, true))) {
                continue;
            }

            $level    = $connection->getTransactionNestingLevel();
            $baseline = $connection->isAutoCommit() ? 0 : 1;
            $action   = $this->repairer->betweenRequests($connection, $baseline);

            if ($action === ConnectionRepairer::ACTION_NONE) {
                continue;
            }

            ++$repaired;
            $this->logger?->warning('Doctrine connection "{connection}" was left with an open transaction (nesting level {nesting_level}); {action}.', [
                'connection'    => $name,
                'nesting_level' => $level,
                'action'        => $action,
                'phase'         => $phase,
            ]);
        }

        if ($this->resetClosedManagers) {
            $repaired += $this->resetClosedManagers($phase);
        }

        return $repaired;
    }

    private function resetClosedManagers(string $phase): int
    {
        $reset = 0;

        foreach ($this->registry->getManagerNames() as $name => $serviceId) {
            $manager = $this->registry->getManager($name);

            if (!$manager instanceof EntityManagerInterface || $manager->isOpen()) {
                continue;
            }

            $this->registry->resetManager($name);
            ++$reset;
            $this->logger?->warning('Doctrine entity manager "{manager}" was left closed; it has been reset.', [
                'manager' => $name,
                'phase'   => $phase,
            ]);
        }

        return $reset;
    }
}
