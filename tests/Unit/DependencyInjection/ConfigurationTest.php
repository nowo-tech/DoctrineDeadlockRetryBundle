<?php

declare(strict_types=1);

namespace Nowo\DoctrineDeadlockRetryBundle\Tests\Unit\DependencyInjection;

use Nowo\DoctrineDeadlockRetryBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Processor;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class ConfigurationTest extends TestCase
{
    public function testDefaultConfiguration(): void
    {
        $config = $this->processConfiguration([[]]);

        $this->assertSame('default', $config['default_profile']);
        $this->assertArrayHasKey('default', $config['profiles']);
        $this->assertSame(3, $config['profiles']['default']['max_retries']);
        $this->assertSame(100, $config['profiles']['default']['sleep_ms']);
        $this->assertTrue($config['profiles']['default']['rollback_on_deadlock']);
    }

    public function testCustomProfiles(): void
    {
        $config = $this->processConfiguration([
            [
                'default_profile' => 'heavy',
                'profiles'        => [
                    'heavy' => [
                        'max_retries'          => 10,
                        'sleep_ms'             => 250,
                        'rollback_on_deadlock' => false,
                    ],
                ],
            ],
        ]);

        $this->assertSame('heavy', $config['default_profile']);
        $this->assertSame(10, $config['profiles']['heavy']['max_retries']);
        $this->assertSame(250, $config['profiles']['heavy']['sleep_ms']);
        $this->assertFalse($config['profiles']['heavy']['rollback_on_deadlock']);
    }

    /**
     * @param array<int, array<string, mixed>> $configs
     *
     * @return array<string, mixed>
     */
    private function processConfiguration(array $configs): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), $configs);
    }
}
