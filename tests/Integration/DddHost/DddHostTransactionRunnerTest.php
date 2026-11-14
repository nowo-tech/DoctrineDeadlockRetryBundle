<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Integration\DddHost;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface;
use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Application\PayOrderHandler;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Infrastructure\DeadlockRetryTransactionRunner;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Infrastructure\InMemoryOrderRepository;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Fixtures\DddHost\Infrastructure\TransactionalEntityManager;
use Nowo\DoctrineDeadlockRetryBundle\Tests\Integration\Service\DeadlockRetryBundleIntegrationTest;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * REQ-ARCH-001, DDD / hexagonal host: a plain-PHP domain (no Doctrine attribute, no bundle base class) and an
 * application service that depends only on its own TransactionRunner port. The infrastructure adapter implements
 * that port with the container's DeadlockRetryInterface alias. The standard host path (concrete service, flush())
 * is covered by {@see DeadlockRetryBundleIntegrationTest}.
 *
 * @covers \Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService
 */
final class DddHostTransactionRunnerTest extends TestCase
{
    public function testDeadlockedUnitOfWorkIsReplayedOnAFreshManagerAndEventsArePublishedOnceAfterCommit(): void
    {
        // The first manager deadlocks inside its transaction and is closed (ORM behaviour); the registry hands out a
        // second, open manager once the bundle has reset the closed one.
        $state = new class {
            public bool $closed = false;

            public int $resets = 0;
        };
        $firstEm = $this->manager(function (callable $work) use ($state): mixed {
            $work();
            $state->closed = true;

            throw $this->deadlock();
        }, static fn (): bool => !$state->closed);
        $secondEm = $this->manager(static fn (callable $work): mixed => $work(), static fn (): bool => true);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturnCallback(static fn (): EntityManagerInterface => $state->resets === 0 ? $firstEm : $secondEm);
        $registry->expects($this->once())->method('resetManager')->willReturnCallback(static function () use ($state, $secondEm): EntityManagerInterface {
            ++$state->resets;

            return $secondEm;
        });

        $retry = $this->containerRetry($firstEm, $registry);

        $orders    = new InMemoryOrderRepository();
        $published = [];
        $handler   = new PayOrderHandler(
            new DeadlockRetryTransactionRunner($retry, $registry),
            $orders,
            static function (string $event) use (&$published): void {
                $published[] = $event;
            },
        );

        $handler('o-1');

        self::assertSame(2, $orders->loads, 'the whole unit of work is replayed');
        self::assertSame(['order.paid:o-1'], $published, 'events are published once, after the committed attempt');
        self::assertTrue($orders->lastSaved?->isPaid());
    }

    public function testTheHostAdapterCanBeTestedWithADoubleOfThePort(): void
    {
        $retry = $this->createMock(DeadlockRetryInterface::class);
        $retry->expects($this->once())->method('retry')->willReturnCallback(static fn (callable $operation): mixed => $operation());

        $em       = $this->manager(static fn (callable $work): mixed => $work(), static fn (): bool => true);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($em);

        $runner = new DeadlockRetryTransactionRunner($retry, $registry);

        self::assertSame('done', $runner->run(static fn (): string => 'done'));
    }

    public function testThePortCarriesNoFrameworkType(): void
    {
        $reflection = new ReflectionClass(DeadlockRetryInterface::class);
        foreach ($reflection->getMethods() as $method) {
            $types = [(string) $method->getReturnType()];
            foreach ($method->getParameters() as $parameter) {
                $types[] = (string) $parameter->getType();
            }
            foreach ($types as $type) {
                self::assertDoesNotMatchRegularExpression('/Doctrine|Symfony/', $type, $method->getName());
            }
        }
    }

    private function containerRetry(EntityManagerInterface $em, ManagerRegistry $registry): DeadlockRetryInterface
    {
        $container = new ContainerBuilder();
        (new NowoDoctrineDeadlockRetryExtension())->load([['profiles' => ['fast' => ['max_retries' => 2, 'sleep_ms' => 0]], 'default_profile' => 'fast']], $container);
        $definition = $container->getDefinition(DeadlockRetryService::class);
        $definition->setAutowired(false);
        $definition->setArgument('$entityManager', $em);
        $definition->setArgument('$managerRegistry', $registry);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getAlias(DeadlockRetryInterface::class)->setPublic(true);
            }
        });
        $container->compile(true);

        $service = $container->get(DeadlockRetryInterface::class);
        self::assertInstanceOf(DeadlockRetryInterface::class, $service);

        return $service;
    }

    /**
     * @param callable(callable): mixed $wrap
     * @param callable(): bool $isOpen
     */
    private function manager(callable $wrap, callable $isOpen): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);

        $em = $this->createStub(TransactionalEntityManager::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('isOpen')->willReturnCallback($isOpen);
        $em->method('wrapInTransaction')->willReturnCallback($wrap);

        return $em;
    }

    private function deadlock(): DeadlockException
    {
        $driverException = new class('Deadlock', 1213) extends Exception implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driverException, null);
    }
}
