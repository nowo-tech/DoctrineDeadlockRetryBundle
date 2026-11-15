<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Worker;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\DoctrineDeadlockRetryBundle\Connection\ConnectionRepairer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\VarExporter\LazyObjectInterface;

use function in_array;

/**
 * Repairs Doctrine state a request or a message left behind, for long-running workers (FrankenPHP worker mode with
 * kernel reset false, RoadRunner, Messenger consumers):
 *
 * - a connection still in a transaction is rolled back when a single level is open, and closed otherwise (or when
 *   the rollback fails);
 * - a closed entity manager is reset;
 * - with $clearManagers, an open entity manager is cleared, so changes a failed unit of work left pending (and the
 *   identity map) do not reach the next request or message.
 *
 * Only connections and managers the container already initialised are touched (when the container is given), so the
 * guard never opens a connection or initialises a lazy manager a request did not use.
 *
 * Every repair logs one warning with the connection or manager name, the nesting level and the action, never SQL
 * or parameters. The guard holds no state between calls.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConnectionGuard
{
    private readonly ConnectionRepairer $repairer;

    /**
     * @param list<string> $connections Connection names to guard (empty = every connection of the registry)
     * @param bool $resetClosedManagers Whether closed entity managers are reset
     * @param bool $clearManagers Whether open entity managers are cleared when repair() is asked to clear
     * @param ContainerInterface|null $container Used to skip connections and managers not initialised yet
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $connections = [],
        private readonly bool $resetClosedManagers = true,
        ?ConnectionRepairer $repairer = null,
        private readonly bool $clearManagers = false,
        private readonly ?ContainerInterface $container = null,
    ) {
        $this->repairer = $repairer ?? new ConnectionRepairer();
    }

    /**
     * @param string $phase Where the guard runs (kernel.request, kernel.terminate, messenger, …), for the log
     * @param bool $clear Whether open entity managers are cleared too (only when the guard is configured to)
     *
     * @return int Number of connections and managers repaired (clearing a clean manager does not count)
     */
    public function repair(string $phase, bool $clear = false): int
    {
        $repaired = 0;

        foreach ($this->registry->getConnectionNames() as $name => $serviceId) {
            if (($this->connections !== [] && !in_array($name, $this->connections, true)) || !$this->initialized($serviceId)) {
                continue;
            }

            $connection = $this->registry->getConnection($name);

            if (!$connection instanceof Connection) {
                continue;
            }

            $level  = $connection->getTransactionNestingLevel();
            $action = $this->repairer->betweenRequests($connection, $connection->isAutoCommit() ? 0 : 1);

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

        return $repaired + $this->repairManagers($phase, $clear && $this->clearManagers);
    }

    private function repairManagers(string $phase, bool $clear): int
    {
        if (!$this->resetClosedManagers && !$clear) {
            return 0;
        }

        $repaired = 0;

        foreach ($this->registry->getManagerNames() as $name => $serviceId) {
            if (!$this->initialized($serviceId)) {
                continue;
            }

            $manager = $this->registry->getManager($name);

            if (!$manager instanceof EntityManagerInterface || $this->isUninitializedLazyObject($manager)) {
                continue;
            }

            if (!$manager->isOpen()) {
                if ($this->resetClosedManagers) {
                    $this->registry->resetManager($name);
                    ++$repaired;
                    $this->logger?->warning('Doctrine entity manager "{manager}" was left closed; it has been reset.', [
                        'manager' => $name,
                        'phase'   => $phase,
                    ]);
                }

                continue;
            }

            if ($clear) {
                $repaired += $this->clear($manager, $name, $phase);
            }
        }

        return $repaired;
    }

    private function clear(EntityManagerInterface $manager, string $name, string $phase): int
    {
        $unitOfWork = $manager->getUnitOfWork();
        $pending    = $unitOfWork->getScheduledEntityInsertions() !== []
            || $unitOfWork->getScheduledEntityUpdates() !== []
            || $unitOfWork->getScheduledEntityDeletions() !== [];

        $manager->clear();

        if (!$pending) {
            return 0;
        }

        $this->logger?->warning('Doctrine entity manager "{manager}" was left with pending changes; it has been cleared.', [
            'manager' => $name,
            'phase'   => $phase,
        ]);

        return 1;
    }

    private function initialized(string $serviceId): bool
    {
        return !$this->container instanceof ContainerInterface || $this->container->initialized($serviceId);
    }

    private function isUninitializedLazyObject(object $object): bool
    {
        return $object instanceof LazyObjectInterface && !$object->isLazyObjectInitialized();
    }
}
