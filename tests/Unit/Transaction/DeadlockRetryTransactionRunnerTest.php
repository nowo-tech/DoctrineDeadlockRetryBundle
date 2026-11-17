<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Transaction;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use LogicException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Failure\TransientFailureClassifier;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\DoctrineDeadlockRetryBundle\Transaction\DeadlockRetryTransactionRunner;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class DeadlockRetryTransactionRunnerTest extends TestCase
{
    private int $transactions = 0;

    public function testRunsTheOperationInATransactionAndReturnsItsResult(): void
    {
        $runner = $this->runner();

        self::assertSame('done', $runner->run(static fn (): string => 'done'));
        self::assertSame(1, $this->transactions);
    }

    public function testRetriesATransientFailureThenSucceeds(): void
    {
        $attempts = 0;
        $deadlock = $this->deadlock();

        $result = $this->runner()->run(static function () use (&$attempts, $deadlock): string {
            if (++$attempts === 1) {
                throw $deadlock;
            }

            return 'second';
        });

        self::assertSame('second', $result);
        self::assertSame(2, $attempts);
        self::assertSame(2, $this->transactions);
    }

    public function testExhaustedTransientFailureBecomesTransientStorageFailure(): void
    {
        $deadlock = $this->deadlock();
        $caught   = null;

        try {
            $this->runner(maxRetries: 1, retryAfterSeconds: 7)->run(static function () use ($deadlock): never {
                throw $deadlock;
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertSame(7, $caught->retryAfterSeconds());
        self::assertSame($deadlock, $caught->getPrevious());
        self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $caught->getMessage());
        self::assertSame(2, $this->transactions);
    }

    public function testAnInnerTransientStorageFailureIsRetriedThenRethrownAsIs(): void
    {
        $inner  = new TransientStorageFailure(3);
        $caught = null;

        try {
            $this->runner(maxRetries: 1)->run(static function () use ($inner): never {
                throw $inner;
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertSame($inner, $caught);
        self::assertSame(2, $this->transactions);
    }

    public function testAnInnerFailureOverALockWaitFollowsTheLockWaitSwitch(): void
    {
        $inner  = TransientStorageFailure::fromPrevious(new LockWaitTimeoutException($this->driverError(1205, 'HY000'), null), 3);
        $caught = null;

        try {
            $this->runner(maxRetries: 3)->run(static function () use ($inner): never {
                throw $inner;
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertSame($inner, $caught);
        self::assertSame(1, $this->transactions, 'retry_lock_wait_timeout is off by default: not retried.');
    }

    public function testAHandlerFailedExceptionOverADeadlockBecomesTransientStorageFailure(): void
    {
        $failed = new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug'), 'b' => $this->deadlock()]);
        $caught = null;

        try {
            $this->runner(maxRetries: 1, retryAfterSeconds: 7)->run(static function () use ($failed): never {
                throw $failed;
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertSame(7, $caught->retryAfterSeconds());
        self::assertSame($failed, $caught->getPrevious());
        self::assertSame(2, $this->transactions);
    }

    public function testATransientStorageFailureInsideAHandlerFailedExceptionKeepsItsHint(): void
    {
        $failed = new HandlerFailedException(new Envelope(new stdClass()), ['a' => new LogicException('bug'), 'b' => new TransientStorageFailure(4)]);
        $caught = null;

        try {
            $this->runner(maxRetries: 0, retryAfterSeconds: 7)->run(static function () use ($failed): never {
                throw $failed;
            });
        } catch (TransientStorageFailure $failure) {
            $caught = $failure;
        }

        self::assertSame(4, $caught->retryAfterSeconds());
        self::assertSame($failed, $caught->getPrevious());
    }

    public function testOtherFailuresAreRethrownUnchanged(): void
    {
        $bug = new LogicException('bug');

        $this->expectExceptionObject($bug);

        $this->runner()->run(static function () use ($bug): never {
            throw $bug;
        });
    }

    private function runner(int $maxRetries = 3, int $retryAfterSeconds = 1): TransactionRunnerInterface
    {
        $connection = $this->createMock(Connection::class);
        $manager    = $this->createMock(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('wrapInTransaction')->willReturnCallback(function (callable $work) use ($manager): mixed {
            ++$this->transactions;

            return $work($manager);
        });

        $service = new DeadlockRetryService($manager, ['default' => new RetryProfile('default', $maxRetries, 0)], 'default');

        return new DeadlockRetryTransactionRunner($service, new TransientFailureClassifier(), $retryAfterSeconds);
    }

    private function driverError(int $code, string $sqlState): DriverExceptionInterface
    {
        return new class('driver error', $code, $sqlState) extends Exception implements DriverExceptionInterface {
            public function __construct(string $message, int $code, private readonly string $sqlState)
            {
                parent::__construct($message, $code);
            }

            public function getSQLState(): string
            {
                return $this->sqlState;
            }
        };
    }

    private function deadlock(): DeadlockException
    {
        $driver = new class('Deadlock found', 1213) extends Exception implements DriverExceptionInterface {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driver, null);
    }
}
