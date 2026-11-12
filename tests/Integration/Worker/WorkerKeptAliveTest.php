<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Integration\Worker;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function gc_collect_cycles;
use function memory_get_usage;

/**
 * REQ-RUNTIME-002: ONE kept-alive container, the services resetter never called. The shared
 * {@see DeadlockRetryService} serves 1,200 calls alternating two tenants; some calls deadlock once and are retried.
 * Each call returns only its own tenant's data, no attempt counter or error is carried over, memory stays flat.
 */
final class WorkerKeptAliveTest extends TestCase
{
    private const CALLS         = 1200;
    private const MEMORY_MARGIN = 4 * 1024 * 1024;

    public function testAlternatingTenantsNeverShareStateAndMemoryStaysFlat(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturn(false);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('isOpen')->willReturn(true);

        $container = new ContainerBuilder();
        (new NowoDoctrineDeadlockRetryExtension())->load([['profiles' => ['fast' => ['max_retries' => 2, 'sleep_ms' => 0]], 'default_profile' => 'fast']], $container);
        $definition = $container->getDefinition(DeadlockRetryService::class);
        $definition->setAutowired(false);
        $definition->setArgument('$entityManager', $em);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->getDefinition(DeadlockRetryService::class)->setPublic(true);
            }
        });
        $container->compile(true);
        $service = $container->get(DeadlockRetryService::class);
        self::assertInstanceOf(DeadlockRetryService::class, $service);

        $baseline = 0;
        for ($i = 0; $i < self::CALLS; ++$i) {
            $tenant          = $i % 2 === 0 ? 'alice' : 'bob';
            $state           = new stdClass();
            $state->attempts = 0;
            // Every third call deadlocks once, then succeeds on the retry.
            $deadlockOnce = $i % 3 === 0;

            $operation = function () use ($tenant, $i, $state, $deadlockOnce): array {
                ++$state->attempts;
                if ($deadlockOnce && $state->attempts === 1) {
                    throw $this->deadlock();
                }

                return ['tenant' => $tenant, 'call' => $i, 'attempts' => $state->attempts];
            };
            $result = $service->retry($operation);

            self::assertSame(['tenant' => $tenant, 'call' => $i, 'attempts' => $deadlockOnce ? 2 : 1], $result, 'call ' . $i . ' returns only its own data');

            if ($i === 100) {
                $baseline = $this->collectedMemory();
            }
        }

        self::assertLessThan(self::MEMORY_MARGIN, $this->collectedMemory() - $baseline, 'memory stays flat over many calls');
    }

    private function deadlock(): DeadlockException
    {
        $driver = new class('Deadlock', 1213) extends Exception implements \Doctrine\DBAL\Driver\Exception {
            public function getSQLState(): string
            {
                return '40001';
            }
        };

        return new DeadlockException($driver, null);
    }

    private function collectedMemory(): int
    {
        gc_collect_cycles();

        return memory_get_usage();
    }
}
