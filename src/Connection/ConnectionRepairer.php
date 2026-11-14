<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Connection;

use Doctrine\DBAL\Connection;
use Throwable;

/**
 * Brings a DBAL connection back to a known transaction nesting level after a failure.
 *
 * DBAL 4 lowers the nesting level only after a successful ROLLBACK TO SAVEPOINT. After a MySQL / MariaDB deadlock in
 * a nested transaction the server has dropped every savepoint, the rollback fails with 1305 and the level stays
 * above 0 until Connection::close(), which also makes the server discard whatever is left of the transaction.
 *
 * Stateless: safe as a shared service in a long-running worker.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConnectionRepairer
{
    public const ACTION_NONE        = 'none';
    public const ACTION_ROLLED_BACK = 'rolled_back';
    public const ACTION_CLOSED      = 'closed';

    /**
     * Repairs the connection after a failed operation that started at $entryLevel.
     *
     * At entry level 0 any transaction still active is closed, never rolled back: a rollback is exactly what fails
     * after a deadlock. Inside a caller's transaction (entry level > 0) the leftover levels are rolled back, and the
     * connection is closed when a rollback fails.
     *
     * @return string One of the ACTION_* constants
     */
    public function afterFailure(Connection $connection, int $entryLevel): string
    {
        if ($connection->getTransactionNestingLevel() <= $entryLevel) {
            return self::ACTION_NONE;
        }

        if ($entryLevel === 0) {
            $connection->close();

            return self::ACTION_CLOSED;
        }

        return $this->rollBackTo($connection, $entryLevel);
    }

    /**
     * Repairs a connection left with an open transaction between two requests: a single level is rolled back when
     * possible, anything deeper (or a failed rollback) closes the connection.
     *
     * @param int $baseline Expected level between requests (1 for a connection with auto-commit disabled)
     *
     * @return string One of the ACTION_* constants
     */
    public function betweenRequests(Connection $connection, int $baseline = 0): string
    {
        $level = $connection->getTransactionNestingLevel();

        if ($level <= $baseline) {
            return self::ACTION_NONE;
        }

        if ($level === $baseline + 1) {
            return $this->rollBackTo($connection, $baseline);
        }

        $connection->close();

        return self::ACTION_CLOSED;
    }

    private function rollBackTo(Connection $connection, int $level): string
    {
        try {
            // Bounded: with auto-commit disabled DBAL opens a new transaction after the outermost rollback.
            for ($remaining = $connection->getTransactionNestingLevel() - $level; $remaining > 0; --$remaining) {
                $connection->rollBack();
            }

            return self::ACTION_ROLLED_BACK;
        } catch (Throwable) {
            $connection->close();

            return self::ACTION_CLOSED;
        }
    }
}
