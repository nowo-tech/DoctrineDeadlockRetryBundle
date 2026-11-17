<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/vendor',
        // Opt-in listeners are registered by the extension only when enabled, not by autoconfigured attributes.
        EventSubscriberToAttributeRector::class => [
            __DIR__ . '/src/EventListener',
        ],
        // Listener signature kept: Symfony passes the event.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/src/EventListener/WorkerGuardSubscriber.php',
        ],
    ])
    // Machine-wide cap: at most 2 worker processes; generous timeout for slow (WSL2 / CI) hosts.
    ->withParallel(300, 2, 10);
