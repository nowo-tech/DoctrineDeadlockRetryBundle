<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\DependencyInjection;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\OptimisticLockException;
use InvalidArgumentException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\Contract\DeadlockRetryInterface;
use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\TransientFailureExceptionListener;
use Nowo\DoctrineDeadlockRetryBundle\EventListener\WorkerGuardSubscriber;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use Nowo\DoctrineDeadlockRetryBundle\Transaction\DeadlockRetryTransactionRunner;
use Nowo\DoctrineDeadlockRetryBundle\Worker\ConnectionGuard;
use Nowo\TransactionContract\TransactionRunnerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoDoctrineDeadlockRetryExtensionTest extends TestCase
{
    private NowoDoctrineDeadlockRetryExtension $extension;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new NowoDoctrineDeadlockRetryExtension();
        $this->container = new ContainerBuilder();
    }

    public function testGetAlias(): void
    {
        $this->assertSame('nowo_doctrine_deadlock_retry', $this->extension->getAlias());
    }

    public function testLoadWiresOptionalManagerRegistry(): void
    {
        $this->extension->load([], $this->container);

        $registry = $this->container->getDefinition(DeadlockRetryService::class)->getArgument('$managerRegistry');

        $this->assertInstanceOf(Reference::class, $registry);
        $this->assertSame('doctrine', (string) $registry);
        $this->assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $registry->getInvalidBehavior());
    }

    public function testLoadUsesDefaultEntityManagerWhenNoneConfigured(): void
    {
        $this->extension->load([], $this->container);

        $arguments = $this->container->getDefinition(DeadlockRetryService::class)->getArguments();

        $this->assertArrayNotHasKey('$entityManager', $arguments, 'Autowired EntityManagerInterface = default manager');
        $this->assertArrayNotHasKey('$entityManagerName', $arguments);
    }

    public function testLoadWiresConfiguredEntityManager(): void
    {
        $this->extension->load([['entity_manager' => 'orders']], $this->container);

        $definition    = $this->container->getDefinition(DeadlockRetryService::class);
        $entityManager = $definition->getArgument('$entityManager');

        $this->assertInstanceOf(Reference::class, $entityManager);
        $this->assertSame('doctrine.orm.orders_entity_manager', (string) $entityManager);
        $this->assertSame('orders', $definition->getArgument('$entityManagerName'));
    }

    public function testLoadRegistersDeadlockRetryServiceWithProfiles(): void
    {
        $this->extension->load([
            [
                'default_profile' => 'fast',
                'profiles'        => [
                    'fast' => [
                        'max_retries' => 1,
                        'sleep_ms'    => 50,
                    ],
                ],
            ],
        ], $this->container);

        $this->assertTrue($this->container->hasDefinition(DeadlockRetryService::class));

        $definition = $this->container->getDefinition(DeadlockRetryService::class);
        /** @var array<string, Reference> $profileReferences */
        $profileReferences = $definition->getArgument('$profiles');

        $this->assertArrayHasKey('fast', $profileReferences);
        $this->assertInstanceOf(Reference::class, $profileReferences['fast']);
        $this->assertSame('nowo_doctrine_deadlock_retry.profile.fast', (string) $profileReferences['fast']);

        $profileDefinition = $this->container->getDefinition('nowo_doctrine_deadlock_retry.profile.fast');
        $this->assertSame(RetryProfile::class, $profileDefinition->getClass());
        $this->assertSame(1, $profileDefinition->getArgument(1));
        $this->assertSame(50, $profileDefinition->getArgument(2));
        $this->assertSame('fast', $definition->getArgument('$defaultProfile'));
    }

    public function testProfileBackoffOptionsAndTheSleepMsAlias(): void
    {
        $this->extension->load([
            [
                'profiles' => [
                    'default' => ['backoff_base_ms' => 20, 'backoff_max_ms' => 10, 'jitter' => 'none', 'retry_lock_wait_timeout' => false, 'rollback_on_deadlock' => false],
                    'legacy'  => ['sleep_ms' => 70],
                    'plain'   => [],
                ],
            ],
        ], $this->container);

        $this->assertSame(
            ['default', 3, 20, false, 20, false, false],
            $this->container->getDefinition('nowo_doctrine_deadlock_retry.profile.default')->getArguments(),
            'The cap is raised to the base.',
        );
        $this->assertSame(['legacy', 3, 70, true, 2000, true, true], $this->container->getDefinition('nowo_doctrine_deadlock_retry.profile.legacy')->getArguments());
        $this->assertSame(100, $this->container->getDefinition('nowo_doctrine_deadlock_retry.profile.plain')->getArgument(2));
    }

    public function testConflictingSleepMsAndBackoffBaseAreRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('sleep_ms is an alias of backoff_base_ms');

        $this->extension->load([['profiles' => ['default' => ['sleep_ms' => 10, 'backoff_base_ms' => 20]]]], $this->container);
    }

    public function testTransactionRunnerAdapterIsRegisteredAndAliased(): void
    {
        $this->extension->load([['retry_after_seconds' => 3, 'transaction_runner' => ['profile' => 'default']]], $this->container);

        $runner = $this->container->getDefinition(DeadlockRetryTransactionRunner::class);
        $this->assertSame(3, $runner->getArgument('$retryAfterSeconds'));
        $this->assertSame('default', $runner->getArgument('$profile'));
        $this->assertSame(DeadlockRetryTransactionRunner::class, (string) $this->container->getAlias(TransactionRunnerInterface::class));
    }

    public function testWorkerGuardAndHttpMappingAreOffByDefault(): void
    {
        $this->extension->load([], $this->container);

        $this->assertFalse($this->container->hasDefinition(ConnectionGuard::class));
        $this->assertFalse($this->container->hasDefinition(WorkerGuardSubscriber::class));
        $this->assertFalse($this->container->hasDefinition(TransientFailureExceptionListener::class));
    }

    public function testWorkerGuardIsRegisteredWhenEnabled(): void
    {
        $this->extension->load([['worker_guard' => ['connections' => ['default'], 'reset_closed_managers' => false]]], $this->container);

        $guard = $this->container->getDefinition(ConnectionGuard::class);
        $this->assertSame('doctrine', (string) $guard->getArgument('$registry'));
        $this->assertSame(['default'], $guard->getArgument('$connections'));
        $this->assertFalse($guard->getArgument('$resetClosedManagers'));
        $this->assertFalse($guard->isPublic());
        $this->assertTrue($this->container->getDefinition(WorkerGuardSubscriber::class)->hasTag('kernel.event_subscriber'));
    }

    public function testHttpMappingIsRegisteredWhenEnabled(): void
    {
        $this->extension->load([[
            'retry_after_seconds' => 5,
            'http_mapping'        => ['paths' => ['/api'], 'routes' => ['api_'], 'format' => 'problem_json'],
        ]], $this->container);

        $listener = $this->container->getDefinition(TransientFailureExceptionListener::class);
        $this->assertTrue($listener->hasTag('kernel.event_subscriber'));
        $this->assertSame(5, $listener->getArgument('$retryAfterSeconds'));
        $this->assertSame(['/api'], $listener->getArgument('$pathPrefixes'));
        $this->assertSame(['api_'], $listener->getArgument('$routePrefixes'));
        $this->assertSame([OptimisticLockException::class, UniqueConstraintViolationException::class], $listener->getArgument('$conflictExceptions'));
        $this->assertSame('problem_json', $listener->getArgument('$format'));
    }

    public function testLoadFailsWhenDefaultProfileIsMissing(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->extension->load([
            [
                'default_profile' => 'unknown',
                'profiles'        => [
                    'default' => ['max_retries' => 1, 'sleep_ms' => 10],
                ],
            ],
        ], $this->container);
    }

    public function testServiceInterfaceIsAliasedAndHostCanOverrideIt(): void
    {
        $this->extension->load([], $this->container);

        $this->assertTrue($this->container->hasAlias(DeadlockRetryInterface::class));
        $this->assertSame(DeadlockRetryService::class, (string) $this->container->getAlias(DeadlockRetryInterface::class));
        $this->assertContains(DeadlockRetryInterface::class, class_implements(DeadlockRetryService::class));

        $this->container->register('app.deadlock_retry', stdClass::class);
        $this->container->setAlias(DeadlockRetryInterface::class, 'app.deadlock_retry');
        $this->assertSame('app.deadlock_retry', (string) $this->container->getAlias(DeadlockRetryInterface::class));
        $this->assertTrue($this->container->hasDefinition(DeadlockRetryService::class));
    }
}
