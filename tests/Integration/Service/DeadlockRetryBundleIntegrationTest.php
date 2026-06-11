<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Integration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Integration tests: bundle extension loads DeadlockRetryService with configured profiles.
 *
 * @covers \Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension
 * @covers \Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService
 */
final class DeadlockRetryBundleIntegrationTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private Connection&MockObject $connection;

    protected function setUp(): void
    {
        $this->connection    = $this->createMock(Connection::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->entityManager->method('getConnection')->willReturn($this->connection);
    }

    /**
     * @param array<int, array<string, mixed>> $config
     */
    private function buildContainer(array $config = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new NowoDoctrineDeadlockRetryExtension())->load($config, $container);

        $definition = $container->getDefinition(DeadlockRetryService::class);
        $definition->setAutowired(false);
        $definition->setArgument('$entityManager', $this->entityManager);

        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                if ($container->hasDefinition(DeadlockRetryService::class)) {
                    $container->getDefinition(DeadlockRetryService::class)->setPublic(true);
                }
            }
        });

        $container->compile();

        return $container;
    }

    public function testContainerProvidesDeadlockRetryServiceWithDefaultProfile(): void
    {
        $container = $this->buildContainer();
        $service   = $container->get(DeadlockRetryService::class);

        self::assertInstanceOf(DeadlockRetryService::class, $service);
        self::assertSame('default', $service->getDefaultProfileName());
        self::assertContains('default', $service->getProfileNames());

        $this->entityManager->expects($this->once())->method('flush');
        $service->flush();
    }

    public function testContainerWithCustomProfileRetriesFlushOnDeadlock(): void
    {
        $deadlock = $this->createDeadlockException();

        $this->entityManager
            ->expects($this->exactly(2))
            ->method('flush')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($deadlock),
                null,
            );

        $this->connection->method('isTransactionActive')->willReturn(false);

        $container = $this->buildContainer([
            [
                'profiles' => [
                    'fast' => ['max_retries' => 1, 'sleep_ms' => 0],
                ],
                'default_profile' => 'fast',
            ],
        ]);

        /** @var DeadlockRetryService $service */
        $service = $container->get(DeadlockRetryService::class);
        $service->flush();
    }

    private function createDeadlockException(): DeadlockException
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
