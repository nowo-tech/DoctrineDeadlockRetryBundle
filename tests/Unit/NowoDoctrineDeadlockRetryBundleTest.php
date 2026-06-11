<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit;

use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\NowoDoctrineDeadlockRetryExtension;
use Nowo\DoctrineDeadlockRetryBundle\NowoDoctrineDeadlockRetryBundle;
use PHPUnit\Framework\TestCase;

final class NowoDoctrineDeadlockRetryBundleTest extends TestCase
{
    public function testGetContainerExtensionReturnsExtension(): void
    {
        $bundle = new NowoDoctrineDeadlockRetryBundle();

        self::assertInstanceOf(NowoDoctrineDeadlockRetryExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
    }
}
