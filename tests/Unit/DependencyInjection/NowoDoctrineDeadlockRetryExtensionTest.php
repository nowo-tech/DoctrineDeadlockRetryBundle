<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\DependencyInjection;

use InvalidArgumentException;
use Nowo\DoctrineDeadlockRetryBundle\Config\RetryProfile;
use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
use Nowo\DoctrineDeadlockRetryBundle\Service\DeadlockRetryService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
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
}
