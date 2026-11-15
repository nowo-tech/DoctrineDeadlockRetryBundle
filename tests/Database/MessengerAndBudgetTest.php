<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Database;

use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\MessengerWorkerGuardSubscriber;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Database\Support\DatabaseTestCase;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Throwable;

/**
 * Delta QA-1 regressions (APPENDIX-QA, Delta QA-1), ported from its proofs of concept.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class MessengerAndBudgetTest extends DatabaseTestCase
{
    /**
     * QA-DDRB-D-L1: a failed handler left a transaction open. The guard repairs before Messenger's
     * SendFailedMessageForRetryListener (priority 100) writes the retry envelope on the same connection, so the
     * envelope is committed instead of being rolled back with the forgotten transaction.
     */
    #[DataProvider('mysqlFamily')]
    public function testFailedMessageIsRepairedBeforeTheRetryEnvelopeIsWritten(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement('DROP TABLE IF EXISTS qa_messenger');
        $this->connection->executeStatement('CREATE TABLE qa_messenger (id INT AUTO_INCREMENT PRIMARY KEY, body VARCHAR(20) NOT NULL)');

        $this->connection->beginTransaction();
        $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 5 WHERE id = 1');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(WorkerMessageFailedEvent::class, function (): void {
            $this->connection->insert('qa_messenger', ['body' => 'retry-envelope']);   // Doctrine transport send()
        }, 100);
        $dispatcher->addSubscriber(new MessengerWorkerGuardSubscriber(new ConnectionGuard($this->registry, null, [], true, null, true)));
        $dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('handler failed')));

        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(1, (int) $this->freshValue('SELECT COUNT(*) FROM qa_messenger'), 'The retry envelope is committed.');
        self::assertSame(0, $this->committedValue(1), 'The failed handler\'s write is rolled back.');
    }

    /**
     * QA-DDRB-D-L2: max_total_ms gates the start of each attempt. The call stops retrying once the budget is used, but
     * can overrun it by one attempt (here a 1 s lock wait).
     */
    #[DataProvider('mysqlFamily')]
    public function testMaxTotalMsIsTheBudgetPlusAtMostOneAttempt(string $server): void
    {
        $this->boot($server);
        $this->connection->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame('OK', $this->otherSession()->execute('BEGIN'));
        self::assertSame('OK', $this->otherSession()->execute('UPDATE ddrb_counter SET counter_value = 99 WHERE id = 1'));
        $service = new DeadlockRetryService(
            $this->manager(),
            ['default' => new RetryProfile('default', 10, 0, retryLockWaitTimeout: true, maxTotalMs: 1500)],
            'default',
            $this->registry,
        );
        $attempts = 0;
        $start    = hrtime(true);

        try {
            $service->retry(function () use (&$attempts): void {
                ++$attempts;
                $this->connection->executeStatement('UPDATE ddrb_counter SET counter_value = 1 WHERE id = 1');
            });
        } catch (Throwable) {
        }

        $elapsedMs = (hrtime(true) - $start) / 1e6;
        self::assertSame('OK', $this->otherSession()->execute('ROLLBACK'));
        self::assertLessThan(10, $attempts, 'The budget stops the retries.');
        self::assertGreaterThanOrEqual(2, $attempts);
        self::assertLessThan(1500 + 1000 + 1000, $elapsedMs, 'At most the budget plus one attempt (1 s lock wait), with slack.');
    }
}
