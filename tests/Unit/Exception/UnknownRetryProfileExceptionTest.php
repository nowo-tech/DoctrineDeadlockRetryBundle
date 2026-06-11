<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\Exception;

use Nowo\DoctrineDeadlockRetryBundle\Exception\UnknownRetryProfileException;
use PHPUnit\Framework\TestCase;

final class UnknownRetryProfileExceptionTest extends TestCase
{
    public function testForNameListsAvailableProfiles(): void
    {
        $exception = UnknownRetryProfileException::forName('missing', ['default', 'batch']);

        self::assertStringContainsString('missing', $exception->getMessage());
        self::assertStringContainsString('default, batch', $exception->getMessage());
    }

    public function testForNameWhenNoProfilesConfigured(): void
    {
        $exception = UnknownRetryProfileException::forName('x', []);

        self::assertStringContainsString('(none)', $exception->getMessage());
    }
}
